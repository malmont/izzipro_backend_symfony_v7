<?php

namespace App\UseCase\SubscriptionUseCase;

use App\Entity\Subscription;
use App\Services\OrderService\OrderMailerService;
use App\Services\SubscriptionService\SubscriptionMailer;
use App\Services\SubscriptionService\SubscriptionOrderFactory;
use App\Services\SubscriptionService\SubscriptionService;
use App\Services\SubscriptionService\SubscriptionException;
use App\Services\SubscriptionService\SubscriptionStripeGateway;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Webhooks Stripe Billing (POST /api/stripe/webhook) : invoice.paid → commande de l'échéance, abonnement actif, impayé
 * effacé ; invoice.payment_failed → voir paymentFailed ; customer.subscription.updated / deleted / paused / resumed →
 * état recopié, courriel de résiliation. Le site vient des métadonnées posées à la souscription (tenant_code) ; un évènement sans site ou sans
 * abonnement connu est ignoré.
 */
class HandleSubscriptionWebhookUseCase
{
    public const TYPES = ['invoice.paid', 'invoice.payment_succeeded', 'invoice.payment_failed', 'customer.subscription.updated', 'customer.subscription.deleted', 'customer.subscription.paused', 'customer.subscription.resumed'];

    public function __construct(
        private readonly TenantConnectionManager $connectionManager,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly SubscriptionService $service,
        private readonly SubscriptionOrderFactory $orders,
        private readonly SubscriptionMailer $mailer,
        private readonly OrderMailerService $orderMailer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $object data.object de l'évènement
     * @return array{status: string, detail?: string}
     */
    public function execute(string $type, array $object, string $host): array
    {
        $tenantCode = self::metadata($object, 'tenant_code');
        if ($tenantCode === null) {
            return ['status' => 'ignored', 'detail' => 'sans tenant_code'];
        }
        $tenant = $this->connectionManager->findTenantByCode($tenantCode);
        if (!$tenant || empty($tenant['dbname'])) {
            return ['status' => 'ignored', 'detail' => 'site inconnu'];
        }
        $this->emProvider->switchTenant($tenant['dbname'], $tenantCode);

        $stripeId = str_starts_with($type, 'invoice.') ? self::invoiceSubscriptionId($object) : (string) ($object['id'] ?? '');
        $subscription = $stripeId !== '' ? $this->service->subscriptions()->findOneByStripeId($stripeId) : null;
        if ($subscription === null) {
            return ['status' => 'ignored', 'detail' => 'abonnement inconnu'];
        }
        $locale = 'fr';
        $em = $this->emProvider->getEntityManager();

        switch ($type) {
            case 'invoice.paid':
            case 'invoice.payment_succeeded':
                if (($object['paid'] ?? true) === false || (int) ($object['amount_paid'] ?? 0) === 0 && (int) ($object['total'] ?? 0) > 0) {
                    return ['status' => 'ignored', 'detail' => 'facture non payée'];
                }
                $order = $this->orders->createFromInvoice($subscription, $object);
                $this->keepPaymentMethod($subscription, $object);
                $subscription->setPaymentIssue(null);
                $subscription->setStatus($subscription->getStatus() === Subscription::STATUS_TRIALING && (int) ($object['total'] ?? 0) === 0 ? Subscription::STATUS_TRIALING : Subscription::STATUS_ACTIVE);
                $periodEnd = $object['lines']['data'][0]['period']['end'] ?? null;
                if (is_numeric($periodEnd)) {
                    $subscription->setCurrentPeriodEnd((new \DateTimeImmutable())->setTimestamp((int) $periodEnd));
                }
                $em->flush();
                if ($order !== null) {
                    $this->orderMailer->sendOrderConfirmation($order, $locale, rtrim($host, '/') . '/');
                }

                return ['status' => 'processed', 'detail' => $order ? 'commande ' . $order->getId() : 'sans commande : ' . ($this->orders->lastSkipReason ?? '?')];
            case 'invoice.payment_failed':
                return $this->paymentFailed($subscription, $object, $locale, $host);
            default:
                $before = $subscription->getStatus();
                $this->service->applyStripeState($subscription, SubscriptionStripeGateway::normalize($object));
                $em->flush();
                if ($subscription->getStatus() === Subscription::STATUS_CANCELED && $before !== Subscription::STATUS_CANCELED) {
                    $this->mailer->notify($subscription, 'canceled', $locale, $host);
                }

                return ['status' => 'processed', 'detail' => $subscription->getStatus()];
        }
    }

    /**
     * Facture payée avec un montant (25/10/2026) : si l'abonnement n'a pas de carte par défaut chez Stripe (essai gratuit
     * dont la carte n'a pas été enregistrée, facture payée ensuite depuis le courriel d'impayé), celle qui vient de payer
     * le devient, pour que les échéances suivantes soient débitées seules. Une erreur Stripe ne bloque jamais le webhook.
     */
    private function keepPaymentMethod(Subscription $subscription, array $invoice): void
    {
        if ((int) ($invoice['amount_paid'] ?? 0) <= 0 || !is_string($invoice['id'] ?? null)) {
            return;
        }
        try {
            if (($paymentMethod = $this->service->ensureDefaultPaymentMethod($subscription, $invoice['id'])) !== null) {
                $this->logger->info('[Subscription] Carte de la facture posée par défaut sur l\'abonnement', ['subscription' => $subscription->getId(), 'invoice' => $invoice['id']]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Subscription] Carte par défaut non posée après une facture payée', ['subscription' => $subscription->getId(), 'invoice' => $invoice['id'], 'message' => $e->getMessage()]);
        }
    }

    /**
     * Paiement refusé (13/10/2026) :
     * - première facture (abonnement incomplete) : il le reste, le client réessaie depuis la page de souscription (même
     *   abonnement, même clientSecret) ; Stripe l'expire au bout de 23 h (incomplete_expired → résilié) ;
     * - échéance : past_due, impayé gardé (montant, lien de paiement Stripe, prochain essai) et courriel au client ;
     * - au MAX_FAILED_PAYMENTS-ième essai, ou quand Stripe ne prévoit plus d'essai : résilié tout de suite, courriel.
     *
     * @return array{status: string, detail?: string}
     */
    private function paymentFailed(Subscription $subscription, array $invoice, string $locale, string $host): array
    {
        $em = $this->emProvider->getEntityManager();
        if ($subscription->getStatus() === Subscription::STATUS_INCOMPLETE || ($invoice['billing_reason'] ?? null) === 'subscription_create') {
            return ['status' => 'processed', 'detail' => 'premier paiement refusé : abonnement incomplete, nouvel essai possible'];
        }
        $attempt = (int) ($invoice['attempt_count'] ?? 1);
        $next = is_numeric($invoice['next_payment_attempt'] ?? null) ? (new \DateTimeImmutable())->setTimestamp((int) $invoice['next_payment_attempt']) : null;
        $issue = [
            'amountDue' => (int) ($invoice['amount_due'] ?? $invoice['amount_remaining'] ?? 0), 'currency' => strtoupper((string) ($invoice['currency'] ?? 'cad')),
            'hostedInvoiceUrl' => $invoice['hosted_invoice_url'] ?? null, 'nextPaymentAttempt' => $next?->format(\DateTimeInterface::ATOM),
            'attemptCount' => $attempt, 'maxAttempts' => SubscriptionService::MAX_FAILED_PAYMENTS,
        ];
        $subscription->setStatus(Subscription::STATUS_PAST_DUE)->setPaymentIssue($issue);
        $em->flush();
        $amount = number_format($issue['amountDue'] / 100, 2, ',', ' ') . ' ' . $issue['currency'];

        if ($attempt >= SubscriptionService::MAX_FAILED_PAYMENTS || $next === null) {
            try {
                $this->service->cancel($subscription, false);
            } catch (SubscriptionException $e) {
                // Déjà résilié chez Stripe (réglage de relance du compte) : l'état local suffit
                $this->logger->warning('[Subscription] Résiliation après impayés : ' . $e->getMessage(), ['subscription' => $subscription->getId()]);
                $subscription->setStatus(Subscription::STATUS_CANCELED)->setCanceledAt($subscription->getCanceledAt() ?? new \DateTimeImmutable());
                $em->flush();
            }
            $this->mailer->notify($subscription, 'canceled_unpaid', $locale, $host, ['rows' => ['Montant impayé' => $amount, 'Essais de paiement' => (string) $attempt]]);

            return ['status' => 'processed', 'detail' => sprintf('résilié après %d essai(s) de paiement', $attempt)];
        }
        $this->mailer->notify($subscription, 'payment_failed', $locale, $host, [
            'rows' => ['Montant dû' => $amount, 'Essai' => sprintf('%d sur %d', $attempt, SubscriptionService::MAX_FAILED_PAYMENTS), 'Prochain essai' => $next->format('d/m/Y')],
            'payUrl' => $issue['hostedInvoiceUrl'],
        ]);

        return ['status' => 'processed', 'detail' => sprintf('past_due, essai %d sur %d', $attempt, SubscriptionService::MAX_FAILED_PAYMENTS)];
    }

    private static function metadata(array $object, string $key): ?string
    {
        foreach ([$object['metadata'] ?? null, $object['subscription_details']['metadata'] ?? null, $object['parent']['subscription_details']['metadata'] ?? null, $object['lines']['data'][0]['metadata'] ?? null] as $metadata) {
            if (is_array($metadata) && isset($metadata[$key]) && $metadata[$key] !== '') {
                return (string) $metadata[$key];
            }
        }

        return null;
    }

    private static function invoiceSubscriptionId(array $invoice): string
    {
        $candidate = $invoice['subscription'] ?? $invoice['parent']['subscription_details']['subscription'] ?? $invoice['lines']['data'][0]['subscription'] ?? null;
        if (is_array($candidate)) {
            $candidate = $candidate['id'] ?? null;
        }

        return is_string($candidate) ? $candidate : '';
    }
}
