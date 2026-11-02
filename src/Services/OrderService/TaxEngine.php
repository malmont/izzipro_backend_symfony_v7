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
 * Résultat : taxes [{ label, rate, base, amount, total, category?, jurisdiction? }], total (cents), status, calculationId (Stripe), provider.
 *
 * Catégories fiscales (F18 partie 2a, 01/11/2026, fournisseur « table ») : chaque ligne porte `taxCategory` (TaxCategory, vide =
 * standard ; `taxable: false` ≡ exempt) et chaque taxe une catégorie facultative. Assiette de la taxe i = Σ nets des lignes auxquelles
 * elle s'applique (+ la livraison pour une taxe sans catégorie ou standard) ; amount_i = round(base_i × taux_i). Sans aucune
 * catégorie (tous les sites d'avant), toutes les taxes ont la même assiette qu'avant et le total garde la formule d'avant,
 * round(Σ montants non arrondis) ; dès qu'une catégorie entre en jeu, total = Σ montants arrondis (Σ taxes[].amount = total).
 * Stripe Tax ignore les catégories (codes fiscaux bien / service / livraison inchangés).
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
    /** Article libre non taxable (exonéré / détaxé, F7) : code Stripe « non taxable » */
    public const TAX_CODE_NONTAXABLE = 'txcd_00000000';
    private const CACHE_TTL = 600;

    private const PROVINCES = [
        'QUEBEC' => 'QC', 'QUÉBEC' => 'QC', 'ONTARIO' => 'ON', 'COLOMBIE-BRITANNIQUE' => 'BC', 'BRITISH COLUMBIA' => 'BC', 'ALBERTA' => 'AB',
        'SASKATCHEWAN' => 'SK', 'MANITOBA' => 'MB', 'NOUVEAU-BRUNSWICK' => 'NB', 'NEW BRUNSWICK' => 'NB', 'NOUVELLE-ÉCOSSE' => 'NS', 'NOVA SCOTIA' => 'NS',
        'TERRE-NEUVE-ET-LABRADOR' => 'NL', 'NEWFOUNDLAND AND LABRADOR' => 'NL', 'ÎLE-DU-PRINCE-ÉDOUARD' => 'PE', 'PRINCE EDWARD ISLAND' => 'PE',
        'YUKON' => 'YT', 'TERRITOIRES DU NORD-OUEST' => 'NT', 'NORTHWEST TERRITORIES' => 'NT', 'NUNAVUT' => 'NU',
    ];

    /** États américains en toutes lettres → code (F18 partie 2a) ; un code à deux lettres passe tel quel */
    private const US_STATES = [
        'ALABAMA' => 'AL', 'ALASKA' => 'AK', 'ARIZONA' => 'AZ', 'ARKANSAS' => 'AR', 'CALIFORNIA' => 'CA', 'CALIFORNIE' => 'CA', 'COLORADO' => 'CO', 'CONNECTICUT' => 'CT',
        'DELAWARE' => 'DE', 'DISTRICT OF COLUMBIA' => 'DC', 'FLORIDA' => 'FL', 'FLORIDE' => 'FL', 'GEORGIA' => 'GA', 'GÉORGIE' => 'GA', 'HAWAII' => 'HI', 'IDAHO' => 'ID',
        'ILLINOIS' => 'IL', 'INDIANA' => 'IN', 'IOWA' => 'IA', 'KANSAS' => 'KS', 'KENTUCKY' => 'KY', 'LOUISIANA' => 'LA', 'LOUISIANE' => 'LA', 'MAINE' => 'ME',
        'MARYLAND' => 'MD', 'MASSACHUSETTS' => 'MA', 'MICHIGAN' => 'MI', 'MINNESOTA' => 'MN', 'MISSISSIPPI' => 'MS', 'MISSOURI' => 'MO', 'MONTANA' => 'MT',
        'NEBRASKA' => 'NE', 'NEVADA' => 'NV', 'NEW HAMPSHIRE' => 'NH', 'NEW JERSEY' => 'NJ', 'NEW MEXICO' => 'NM', 'NOUVEAU-MEXIQUE' => 'NM', 'NEW YORK' => 'NY',
        'NORTH CAROLINA' => 'NC', 'CAROLINE DU NORD' => 'NC', 'NORTH DAKOTA' => 'ND', 'DAKOTA DU NORD' => 'ND', 'OHIO' => 'OH', 'OKLAHOMA' => 'OK', 'OREGON' => 'OR',
        'PENNSYLVANIA' => 'PA', 'PENNSYLVANIE' => 'PA', 'RHODE ISLAND' => 'RI', 'SOUTH CAROLINA' => 'SC', 'CAROLINE DU SUD' => 'SC', 'SOUTH DAKOTA' => 'SD', 'DAKOTA DU SUD' => 'SD',
        'TENNESSEE' => 'TN', 'TEXAS' => 'TX', 'UTAH' => 'UT', 'VERMONT' => 'VT', 'VIRGINIA' => 'VA', 'VIRGINIE' => 'VA', 'WASHINGTON' => 'WA',
        'WEST VIRGINIA' => 'WV', 'VIRGINIE-OCCIDENTALE' => 'WV', 'WISCONSIN' => 'WI', 'WYOMING' => 'WY', 'PUERTO RICO' => 'PR',
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
     * @param list<array{amount: int, quantity: int, kind: string, reference: string, taxable?: bool, taxCategory?: ?string}> $lines lignes (montant unitaire en cents ; taxable false = exclue de l'assiette, F7 ; taxCategory : catégorie fiscale, vide = standard)
     * @return array{taxes: list<array{label: string, rate: float, base: int, amount: int, total: int, category?: ?string, jurisdiction?: string}>, total: int, status: string, provider: string, calculationId: ?string}
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
        $province = self::PROVINCES[$province] ?? ($country === 'US' ? (self::US_STATES[$province] ?? null) : null) ?? ($province !== '' ? $province : null);
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
            if (self::matchesAddress($tax->getCountry(), $tax->getProvince(), $address)) {
                $applicable[] = $tax;
            }
        }

        return $applicable;
    }

    /** Une ligne de la table (pays vide = tous ; région « Toutes » ou codes séparés par des virgules) s'applique-t-elle à l'adresse normalisée ? */
    public static function matchesAddress(?string $taxCountry, ?string $taxProvince, array $address): bool
    {
        $country = strtoupper(trim((string) $taxCountry));
        if ($country !== '' && $country !== ($address['country'] ?? null)) {
            return false;
        }
        $province = trim((string) $taxProvince);
        if ($province !== '' && !in_array(mb_strtoupper($province), ['TOUTES', 'TOUS', 'ALL', '*'], true)) {
            $codes = array_map(fn ($p) => self::PROVINCES[mb_strtoupper(trim($p))] ?? strtoupper(trim($p)), explode(',', $province));
            if (!in_array($address['province'] ?? '', $codes, true)) {
                return false;
            }
        }

        return true;
    }

    private function table(array $address, array $lines, int $shipping): array
    {
        $applicable = $this->applicableTaxes($address);
        // Catégorie de chaque ligne (taxable: false ≡ exempt) ; « legacy » = aucune catégorie nulle part : formule d'avant à l'identique
        $categories = array_map(fn ($line) => ($line['taxable'] ?? true) === false ? TaxCategory::EXEMPT : TaxCategory::of($line['taxCategory'] ?? null), $lines);
        $legacy = !array_filter($categories, fn ($c) => $c !== TaxCategory::STANDARD && $c !== TaxCategory::EXEMPT) && !array_filter($applicable, fn (Tax $t) => $t->getCategory() !== null)
            && !array_filter($lines, fn ($line) => ($line['taxCategory'] ?? null) === TaxCategory::EXEMPT && ($line['taxable'] ?? true) !== false); // produit exonéré par catégorie (l'article libre non taxable de F7 reste la formule d'avant)
        $taxes = [];
        $floatTotal = 0.0;
        $intTotal = 0;
        foreach ($applicable as $tax) {
            $base = TaxCategory::applies($tax->getCategory(), TaxCategory::STANDARD) ? $shipping : 0; // livraison : taxes sans catégorie ou standard
            foreach ($lines as $i => $line) {
                if (TaxCategory::applies($tax->getCategory(), $categories[$i])) {
                    $base += $line['amount'] * $line['quantity'];
                }
            }
            $raw = $base * (float) $tax->getRate();
            $amount = (int) round($raw);
            $floatTotal += $raw;
            $intTotal += $amount;
            $taxes[] = ['label' => (string) $tax->getName(), 'rate' => (float) $tax->getRate(), 'base' => $base, 'amount' => $amount, 'total' => $base + $amount, 'category' => $tax->getCategory(), 'taxId' => (int) $tax->getId()];
        }

        return ['taxes' => $taxes, 'total' => $legacy ? (int) round($floatTotal) : $intTotal, 'status' => $taxes ? self::STATUS_CALCULATED : self::STATUS_NO_TAX, 'provider' => self::PROVIDER_TABLE, 'calculationId' => null];
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
                'tax_code' => ($line['taxable'] ?? true) === false ? self::TAX_CODE_NONTAXABLE : ($line['kind'] === 'rental' ? self::TAX_CODE_SERVICE : self::TAX_CODE_GOODS), 'tax_behavior' => 'exclusive',
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
                    'base' => (int) ($breakdown->taxable_amount ?? 0), // assiette de la juridiction (F18 partie 2a)
                    'amount' => (int) $breakdown->amount,
                    'total' => (int) ($breakdown->taxable_amount ?? 0) + (int) $breakdown->amount,
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
