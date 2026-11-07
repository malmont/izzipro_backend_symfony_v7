<?php

namespace App\Services\OrderService;

use App\Entity\ContentAuditLog;
use App\Entity\Order;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;

/**
 * Fiche commande refondue et vue « À traiter » du back-office (lot 4, 07/11/2026), en lecture : tout ce qu'une personne au support
 * doit voir sur une commande (client, chronologie, articles, paiements et remboursements, expédition, journal) et les actions permises
 * par son état ; et les commandes qui attendent une action (à expédier, paiement à capturer, incomplètes anciennes, en transit depuis
 * trop longtemps). Les actions elles-mêmes sont celles des lots 1 à 3.
 */
class OrderDeskService
{
    public const STATUS_LABELS = [1 => 'Incomplète (paiement non finalisé)', 2 => 'En cours', 3 => 'Payée', 4 => 'En préparation', 5 => 'Expédiée', 6 => 'Livrée', 7 => 'Annulée'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly OrderPresenter $presenter,
        private readonly OrderOperationsService $operations,
        private readonly OrderShipmentService $shipments,
        private readonly ContentAuditRecorder $audit,
        private readonly TenantCurrencyProvider $currency,
        private readonly ?OrderReturnService $returns = null, // retours web (lot 7, 07/11/2026)
        private readonly ?OrderAddressService $addresses = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function sheet(Order $order, string $host, string $locale = 'fr'): array
    {
        $em = $this->emProvider->getEntityManager();
        $dto = $this->presenter->toDto($order, $host, $locale)->toArray();
        $money = $this->operations->money($order);
        $status = (int) ($order->getStatus()?->getId() ?? 0);
        $counter = $order->getOrderSource()?->getId() === 2;
        $payments = [];
        foreach ($order->getPayments() as $p) {
            $payments[] = ['id' => (int) $p->getId(), 'amountCents' => (int) round((float) $p->getAmount()), 'date' => $p->getPaymentDate()?->format('Y-m-d H:i'), 'method' => $p->getPaymentMethod()?->getName(), 'type' => $p->getPaymentType()?->getName(), 'status' => $p->getStatutPayment()?->getName(), 'stripeId' => $p->getStripePaymentId(), 'stripeStatus' => $p->getStripeStatus(), 'receiptUrl' => $p->getStripeReceiptUrl(), 'card' => trim((string) $p->getStripeCardBrand() . ' ' . ($p->getStripeLast4() ? '•••• ' . $p->getStripeLast4() : ''))];
        }
        usort($payments, fn ($a, $b) => $a['id'] <=> $b['id']);
        $labels = [];
        if ($order->getShippingOrder() !== null) {
            foreach ($order->getShippingOrder()->getParcels() as $parcel) {
                $labels[] = ['index' => $parcel->getIndex(), 'url' => $parcel->getShippingLabel()?->getLabelUrl(), 'tracking' => $parcel->getShippingLabel()?->getTrackingCode(), 'weight' => $parcel->getWeight()];
            }
        }
        $journal = [];
        try {
            [$entries] = $this->audit->repository()->search('order', (string) $order->getId(), 1, 30);
            /** @var ContentAuditLog $e */
            foreach ($entries as $e) {
                $journal[] = ['at' => $e->getCreatedAt()->format('Y-m-d H:i'), 'action' => $e->getAction(), 'by' => $e->getUser(), 'after' => json_decode((string) $e->getAfter(), true)];
            }
        } catch (\Throwable) {
            $journal = [];
        }
        $timeline = $this->timeline($order, $payments, $journal, $status);
        $customer = null;
        if ($order->getUserId() !== null) {
            $u = $order->getUserId();
            $customer = ['id' => (int) $u->getId(), 'name' => trim((string) $u->getFirstname() . ' ' . (string) $u->getLastname()), 'email' => (string) $u->getEmail(), 'ordersCount' => (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM "order" WHERE user_id_id = ?', [$u->getId()])];
        }
        $actions = [
            'capture' => !$counter && $money['authorized'] !== [],
            'refund' => !$counter && $status !== 7 && $money['refundableCents'] > 0,
            'cancel' => !$counter && in_array($status, [1, 2, 3, 4], true),
            'ship' => !$counter && $this->shipments->shipBlocker($order) === null,
            'label' => !$counter && $this->shipments->shipBlocker($order) === null && $order->getShippingAdress() !== null,
            'deliver' => !$counter && $this->shipments->deliverBlocker($order) === null,
            'emails' => !$counter && (string) ($order->getUserId()?->getEmail() ?? '') !== '' && !str_ends_with((string) $order->getUserId()?->getEmail(), '@compte-supprime.invalid'),
            'return' => !$counter && $this->returns !== null && $this->returns->blocker($order) === null,
            'address' => !$counter && $this->addresses !== null && $this->addresses->blocker($order) === null,
            'counterSale' => $counter,
        ];
        $returns = $this->returns !== null && !$counter ? array_map(fn ($r) => $r->toArray(), $this->returns->returns($order)) : [];

        return ['order' => $dto, 'statusId' => $status, 'statusLabel' => self::STATUS_LABELS[$status] ?? (string) $order->getStatus()?->getName(), 'currency' => $this->currency->code(), 'money' => $money, 'payments' => $payments, 'labels' => $labels,
            'shipment' => ['shippedAt' => $order->getShippedAt()?->format('Y-m-d H:i'), 'carrier' => $order->getTrackingCarrier(), 'trackingNumber' => $order->getTrackingNumber(), 'trackingUrl' => $order->getTrackingUrl()],
            'timeline' => $timeline, 'journal' => $journal, 'customer' => $customer, 'actions' => $actions, 'returns' => $returns];
    }

    /** @return list<array{at: string, label: string, kind: string}> */
    private function timeline(Order $order, array $payments, array $journal, int $status): array
    {
        $events = [];
        if ($order->getOrderDate()) {
            $events[] = ['at' => $order->getOrderDate()->format('Y-m-d H:i'), 'label' => 'Commande passée', 'kind' => 'created'];
        }
        foreach ($payments as $p) {
            if ($p['amountCents'] > 0 && ($p['stripeStatus'] === 'succeeded' || $p['status'] === 'Complété')) {
                $events[] = ['at' => (string) $p['date'], 'label' => sprintf('Paiement encaissé : %s %s', number_format($p['amountCents'] / 100, 2, ',', ' '), $this->currency->code()), 'kind' => 'paid'];
            } elseif ($p['amountCents'] < 0) {
                $events[] = ['at' => (string) $p['date'], 'label' => sprintf('Remboursement : %s %s', number_format(-$p['amountCents'] / 100, 2, ',', ' '), $this->currency->code()), 'kind' => 'refund'];
            }
        }
        if ($order->getShippedAt()) {
            $events[] = ['at' => $order->getShippedAt()->format('Y-m-d H:i'), 'label' => 'Expédiée' . ($order->getTrackingCarrier() ? ' par ' . $order->getTrackingCarrier() : ''), 'kind' => 'shipped'];
        }
        $labelsByAction = ['deliver' => 'Livrée', 'cancel' => 'Annulée', 'capture' => 'Paiement capturé', 'return' => 'Retour d\'articles', 'address' => 'Adresse de livraison changée', 'resend_confirmation' => 'Confirmation renvoyée', 'resend_receipt' => 'Reçu renvoyé', 'resend_shipped' => 'Avis d\'expédition renvoyé'];
        foreach ($journal as $j) {
            if (isset($labelsByAction[$j['action']])) {
                $events[] = ['at' => $j['at'], 'label' => $labelsByAction[$j['action']] . ($j['by'] ? ' (' . $j['by'] . ')' : ''), 'kind' => $j['action']];
            }
        }
        if ($status === 7 && $order->getStatusUpdatedAt() && !array_filter($events, fn ($e) => $e['kind'] === 'cancel')) {
            $events[] = ['at' => $order->getStatusUpdatedAt()->format('Y-m-d H:i'), 'label' => 'Annulée', 'kind' => 'cancel'];
        }
        usort($events, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return $events;
    }

    /**
     * Commandes qui attendent une action (commandes web ; les ventes au comptoir ont la caisse).
     *
     * @return array{toShip: list<array<string, mixed>>, toCapture: list<array<string, mixed>>, incomplete: list<array<string, mixed>>, inTransit: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function todo(int $limit = 100): array
    {
        $c = $this->emProvider->getEntityManager()->getConnection();
        $select = 'SELECT o.id, o.reference, o.order_date, o.status_id, o.total_amount, o.shipped_at, u.email, u.firstname, u.lastname FROM "order" o LEFT JOIN "user" u ON u.id = o.user_id_id WHERE COALESCE(o.order_source_id, 1) <> 2 AND ';
        $map = fn (array $r) => ['id' => (int) $r['id'], 'reference' => $r['reference'], 'date' => substr((string) $r['order_date'], 0, 16), 'statusId' => (int) $r['status_id'], 'status' => self::STATUS_LABELS[(int) $r['status_id']] ?? '', 'totalCents' => (int) round((float) $r['total_amount']), 'customer' => trim((string) $r['firstname'] . ' ' . (string) $r['lastname']) ?: (string) $r['email'], 'email' => $r['email'], 'shippedAt' => $r['shipped_at'] ? substr((string) $r['shipped_at'], 0, 10) : null];
        $limit = max(1, min(500, $limit));
        $lists = [
            'toShip' => $select . "o.status_id IN (3, 4) AND o.shipping_adress_id IS NOT NULL AND o.shipped_at IS NULL ORDER BY o.order_date ASC LIMIT $limit",
            'toCapture' => $select . "EXISTS (SELECT 1 FROM payments p WHERE p.order_payment_id = o.id AND p.amount > 0 AND p.stripe_status = 'requires_capture') AND o.status_id <> 7 ORDER BY o.order_date ASC LIMIT $limit",
            'incomplete' => $select . "o.status_id = 1 AND o.order_date < NOW() - INTERVAL '1 hour' ORDER BY o.order_date DESC LIMIT $limit",
            'inTransit' => $select . "o.status_id = 5 AND COALESCE(o.shipped_at, o.status_updated_at, o.order_date) < NOW() - INTERVAL '10 days' ORDER BY o.order_date ASC LIMIT $limit",
        ];
        $out = ['counts' => []];
        foreach ($lists as $key => $sql) {
            try {
                $out[$key] = array_map($map, $c->fetchAllAssociative($sql));
            } catch (\Throwable) {
                $out[$key] = [];
            }
            $out['counts'][$key] = count($out[$key]);
        }
        $out['counts']['total'] = $out['counts']['toShip'] + $out['counts']['toCapture'] + $out['counts']['incomplete'] + $out['counts']['inTransit'];

        return $out;
    }
}
