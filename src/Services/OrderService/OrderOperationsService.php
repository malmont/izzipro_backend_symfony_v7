<?php

namespace App\Services\OrderService;

use App\Entity\Order;
use App\Entity\Payments;
use App\Entity\StatusCommande;
use App\Entity\StatusPayment;
use App\Services\CheckoutService\CheckoutPaymentGatewayInterface;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\OrderUseCase\CancelOrderUseCase;
use App\UseCase\OrderUseCase\PaymentHandlerUseCase;
use Psr\Log\LoggerInterface;

/**
 * Opérations de support sur une commande web (lot 3, 07/11/2026) : remboursement total ou partiel d'un paiement Stripe capturé,
 * capture d'un paiement autorisé non capturé, annulation avec motif (CancelOrderUseCase, qui rembourse désormais chez Stripe), renvoi
 * des courriels (confirmation, reçu, expédition). Jamais sur une vente au comptoir (la caisse a ses retours et remboursements).
 * Chaque opération est tracée (content_audit_log, ressource order) et prévient le client dans la langue du site.
 */
class OrderOperationsService
{
    public const MESSAGES = [
        'COUNTER_SALE' => 'Vente au comptoir : les remboursements se font depuis la caisse (retours).',
        'ORDER_CANCELLED' => 'Commande annulée : son paiement est déjà remboursé.',
        'NO_ONLINE_PAYMENT' => 'Aucun paiement par carte capturé sur cette commande : rien à rembourser en ligne.',
        'AMOUNT_INVALID' => 'Montant invalide.',
        'REFUND_EXCEEDS' => 'Le montant dépasse ce qui reste remboursable.',
        'REFUND_FAILED' => 'Stripe a refusé le remboursement : rien n\'est enregistré, réessayez ou remboursez depuis le tableau de bord Stripe.',
        'NOTHING_TO_CAPTURE' => 'Aucun paiement autorisé en attente de capture.',
        'CAPTURE_FAILED' => 'Stripe a refusé la capture : l\'autorisation a peut-être expiré (7 jours).',
        'ALREADY_SHIPPED' => 'Commande expédiée ou livrée : remboursez-la plutôt que de l\'annuler.',
        'NO_EMAIL' => 'Le client n\'a pas d\'adresse courriel.',
        'NOT_SHIPPED' => 'La commande n\'est pas expédiée : rien à renvoyer.',
        'CANCEL_FAILED' => 'Annulation impossible.',
    ];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly CheckoutPaymentGatewayInterface $checkout,
        private readonly PaymentHandlerUseCase $payments,
        private readonly CancelOrderUseCase $cancelOrder,
        private readonly OrderMailerService $mailer,
        private readonly OrderShipmentService $shipments,
        private readonly ContentAuditRecorder $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * État des paiements en ligne : capturés, déjà remboursés, remboursable, autorisés à capturer.
     *
     * @return array{capturedCents: int, refundedCents: int, refundableCents: int, authorizedCents: int, captured: list<array{paymentId: int, intentId: string, amountCents: int, receiptUrl: ?string}>, authorized: list<array{paymentId: int, intentId: string, amountCents: int}>, counterSale: bool}
     */
    public function money(Order $order): array
    {
        $out = ['capturedCents' => 0, 'refundedCents' => 0, 'refundableCents' => 0, 'authorizedCents' => 0, 'captured' => [], 'authorized' => [], 'counterSale' => $order->getOrderSource()?->getId() === 2];
        foreach ($order->getPayments() as $p) {
            $amount = (int) round((float) $p->getAmount());
            if ($amount < 0 && $p->getPaymentType()?->getId() === 1) {
                $out['refundedCents'] += -$amount;
            } elseif ($amount > 0 && $p->getStripePaymentId() && $p->getStripeStatus() === 'succeeded') {
                $out['capturedCents'] += $amount;
                $out['captured'][] = ['paymentId' => (int) $p->getId(), 'intentId' => (string) $p->getStripePaymentId(), 'amountCents' => $amount, 'receiptUrl' => $p->getStripeReceiptUrl()];
            } elseif ($amount > 0 && $p->getStripePaymentId() && $p->getStripeStatus() === 'requires_capture') {
                $out['authorizedCents'] += $amount;
                $out['authorized'][] = ['paymentId' => (int) $p->getId(), 'intentId' => (string) $p->getStripePaymentId(), 'amountCents' => $amount];
            }
        }
        $out['refundableCents'] = max(0, $out['capturedCents'] - $out['refundedCents']);

        return $out;
    }

