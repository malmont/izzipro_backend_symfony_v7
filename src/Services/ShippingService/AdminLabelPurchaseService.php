<?php

namespace App\Services\ShippingService;

use App\Entity\Order;
use App\Entity\Parcel;
use App\Entity\ShippingLabel;
use App\Entity\ShippingOrder;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\OrderService\OrderShipmentService;
use App\Services\Platform\InsufficientShippingBalanceException;
use App\Services\Platform\PlatformShippingService;
use App\Services\Platform\ShippingBalanceRepository;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * « Acheter l'étiquette » depuis la commande (lot 2 Livraison, 07/11/2026). Devis : colis de la commande (poids effectifs des produits,
 * ProductShippingResolver), tarifs EasyPost filtrés sur la devise du site, frais de service de la plateforme en mode `platform`,
 * solde prépayé, bloqueurs. Achat : tarif exact (jamais un autre), débit du solde avant l'achat (remboursé si l'achat échoue), lignes
 * ShippingOrder / Parcel / ShippingLabel, frais enregistrés côté plateforme, puis la commande passe « En cours de livraison » avec le
 * suivi et le courriel au client (OrderShipmentService::ship). Envoi international : déclaration douanière à partir des lignes.
 */
class AdminLabelPurchaseService
{
    public const MESSAGES = [
        'SHIPPING_MODE_NONE' => 'Le site est en « estimation seule » : choisissez « Mon compte EasyPost » ou « Par la plateforme » dans la page Livraison pour acheter des étiquettes.',
        'NO_SHIPPING_ADDRESS' => 'Cette commande n\'a pas d\'adresse de livraison.',
        'NO_SENDER_ADDRESS' => 'Adresse d\'expédition de l\'entreprise incomplète (fiche Entreprise : adresse, ville, code postal, pays, téléphone).',
        'NO_ITEMS' => 'Aucun article à expédier dans cette commande.',
        'EASYPOST_NOT_CONFIGURED' => 'Aucune clé EasyPost pour l\'environnement choisi (page Livraison).',
        'PLATFORM_NOT_READY' => 'Étiquettes par la plateforme indisponibles : clé ou comptes transporteurs de la plateforme absents.',
        'NO_RATES' => 'Aucun tarif proposé pour cet envoi (adresse, dimensions ou transporteurs).',
        'RATE_UNAVAILABLE' => 'Ce tarif n\'est plus proposé : choisissez-en un autre.',
        'BALANCE_INSUFFICIENT' => 'Solde d\'expédition insuffisant.',
    ];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly ShippingService $shipping,
        private readonly ShippingCarrierGatewayInterface $gateway,
        private readonly ShipmentAddressBuilder $addresses,
        private readonly ProductShippingResolver $resolver,
        private readonly BoutiqueSettingsService $settings,
        private readonly PlatformShippingService $platform,
        private readonly ShippingBalanceRepository $balances,
        private readonly OrderShipmentService $shipments,
        private readonly TenantCurrencyProvider $currency,
        private readonly LoggerInterface $logger,
        private readonly ?\App\Services\Http\ClientSafeError $safeError = null, // lot ERREURS-1 (12/11/2026)
    ) {
    }

    /**
     * @return array{mode: string, environment: string, blocker: ?string, blockerMessage: ?string, parcels: list<array<string, mixed>>, rates: list<array<string, mixed>>, balance: ?array{balanceCents: int, currency: string}, fee: array{bp: int, fixedCents: int}, currency: string, international: bool, warnings: list<string>}
     */
    public function quote(Order $order): array
    {
        $mode = $this->settings->shippingSettings()['mode'];
        $currency = $this->currency->code();
        $out = ['mode' => $mode, 'environment' => $this->gateway->environment(), 'blocker' => null, 'blockerMessage' => null, 'parcels' => [], 'rates' => [], 'balance' => null, 'fee' => ['bp' => 0, 'fixedCents' => 0], 'currency' => $currency, 'international' => false, 'warnings' => []];
        $code = (string) $this->tenants->getCurrentTenantCode();
        if ($mode === 'platform') {
            $s = $this->platform->settings();
            $out['fee'] = ['bp' => $s['feeBp'], 'fixedCents' => $s['feeFixedCents']];
            $out['balance'] = $this->balances->balance($code);
        }
        if ($blocker = $this->shipments->shipBlocker($order)) {
            return $out + [] + ['blocker' => $blocker, 'blockerMessage' => OrderShipmentService::MESSAGES[$blocker]];
        }
        if ($mode === 'none') {
            return $this->blocked($out, 'SHIPPING_MODE_NONE');
        }
        if ($mode === 'platform' && (!$this->gateway->hasConfiguration() || $this->platform->carrierAccountIds() === [])) {
            return $this->blocked($out, 'PLATFORM_NOT_READY');
        }
        if ($mode === 'own_key' && !$this->gateway->hasConfiguration()) {
            return $this->blocked($out, 'EASYPOST_NOT_CONFIGURED');
        }
        $items = $this->items($order);
        if ($items === []) {
            return $this->blocked($out, 'NO_ITEMS');
        }
        try {
            $to = $this->to($order);
            $from = $this->addresses->buildFrom();
        } catch (\Throwable $e) {
            $delivery = str_contains($e->getMessage(), 'livraison'); // le message ne sert qu'à choisir le code : il n'est pas rendu

            return $this->blocked($out, $delivery ? 'NO_SHIPPING_ADDRESS' : 'NO_SENDER_ADDRESS');
        }
        if (trim((string) ($from['phone'] ?? '')) === '') {
            $out['warnings'][] = 'Aucun téléphone sur l\'adresse de l\'entreprise : certains transporteurs le refusent.';
        }
        $out['international'] = strtoupper((string) $to['country']) !== strtoupper((string) $from['country']);
        try {
            $out['parcels'] = $this->shipping->getParcelSummaries($items);
            $raw = $this->shipping->getRates($items, $to, $from, $this->carrierAccounts($mode));
        } catch (ItemTooLargeForPackagingException $e) {
            return $this->blocked($out, 'NO_RATES', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('[Étiquette] devis impossible : ' . $e->getMessage(), ['orderId' => $order->getId()]);

            // lot ERREURS-1 : le détail d'une panne imprévue (transporteur, réseau) reste au journal ; seul un refus rédigé par nous est cité
            return $this->blocked($out, 'NO_RATES', $this->safeError->isIntended($e) ? $e->getMessage() : null);
        }
        $parcels = max(1, count($out['parcels']));
        foreach ($this->shipping->aggregateRates($raw) as $r) {
            if (strtoupper($r->currency) !== strtoupper($currency)) {
                $out['warnings'][] = sprintf('Tarif %s %s ignoré : devise %s, le site est en %s.', $r->carrier, $r->service, $r->currency, $currency);
                continue;
            }
            $cost = (int) round($r->price * 100);
            $fee = $mode === 'platform' ? $this->platform->fee($cost, $parcels) : 0;
            $out['rates'][] = ['carrier' => $r->carrier, 'service' => $r->service, 'carrierAccountId' => $r->carrierAccountId, 'costCents' => $cost, 'feeCents' => $fee, 'totalCents' => $cost + $fee, 'currency' => $r->currency, 'estimatedDays' => $r->estimatedDays];
        }
        usort($out['rates'], fn ($a, $b) => $a['totalCents'] <=> $b['totalCents']);
        if ($out['rates'] === []) {
            return $this->blocked($out, 'NO_RATES');
        }

        return $out;
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function buy(Order $order, string $carrierAccountId, string $service, bool $notify, ?string $by, string $domain): array
    {
        $quote = $this->quote($order);
        if ($quote['blocker'] !== null) {
            return ['status' => 409, 'body' => ['error' => $quote['blocker'], 'message' => $quote['blockerMessage']]];
        }
        $chosen = null;
        foreach ($quote['rates'] as $r) {
            if ($r['carrierAccountId'] === $carrierAccountId && $r['service'] === $service) {
                $chosen = $r;
            }
        }
        if ($chosen === null) {
            return ['status' => 409, 'body' => ['error' => 'RATE_UNAVAILABLE', 'message' => self::MESSAGES['RATE_UNAVAILABLE']]];
        }
        $code = (string) $this->tenants->getCurrentTenantCode();
        $reference = 'order:' . $order->getId();
        $debited = false;
        if ($quote['mode'] === 'platform') {
            try {
                $this->balances->debit($code, $chosen['totalCents'], $reference, sprintf('Étiquette %s %s, commande %s', $chosen['carrier'], $chosen['service'], $order->getReference()), $by);
                $debited = true;
            } catch (InsufficientShippingBalanceException $e) {
                return ['status' => 409, 'body' => ['error' => 'BALANCE_INSUFFICIENT', 'message' => $e->getMessage(), 'balanceCents' => $e->balanceCents, 'neededCents' => $e->neededCents]];
            }
        }
        $items = $this->items($order);
        $to = $this->to($order);
        $from = $this->addresses->buildFrom();
        $customs = $quote['international'] ? $this->customs($order, $from) : null;
        try {
            $labels = $this->shipping->purchase($items, $to, $from, $carrierAccountId, $service, $customs);
        } catch (\Throwable $e) {
            if ($debited) {
                // lot ERREURS-1 : ni le relevé du solde ni la réponse ne citent le message technique du transporteur (il est au journal)
                $detail = $this->safeError->isIntended($e) ? ' : ' . mb_substr($e->getMessage(), 0, 200) : '';
                $this->balances->credit($code, $chosen['totalCents'], 'refund', $reference, 'Achat d\'étiquette échoué' . $detail, $by);
            }
            $this->logger->error('[Étiquette] achat échoué : ' . $e->getMessage(), ['orderId' => $order->getId(), 'exception' => $e]);
            $code2 = $e instanceof RateUnavailableException ? 'RATE_UNAVAILABLE' : 'PURCHASE_FAILED';

            return ['status' => 409, 'body' => ['error' => $code2, 'message' => $code2 === 'RATE_UNAVAILABLE' ? self::MESSAGES['RATE_UNAVAILABLE']
                : ($this->safeError->isIntended($e) ? 'L\'achat a échoué chez EasyPost : ' . $e->getMessage() : 'L\'achat a échoué chez le transporteur. Rien n\'a été débité. Réessayez dans quelques instants.')]];
        }
        $em = $this->emProvider->getEntityManager();
        $shippingOrder = (new ShippingOrder())->setOdershipping($order)->setCarrierAccountId($carrierAccountId)->setService($service)->setCreatedAt(new \DateTimeImmutable())
            ->setTotalPrice(array_sum(array_map(fn ($l) => (float) $l['price'], $labels)));
        $em->persist($shippingOrder);
        $tracking = [];
        foreach ($labels as $i => $raw) {
            $dto = $quote['parcels'][$i] ?? ['weight' => 0, 'length' => 0, 'width' => 0, 'height' => 0];
            $parcel = (new Parcel())->setShippingOrder($shippingOrder)->setIndex($i + 1)->setWeight($dto['weight'])->setLength($dto['length'])->setWidth($dto['width'])->setHeight($dto['height']);
            $em->persist($parcel);
            $label = (new ShippingLabel())->setParcel($parcel)->setLabelUrl($raw['label_url'])->setTrackingCode($raw['tracking_code'])->setCreatedAt(new \DateTimeImmutable());
            $em->persist($label);
            $tracking[] = (string) $raw['tracking_code'];
        }
        $em->flush();
        $actualCost = (int) round(array_sum(array_map(fn ($l) => (float) $l['price'], $labels)) * 100);
        if ($quote['mode'] === 'platform') {
            $this->balances->recordCharge($code, (int) $order->getId(), $order->getReference(), $chosen['carrier'], $chosen['service'], count($labels), $actualCost, $chosen['feeCents'], $chosen['currency'], $tracking, $quote['environment'], $by);
        }
        $carrierName = $chosen['carrier'] . ' (' . $chosen['service'] . ')';
        $shipped = $this->shipments->ship($order, $carrierName, implode(', ', $tracking), null, $notify, $domain);

        return ['status' => 200, 'body' => ['bought' => true, 'labels' => array_map(fn ($l) => ['labelUrl' => $l['label_url'], 'trackingCode' => $l['tracking_code']], $labels), 'costCents' => $actualCost, 'feeCents' => $chosen['feeCents'], 'totalCents' => $actualCost + $chosen['feeCents'], 'currency' => $chosen['currency'], 'notified' => $shipped['notified']]];
    }

    /** @return list<string> comptes transporteurs à interroger : ceux de la plateforme, ou ceux des transporteurs EasyPost du site */
    private function carrierAccounts(string $mode): array
    {
        if ($mode === 'platform') {
            return $this->platform->carrierAccountIds();
        }
        $ids = [];
        foreach ($this->emProvider->getEntityManager()->getRepository(\App\Entity\Carrier::class)->findAll() as $c) {
            if ($c->getKind() === \App\Entity\Carrier::KIND_EASYPOST && trim((string) $c->getCarrierAccountId()) !== '') {
                $ids[] = trim((string) $c->getCarrierAccountId());
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<\App\Entity\ProductShipping> un élément par unité de produit à expédier (lignes libres et services ignorés) */
    private function items(Order $order): array
    {
        $items = [];
        foreach ($order->getOrderItems() as $line) {
            $product = $line->getProductVariant()?->getProduct();
            if ($product === null || !$product->isRequiresShipping()) {
                continue;
            }
            $ps = $this->resolver->forProductId((int) $product->getId());
            for ($i = 0; $i < max(1, (int) $line->getQuantity()); $i++) {
                $items[] = $ps;
            }
        }

        return $items;
    }

    /** @return array<string, mixed> adresse EasyPost du destinataire */
    private function to(Order $order): array
    {
        $a = $order->getShippingAdress();
        if ($a === null || trim((string) $a->getAddress()) === '' || trim((string) $a->getCity()) === '' || trim((string) $a->getCountry()) === '') {
            throw new \LogicException('Adresse de livraison absente ou incomplète.');
        }

        return $this->addresses->buildTo(['name' => trim((string) ($a->getFullname() ?: trim($a->getFirstname() . ' ' . $a->getLastname()))), 'street1' => (string) $a->getAddress(), 'street2' => $a->getComplement(), 'city' => (string) $a->getCity(), 'province' => (string) $a->getProvince(), 'postal_code' => (string) $a->getCodepostal(), 'country' => strtoupper((string) $a->getCountry()), 'contactNumber' => $a->getPhone() ?: ($order->getUserId()?->getEmail() ? null : null)]);
    }

    /** @return array<string, mixed> déclaration douanière EasyPost (marchandise, valeur et poids par ligne) */
    private function customs(Order $order, array $from): array
    {
        $items = [];
        foreach ($order->getOrderItems() as $line) {
            $product = $line->getProductVariant()?->getProduct();
            if ($product === null || !$product->isRequiresShipping()) {
                continue;
            }
            $qty = max(1, (int) $line->getQuantity());
            $ps = $this->resolver->forProductId((int) $product->getId());
            $items[] = [
                'description' => mb_substr((string) ($line->getProductName() ?: $product->getName()), 0, 100),
                'quantity' => $qty,
                'value' => round((float) $line->getUnitPrice() * $qty, 2),
                'weight' => round((float) $ps->getWeightKg() * 35.274 * $qty, 2),
                'origin_country' => strtoupper((string) ($from['country'] ?? 'CA')),
                'currency' => $this->currency->code(),
            ];
        }

        return ['contents_type' => 'merchandise', 'customs_certify' => true, 'customs_signer' => mb_substr((string) ($from['name'] ?? ''), 0, 100), 'non_delivery_option' => 'return', 'restriction_type' => 'none', 'customs_items' => $items];
    }

    private function blocked(array $out, string $code, ?string $detail = null): array
    {
        return ['blocker' => $code, 'blockerMessage' => self::MESSAGES[$code] . ($detail !== null ? ' (' . $detail . ')' : '')] + $out;
    }
}
