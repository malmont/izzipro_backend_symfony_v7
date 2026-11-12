<?php

namespace App\Support\Services;

use App\Entity\Order;
use App\Services\TenantEntityManagerProvider;

/**
 * Résumé EN LECTURE de la commande ou de la vente liée à un ticket (lot 1, décision du propriétaire du 11/11/2026) : seulement ce que le client
 * a payé ou vu (lignes, prix de vente, totaux, paiements, retours, livraison et suivi). Construit par LISTE BLANCHE : ni coût d'achat, ni marge,
 * ni prix catalogue d'un prix manuel, ni prix fournisseur, ni note interne, ni jeton. Montants en cents.
 */
class SupportOrderSummary
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    public function exists(int $orderId): bool
    {
        return $this->emProvider->getEntityManager()->find(Order::class, $orderId) !== null;
    }

    /** Poste de caisse d'une vente au comptoir : la session de caisse de son encaissement */
    public function cashRegisterId(int $orderId): ?int
    {
        $id = $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT c.cash_register_id FROM transaction_caisse t JOIN caisse c ON c.id = t.caisse_id WHERE t.order_caisse_id = ? ORDER BY t.id LIMIT 1', [$orderId]);

        return $id === false || $id === null ? null : (int) $id;
    }

    public function brief(?int $orderId): ?array
    {
        $o = $orderId === null ? null : $this->emProvider->getEntityManager()->find(Order::class, $orderId);

        return $o === null ? null : ['id' => (int) $o->getId(), 'reference' => $o->getReference()];
    }

    public function summary(?int $orderId): ?array
    {
        $em = $this->emProvider->getEntityManager();
        $o = $orderId === null ? null : $em->find(Order::class, $orderId);
        if ($o === null) {
            return null;
        }
        $c = fn (mixed $v) => $v === null ? null : (int) round((float) $v);
        $lines = [];
        foreach ($o->getOrderItems() as $item) {
            $lines[] = ['name' => $item->displayName(), 'variant' => $item->getVariantLabel(), 'quantity' => (int) $item->getQuantity(), 'unitPrice' => $c($item->getUnitPrice()),
                'discount' => $c($item->getDiscountAmount()) ?: 0, 'total' => $c($item->getTotalPrice())];
        }
        $payments = [];
        foreach ($o->getPayments() as $p) {
            $payments[] = ['method' => $p->getPaymentMethod()?->getName(), 'amount' => $c($p->getAmount()), 'date' => $p->getPaymentDate()?->format(\DateTimeInterface::ATOM)];
        }
        $returns = [];
        foreach ($em->getConnection()->fetchAllAssociative('SELECT amount, created_at FROM order_return WHERE order_id = ? ORDER BY id', [$o->getId()]) as $r) {
            $returns[] = ['amount' => (int) $r['amount'], 'date' => (new \DateTimeImmutable((string) $r['created_at'], new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM)];
        }
        foreach ($em->getRepository(Order::class)->findBy(['returnedFrom' => $o]) as $ret) { // retour en caisse : commande de retour (montants négatifs)
            $returns[] = ['amount' => abs((int) $c($ret->getTotalAmount())), 'date' => $ret->getOrderDate()?->format(\DateTimeInterface::ATOM)];
        }

        return [
            'id' => (int) $o->getId(), 'reference' => $o->getReference(), 'date' => $o->getOrderDate()?->format(\DateTimeInterface::ATOM),
            'source' => $o->getOrderSource()?->getName(), 'status' => $o->getStatus()?->getName(),
            'lines' => $lines,
            'totals' => ['subtotal' => $c($o->getSubTotal()), 'discount' => $c($o->getDiscountAmount()) ?: 0, 'shipping' => $c($o->getShippingCost()) ?: 0, 'tax' => $c($o->getTotalTax()), 'total' => $c($o->getTotalAmount())],
            'payments' => $payments, 'returns' => $returns,
            'shipping' => ['carrier' => $o->getCarrier()?->getName(), 'trackingNumber' => $o->getTrackingNumber(), 'trackingUrl' => $o->getTrackingUrl(), 'shippedAt' => $o->getShippedAt()?->format(\DateTimeInterface::ATOM)],
        ];
    }
}
