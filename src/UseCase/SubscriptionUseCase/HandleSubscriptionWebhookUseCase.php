<?php

namespace App\UseCase\SubscriptionUseCase;

use App\Entity\Subscription;
use App\Services\OrderService\OrderMailerService;
use App\Services\SubscriptionService\SubscriptionMailer;
use App\Services\SubscriptionService\SubscriptionOrderFactory;
use App\Services\SubscriptionService\SubscriptionService;
use App\Services\SubscriptionService\SubscriptionStripeGateway;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Webhooks Stripe Billing (POST /api/stripe/webhook) : invoice.paid → commande de l'échéance et abonnement actif ;
 * invoice.payment_failed → past_due et courriel ; customer.subscription.updated / deleted → état recopié, courriel de
 * résiliation. Le site vient des métadonnées posées à la souscription (tenant_code) ; un évènement sans site ou sans
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
                $subscription->setStatus(Subscription::STATUS_PAST_DUE);
                $em->flush();
                $this->mailer->notify($subscription, 'payment_failed', $locale, $host);

                return ['status' => 'processed'];
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