    /** @return array{status: int, body: array<string, mixed>} */
    public function refund(Order $order, int $amountCents, string $reason, bool $notify, ?string $by, string $domain): array
    {
        $money = $this->money($order);
        if ($money['counterSale']) {
            return $this->refuse('COUNTER_SALE');
        }
        if ((int) ($order->getStatus()?->getId() ?? 0) === OrderShipmentService::STATUS_CANCELLED) {
            return $this->refuse('ORDER_CANCELLED');
        }
        if ($money['captured'] === []) {
            return $this->refuse('NO_ONLINE_PAYMENT');
        }
        if ($amountCents <= 0) {
            return $this->refuse('AMOUNT_INVALID');
        }
        if ($amountCents > $money['refundableCents']) {
            return ['status' => 409, 'body' => ['error' => 'REFUND_EXCEEDS', 'message' => self::MESSAGES['REFUND_EXCEEDS'], 'refundableCents' => $money['refundableCents']]];
        }
        // Répartition sur les paiements capturés, dans l'ordre, chacun jusqu'à son montant encore remboursable
        $remaining = $amountCents;
        $alreadyRefunded = $money['refundedCents'];
        $done = [];
        foreach ($money['captured'] as $c) {
            if ($remaining <= 0) {
                break;
            }
            $available = $c['amountCents'] - min($c['amountCents'], $alreadyRefunded);
            $alreadyRefunded = max(0, $alreadyRefunded - $c['amountCents']);
            $part = min($available, $remaining);
            if ($part <= 0) {
                continue;
            }
            $result = $this->checkout->refund($c['intentId'], $part, sprintf('refund-order-%d-%d-%d-%d', $order->getId(), $c['paymentId'], $amountCents, (int) round(microtime(true) * 1000)));
            if ($result === null) {
                $this->logger->error('[Opérations] remboursement Stripe refusé', ['orderId' => $order->getId(), 'intent' => $c['intentId'], 'amount' => $part]);
                if ($done === []) {
                    return $this->refuse('REFUND_FAILED');
                }
                break; // une part remboursée, l'autre refusée : on enregistre ce qui a été rendu et on le dit
            }
            $this->payments->handlePayment($order, -$part, 1, 1, 2, new \DateTime(), null);
            $row = $this->latestRefundRow($order);
            $row?->setStripePaymentId((string) $result['id'])->setStripeStatus('refunded');
            $this->emProvider->getEntityManager()->flush();
            $done[] = ['refundId' => (string) $result['id'], 'amountCents' => $part, 'intentId' => $c['intentId']];
            $remaining -= $part;
        }
        $refunded = array_sum(array_column($done, 'amountCents'));
        $notified = $notify ? $this->mailer->sendOperationNotice($order, 'order_refunded', ['amountCents' => $refunded, 'reason' => $reason], $domain) : false;
        $this->audit->record('order', (int) $order->getId(), 'refund', ['refundedCents' => $money['refundedCents']], ['refundedCents' => $money['refundedCents'] + $refunded, 'amountCents' => $refunded, 'reason' => mb_substr($reason, 0, 500), 'refunds' => $done, 'by' => $by, 'notified' => $notified], ['payments']);

        return ['status' => 200, 'body' => ['refunded' => true, 'amountCents' => $refunded, 'partial' => $refunded < $amountCents, 'refunds' => $done, 'notified' => $notified]];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    public function capture(Order $order, ?string $by): array
    {
        $money = $this->money($order);
        if ($money['counterSale']) {
            return $this->refuse('COUNTER_SALE');
        }
        if ($money['authorized'] === []) {
            return $this->refuse('NOTHING_TO_CAPTURE');
        }
        $em = $this->emProvider->getEntityManager();
        $captured = [];
        foreach ($money['authorized'] as $a) {
            $result = $this->checkout->capture($a['intentId']);
            if ($result === null || ($result['status'] ?? null) !== 'succeeded') {
                $this->logger->error('[Opérations] capture Stripe refusée', ['orderId' => $order->getId(), 'intent' => $a['intentId'], 'status' => $result['status'] ?? null]);
                if ($captured === []) {
                    return $this->refuse('CAPTURE_FAILED');
                }
                break;
            }
            $payment = $em->getRepository(Payments::class)->find($a['paymentId']);
            if ($payment !== null) {
                $payment->setStripeStatus('succeeded')->setStripeReceiptUrl($result['receiptUrl'] ?? $payment->getStripeReceiptUrl());
                $status = $em->getRepository(StatusPayment::class)->find(2);
                if ($status !== null) {
                    $payment->setStatutPayment($status);
                }
            }
            $captured[] = $a;
        }
        if (in_array((int) ($order->getStatus()?->getId() ?? 0), [1, 2], true)) {
            $complete = $em->getRepository(StatusCommande::class)->find(3);
            if ($complete !== null) {
                $order->setStatus($complete)->setStatusUpdatedAt(new \DateTime());
            }
        }
        $em->flush();
        $this->audit->record('order', (int) $order->getId(), 'capture', null, ['captured' => $captured, 'by' => $by], ['payments', 'status']);

        return ['status' => 200, 'body' => ['captured' => true, 'amountCents' => array_sum(array_column($captured, 'amountCents'))]];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    public function cancel(Order $order, string $reason, bool $notify, ?string $by, string $domain): array
    {
        $money = $this->money($order);
        if ($money['counterSale']) {
            return $this->refuse('COUNTER_SALE');
        }
        $status = (int) ($order->getStatus()?->getId() ?? 0);
        if ($status === OrderShipmentService::STATUS_CANCELLED) {
            return $this->refuse('ORDER_CANCELLED');
        }
        if (in_array($status, [OrderShipmentService::STATUS_SHIPPED, OrderShipmentService::STATUS_DELIVERED], true)) {
            return $this->refuse('ALREADY_SHIPPED');
        }
        $response = $this->cancelOrder->execute((int) $order->getId());
        if ($response->getStatusCode() !== 200) {
            $body = json_decode((string) $response->getContent(), true);

            return ['status' => 409, 'body' => ['error' => 'CANCEL_FAILED', 'message' => self::MESSAGES['CANCEL_FAILED'] . ' ' . (string) ($body['message'] ?? $body['error'] ?? '')]];
        }
        $this->emProvider->getEntityManager()->refresh($order);
        $refunded = $money['refundableCents'];
        $notified = $notify ? $this->mailer->sendOperationNotice($order, 'order_cancelled', ['amountCents' => $refunded > 0 ? $refunded : null, 'reason' => $reason], $domain) : false;
        $this->audit->record('order', (int) $order->getId(), 'cancel', ['status' => $status], ['status' => OrderShipmentService::STATUS_CANCELLED, 'refundedCents' => $refunded, 'reason' => mb_substr($reason, 0, 500), 'by' => $by, 'notified' => $notified], ['status', 'payments']);

        return ['status' => 200, 'body' => ['cancelled' => true, 'refundedCents' => $refunded, 'notified' => $notified]];
    }

    /** @return array{status: int, body: array<string, mixed>} kind : confirmation | receipt | shipped */
    public function resend(Order $order, string $kind, string $domain): array
    {
        $email = (string) ($order->getUserId()?->getEmail() ?? '');
        if ($email === '' || str_ends_with($email, '@compte-supprime.invalid')) {
            return $this->refuse('NO_EMAIL');
        }
        $sent = false;
        switch ($kind) {
            case 'confirmation':
                $this->mailer->sendOrderConfirmation($order, 'fr', $domain);
                $sent = true;
                break;
            case 'receipt':
                $money = $this->money($order);
                $sent = $this->mailer->sendOperationNotice($order, 'order_receipt', ['totalCents' => (int) round((float) $order->getTotalAmount()), 'paidCents' => $money['capturedCents'], 'refundedCents' => $money['refundedCents'], 'receiptUrls' => array_column($money['captured'], 'receiptUrl')], $domain);
                break;
            case 'shipped':
                if ($order->getShippedAt() === null && $order->getTrackingNumber() === null) {
                    return $this->refuse('NOT_SHIPPED');
                }
                $sent = $this->shipments->notifyShipped($order, $domain);
                break;
            default:
                return ['status' => 422, 'body' => ['error' => 'VALIDATION_FAILED', 'message' => 'kind : confirmation, receipt ou shipped attendu']];
        }
        $this->audit->record('order', (int) $order->getId(), 'resend_' . $kind, null, ['sent' => $sent], []);

        return ['status' => 200, 'body' => ['sent' => $sent, 'kind' => $kind]];
    }

    private function latestRefundRow(Order $order): ?Payments
    {
        $this->emProvider->getEntityManager()->refresh($order);
        $row = null;
        foreach ($order->getPayments() as $p) {
            if ((float) $p->getAmount() < 0 && $p->getPaymentType()?->getId() === 1 && ($row === null || $p->getId() > $row->getId())) {
                $row = $p;
            }
        }

        return $row;
    }

    private function refuse(string $code): array
    {
        return ['status' => 409, 'body' => ['error' => $code, 'message' => self::MESSAGES[$code]]];
    }
}
