<?php

namespace App\Services\OrderService;

use App\Entity\StripeConfig;
use App\Entity\Tax;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Taxes d'un panier ou d'une commande selon la région du client (09/10/2026). Deux fournisseurs, choisis par le réglage
 * de la boutique `commerce.taxProvider` :
 *
 * - « table » (défaut et repli) : la table `tax` du site, une taxe s'appliquant si son pays (`country`, vide = tous) et
 *   sa région (`province` : « Toutes », ou codes séparés par des virgules : « QC », « ON,NB,NL,PE,NS ») correspondent
 *   à l'adresse de livraison ; les noms de provinces canadiennes sont ramenés à leur code ;
 * - « stripe » : Stripe Tax (Tax Calculations) sur le compte connecté du site, une ligne par article avec son code
 *   fiscal (bien physique, service pour une location) et la livraison ; les montants reviennent par juridiction. Le
 *   calcul est gardé quelques minutes pour un même panier et une même adresse ; à la commande, la transaction fiscale
 *   est enregistrée chez Stripe (rapports de déclaration). Stripe indisponible ou non activé sur le compte : repli sur
 *   la table, signalé par `status`.
 *
 * Sans adresse : aucune taxe et `status: address_required` (le frontend affiche « taxes calculées au paiement »).
 * Résultat : taxes [{ label, rate, amount, jurisdiction? }], total (cents), status, calculationId (Stripe), provider.
 */
final class TaxEngine
{
    public const PROVIDER_TABLE = 'table';
    public const PROVIDER_STRIPE = 'stripe';
    public const STATUS_CALCULATED = 'calculated';
    public const STATUS_ADDRESS_REQUIRED = 'address_required';
    public const STATUS_NO_TAX = 'no_tax';
    public const STATUS_FALLBACK = 'fallback_table';

    /** Codes fiscaux Stripe : bien physique, service (location), livraison */
    public const TAX_CODE_GOODS = 'txcd_99999999';
    public const TAX_CODE_SERVICE = 'txcd_20030000';
    public const TAX_CODE_SHIPPING = 'txcd_92010001';
    private const CACHE_TTL = 600;

    private const PROVINCES = [
        'QUEBEC' => 'QC', 'QUÉBEC' => 'QC', 'ONTARIO' => 'ON', 'COLOMBIE-BRITANNIQUE' => 'BC', 'BRITISH COLUMBIA' => 'BC', 'ALBERTA' => 'AB',
        'SASKATCHEWAN' => 'SK', 'MANITOBA' => 'MB', 'NOUVEAU-BRUNSWICK' => 'NB', 'NEW BRUNSWICK' => 'NB', 'NOUVELLE-ÉCOSSE' => 'NS', 'NOVA SCOTIA' => 'NS',
        'TERRE-NEUVE-ET-LABRADOR' => 'NL', 'NEWFOUNDLAND AND LABRADOR' => 'NL', 'ÎLE-DU-PRINCE-ÉDOUARD' => 'PE', 'PRINCE EDWARD ISLAND' => 'PE',
        'YUKON' => 'YT', 'TERRITOIRES DU NORD-OUEST' => 'NT', 'NORTHWEST TERRITORIES' => 'NT', 'NUNAVUT' => 'NU',
    ];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly BoutiqueSettingsService $settings,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::STRIPE_SECRET_KEY)%')]
        private readonly ?string $stripeSecretKey
    ) {
    }

    /**
     * @param array{country?: ?string, province?: ?string, city?: ?string, postalCode?: ?string}|null $address adresse normalisée (normalizeAddress)
     * @param list<array{amount: int, quantity: int, kind: string, reference: string}> $lines lignes (montant unitaire en cents)
     * @return array{taxes: list<array{label: string, rate: float, amount: int, jurisdiction?: string}>, total: int, status: string, provider: string, calculationId: ?string}
     */
    public function compute(?array $address, array $lines, int $shipping, string $currency): array
    {
        $provider = $this->provider();
        if ($address === null || ($address['country'] ?? null) === null) {
            return ['taxes' => [], 'total' => 0, 'status' => self::STATUS_ADDRESS_REQUIRED, 'provider' => $provider, 'calculationId' => null];
        }
        if ($provider === self::PROVIDER_STRIPE) {
            try {
                return $this->stripe($address, $lines, $shipping, $currency);
            } catch (\Throwable $e) {
                $this->logger->warning('[TaxEngine] Stripe Tax indisponible, repli sur la table : ' . $e->getMessage());
                $result = $this->table($address, $lines, $shipping);
                $result['status'] = self::STATUS_FALLBACK;

                return $result;
            }
        }

        return $this->table($address, $lines, $shipping);
    }

    /** Fournisseur réglé pour le site (commerce.taxProvider), table par défaut */
    public function provider(): string
    {
        $configured = $this->settings->commerceSetting('taxProvider');

        return $configured === self::PROVIDER_STRIPE ? self::PROVIDER_STRIPE : self::PROVIDER_TABLE;
    }

    /**
     * Adresse normalisée : pays ISO à 2 lettres, province en code (QC, ON…), ou null sans pays.
     *
     * @param array<string, mixed>|null $raw { country, province|state, city, postalCode|zip|codepostal }
     * @return array{country: string, province: ?string, city: ?string, postalCode: ?string}|null
     */
    public static function normalizeAddress(?array $raw): ?array
    {
        $country = mb_strtoupper(trim((string) ($raw['country'] ?? '')));
        $country = match ($country) { 'CANADA' => 'CA', 'FRANCE' => 'FR', 'ÉTATS-UNIS', 'ETATS-UNIS', 'USA', 'UNITED STATES' => 'US', default => $country };
        $province = mb_strtoupper(trim((string) ($raw['province'] ?? $raw['state'] ?? '')));
        $province = self::PROVINCES[$province] ?? ($province !== '' ? $province : null);
        $postalCode = isset($raw['postalCode']) || isset($raw['zip']) || isset($raw['codepostal']) ? (string) ($raw['postalCode'] ?? $raw['zip'] ?? $raw['codepostal']) : null;
        // Pays absent (09/10/2026 : commandes invité sans pays, donc sans taxe) : une province canadienne (code ou nom)
        // ou un code postal canadien (A1A 1A1) suffit à reconnaître le Canada
        if ($country === '' && (in_array($province, self::PROVINCES, true) || preg_match('/^[ABCEGHJ-NPRSTVXY]\d[A-Z] ?\d[A-Z]\d$/i', trim((string) $postalCode)))) {
            $country = 'CA';
        }
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            return null;
        }

        return [
            'country' => $country, 'province' => $province,
            'city' => isset($raw['city']) && $raw['city'] !== '' ? (string) $raw['city'] : null,
            'postalCode' => $postalCode,
        ];
    }

    /** @return list<Tax> taxes de la table qui s'appliquent à l'adresse */
    public function applicableTaxes(array $address): array
    {
        $applicable = [];
        foreach ($this->emProvider->getEntityManager()->getRepository(Tax::class)->findBy([], ['id' => 'ASC']) as $tax) {
            $country = strtoupper(trim((string) $tax->getCountry()));
            if ($country !== '' && $country !== $address['country']) {
                continue;
            }
            $province = trim((string) $tax->getProvince());
            if ($province !== '' && !in_array(mb_strtoupper($province), ['TOUTES', 'TOUS', 'ALL', '*'], true)) {
                $codes = array_map(fn ($p) => self::PROVINCES[mb_strtoupper(trim($p))] ?? strtoupper(trim($p)), explode(',', $province));
                if (!in_array($address['province'] ?? '', $codes, true)) {
                    continue;
                }
            }
            $applicable[] = $tax;
        }

        return $applicable;
    }

    private function table(array $address, array $lines, int $shipping): array
    {
        $taxable = $shipping;
        foreach ($lines as $line) {
            $taxable += $line['amount'] * $line['quantity'];
        }
        $taxes = [];
        $total = 0.0;
        foreach ($this->applicableTaxes($address) as $tax) {
            $amount = $taxable * (float) $tax->getRate();
            $total += $amount;
            $taxes[] = ['label' => (string) $tax->getName(), 'rate' => (float) $tax->getRate(), 'amount' => (int) round($amount), 'taxId' => (int) $tax->getId()];
        }

        return ['taxes' => $taxes, 'total' => (int) round($total), 'status' => $taxes ? self::STATUS_CALCULATED : self::STATUS_NO_TAX, 'provider' => self::PROVIDER_TABLE, 'calculationId' => null];
    }

    private function stripe(array $address, array $lines, int $shipping, string $currency): array
    {
        $account = $this->connectedAccount();
        $params = [
            'currency' => strtolower($currency),
            'customer_details' => [
                'address' => array_filter(['country' => $address['country'], 'state' => $address['province'], 'city' => $address['city'], 'postal_code' => $address['postalCode']], fn ($v) => $v !== null && $v !== ''),
                'address_source' => 'shipping',
            ],
            'line_items' => array_map(fn ($line) => [
                'amount' => $line['amount'] * $line['quantity'], 'quantity' => $line['quantity'], 'reference' => $line['reference'],
                'tax_code' => $line['kind'] === 'rental' ? self::TAX_CODE_SERVICE : self::TAX_CODE_GOODS, 'tax_behavior' => 'exclusive',
            ], array_values($lines)),
            'expand' => ['line_items'],
        ];
        if ($shipping > 0) {
            $params['shipping_cost'] = ['amount' => $shipping, 'tax_code' => self::TAX_CODE_SHIPPING, 'tax_behavior' => 'exclusive'];
        }
        $key = 'tax_calc_' . hash('sha256', ($account ?? 'platform') . json_encode($params));

        return $this->cache->get($key, function (ItemInterface $item) use ($params, $account) {
            $item->expiresAfter(self::CACHE_TTL);
            $client = new StripeClient((string) $this->stripeSecretKey);
            $calculation = $client->tax->calculations->create($params, $account ? ['stripe_account' => $account] : []);
            $taxes = [];
            foreach ($calculation->tax_breakdown ?? [] as $breakdown) {
                $details = $breakdown->tax_rate_details;
                $taxes[] = [
                    'label' => self::stripeLabel($details->display_name ?? null, $details->tax_type ?? null),
                    'rate' => isset($details->percentage_decimal) ? (float) $details->percentage_decimal / 100 : 0.0,
                    'amount' => (int) $breakdown->amount,
                    'jurisdiction' => trim(($details->country ?? '') . ' ' . ($details->state ?? '')),
                ];
            }

            return ['taxes' => $taxes, 'total' => (int) $calculation->tax_amount_exclusive, 'status' => $taxes ? self::STATUS_CALCULATED : self::STATUS_NO_TAX,
                'provider' => self::PROVIDER_STRIPE, 'calculationId' => (string) $calculation->id];
        });
    }

    /** Libellé d'une taxe Stripe : nom affiché, sinon type de taxe en français (gst → TPS, qst → TVQ, hst → TVH, pst → TVP, vat → TVA) */
    private static function stripeLabel(?string $displayName, ?string $taxType): string
    {
        if (is_string($displayName) && trim($displayName) !== '') {
            return trim($displayName);
        }
        $type = strtolower((string) $taxType);

        return match ($type) { 'gst' => 'TPS', 'qst' => 'TVQ', 'hst' => 'TVH', 'pst' => 'TVP', 'rst' => 'TVD', 'vat' => 'TVA', 'sales_tax' => 'Taxe de vente', '' => 'Taxe', default => strtoupper($type) };
    }

    /**
     * Enregistre chez Stripe la transaction fiscale d'une commande payée (rapports de déclaration) ; null si le site
     * n'est pas sur Stripe Tax ou si le calcul n'est plus disponible.
     */
    public function recordTransaction(string $calculationId, string $reference): ?string
    {
        try {
            $account = $this->connectedAccount();
            $client = new StripeClient((string) $this->stripeSecretKey);
            $transaction = $client->tax->transactions->createFromCalculation(['calculation' => $calculationId, 'reference' => $reference], $account ? ['stripe_account' => $account] : []);

            return (string) $transaction->id;
        } catch (\Throwable $e) {
            $this->logger->error('[TaxEngine] Transaction fiscale Stripe non enregistrée : ' . $e->getMessage(), ['reference' => $reference]);

            return null;
        }
    }

    private function connectedAccount(): ?string
    {
        $config = $this->emProvider->getEntityManager()->getRepository(StripeConfig::class)->findOneBy(['isActive' => true]);

        return $config?->getAccountId();
    }
}
