<?php

namespace App\Services\Platform\OfferPayments;

use App\Entity\Subscription;
use App\Entity\SubscriptionPlan;
use App\Incident\Services\PlatformIncidentService;
use App\Services\Platform\OfferFulfillmentService;
use App\Services\Platform\PlatformModeService;
use App\Services\SubscriptionService\SubscriptionLock;
use App\Services\SubscriptionService\SubscriptionService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Reprise et réconciliation des droits vendus par la boutique de la plateforme (lot PROVISION-3, 18/11/2026, `docs/console-pilotage.md`,
 * « Paiements des offres ») :
 * - `retryDue` / `retryOne` : un abonnement de la file de reprise relit son état chez Stripe, puis ses droits VOULUS sont recalculés
 *   (`OfferFulfillmentService::resync`), sans rejouer d'événement ; un échec avance au palier suivant ; au 5e, incident de la plateforme ;
 * - `reconcileAll` (chaque nuit) : chaque abonnement branché de la boutique est comparé à Stripe (statut, fin de période) puis à ses droits ;
 *   un écart est corrigé et devient un incident. Seuls les droits de l'abonnement concerné sont touchés, jamais un droit d'une autre origine.
 */
class OfferReconciler
{
    public const ALERT_AFTER = 5;
    /** Une seule exécution de la commande à la fois (minuteur de 15 minutes et passe de nuit) */
    private const RUN_LOCK = 'platform-offers-reconcile';

    public function __construct(
        private readonly OfferPaymentsRepository $payments,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly SubscriptionService $subscriptions,
        private readonly OfferFulfillmentService $fulfillment,
        private readonly PlatformModeService $platform,
        private readonly PlatformIncidentService $incidents,
        private readonly LoggerInterface $logger,
        private readonly ?\Doctrine\Persistence\ManagerRegistry $registry = null,
    ) {
    }

    /**
     * Prend le verrou de la commande (base maître), ou rend faux si une autre exécution le tient.
     */
    public function acquireRun(): bool
    {
        $stmt = $this->tenants->getPdoMaster()->prepare('SELECT pg_try_advisory_lock(hashtext(?))');
        $stmt->execute([self::RUN_LOCK]);

        return filter_var($stmt->fetchColumn(), FILTER_VALIDATE_BOOLEAN);
    }

    public function releaseRun(): void
    {
        $this->tenants->getPdoMaster()->prepare('SELECT pg_advisory_unlock(hashtext(?))')->execute([self::RUN_LOCK]);
    }

    /** @return array{done: int, failed: int} */
    public function retryDue(int $limit = 50): array
    {
        $done = $failed = 0;
        foreach ($this->payments->dueRetries($limit) as $row) {
            $this->process($row) ? ++$done : ++$failed;
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /** « Rejouer » de l'écran : la reprise tout de suite. Rend vrai si les droits sont posés */
    public function retryOne(int $id): bool
    {
        $row = $this->payments->retry($id);

        return $row !== null && $this->process($row);
    }

    /**
     * @return array{checked: int, stripeFixed: int, rightsFixed: int, failed: int, purged: int}
     */
    public function reconcileAll(): array
    {
        $out = ['checked' => 0, 'stripeFixed' => 0, 'rightsFixed' => 0, 'failed' => 0, 'purged' => $this->payments->purgeEvents()];
        $shop = $this->platform->shopTenant();
        $tenant = $shop !== null ? $this->tenants->findTenantByCode($shop) : null;
        if ($tenant === null || empty($tenant['dbname'])) {
            return $out;
        }
        $this->emProvider->switchTenant((string) $tenant['dbname'], $shop);
        $em = $this->emProvider->getEntityManager();
        $ids = $em->getConnection()->fetchFirstColumn('SELECT s.id FROM subscription s JOIN subscription_plan p ON p.id = s.plan_id
            WHERE p.fulfillment_type = ? AND s.fulfillment_target IS NOT NULL AND (s.status <> ? OR s.canceled_at > NOW() - INTERVAL \'40 days\') ORDER BY s.id',
            [SubscriptionPlan::FULFILLMENT_PLATFORM_OFFER, Subscription::STATUS_CANCELED]);
        foreach ($ids as $id) {
            ++$out['checked'];
            try {
                $stripeId = $em->getConnection()->fetchOne('SELECT stripe_subscription_id FROM subscription WHERE id = ?', [(int) $id]);
                SubscriptionLock::run($em->getConnection(), SubscriptionLock::key($shop, is_string($stripeId) ? $stripeId : null, (int) $id), function () use ($em, $id, $shop, &$out) {
                    $subscription = $em->getRepository(Subscription::class)->find((int) $id);
                    if ($subscription === null) {
                        return;
                    }
                    $em->refresh($subscription);
                    $before = [$subscription->getStatus(), $subscription->getCurrentPeriodEnd()?->getTimestamp()];
                    if ($subscription->getStripeSubscriptionId() !== null) {
                        $this->subscriptions->sync($subscription); // l'écouteur des droits suit le changement éventuel
                    }
                    $after = [$subscription->getStatus(), $subscription->getCurrentPeriodEnd()?->getTimestamp()];
                    if ($before !== $after) {
                        ++$out['stripeFixed'];
                        $this->incidents->recordPlatformIssue($shop, 'offres/abonnement/' . $id, 'warning', 'Paiements des offres : état de l\'abonnement corrigé d\'après Stripe',
                            ['component' => 'offers', 'action' => 'stripe_drift', 'from' => $before[0], 'to' => $after[0]]);
                    }
                    $off = $this->fulfillment->drift($subscription, $shop);
                    if ($off !== []) {
                        $this->fulfillment->resync($subscription, $shop);
                        ++$out['rightsFixed'];
                        $this->incidents->recordPlatformIssue($shop, 'offres/abonnement/' . $id, 'warning', 'Paiements des offres : droits corrigés d\'après l\'abonnement',
                            ['component' => 'offers', 'action' => 'rights_drift', 'features' => implode(',', $off), 'status' => $subscription->getStatus()]);
                    }
                });
            } catch (\Throwable $e) {
                ++$out['failed'];
                $this->logger->error(sprintf('[Offers] réconciliation de l\'abonnement %d en échec (%s)', (int) $id, $e::class));
                $this->payments->schedule($shop, (int) $id, $e::class);
                $em = $this->reopen($em, (string) $tenant['dbname'], $shop);
            }
        }

        return $out;
    }

    /** Un échec pendant un flush ferme l'EntityManager : il est rouvert pour les abonnements suivants */
    private function reopen(\Doctrine\ORM\EntityManagerInterface $em, string $dbname, string $code): \Doctrine\ORM\EntityManagerInterface
    {
        if (!$em->isOpen()) {
            $this->registry?->resetManager();
            $this->emProvider->switchTenant($dbname, $code);
        }

        return $this->emProvider->getEntityManager();
    }

    /** @param array{id: int, tenant_code: string, subscription_id: int, attempts: int, alerted: bool} $row */
    private function process(array $row): bool
    {
        try {
            $tenant = $this->tenants->findTenantByCode($row['tenant_code']);
            if ($tenant === null || empty($tenant['dbname'])) {
                throw new \RuntimeException('site inconnu');
            }
            $this->emProvider->switchTenant((string) $tenant['dbname'], $row['tenant_code']);
            $em = $this->emProvider->getEntityManager();
            $stripeId = $em->getConnection()->fetchOne('SELECT stripe_subscription_id FROM subscription WHERE id = ?', [$row['subscription_id']]);
            SubscriptionLock::run($em->getConnection(), SubscriptionLock::key($row['tenant_code'], is_string($stripeId) ? $stripeId : null, $row['subscription_id']), function () use ($em, $row) {
                $subscription = $em->getRepository(Subscription::class)->find($row['subscription_id']);
                if ($subscription === null) {
                    return; // abonnement effacé : rien à poser
                }
                $em->refresh($subscription);
                // Stripe injoignable : les droits sont tout de même recalculés depuis l'état local, puis la reprise compte l'échec
                $stripeError = null;
                if ($subscription->getStripeSubscriptionId() !== null) {
                    try {
                        $this->subscriptions->sync($subscription);
                    } catch (\Throwable $e) {
                        $stripeError = $e;
                    }
                }
                $this->fulfillment->resync($subscription, $row['tenant_code']);
                if ($stripeError !== null) {
                    throw $stripeError;
                }
            });
            $this->payments->retrySucceeded($row['id']);

            return true;
        } catch (\Throwable $e) {
            if (isset($tenant['dbname'])) {
                $this->reopen($this->emProvider->getEntityManager(), (string) $tenant['dbname'], $row['tenant_code']);
            }
            $attempts = $this->payments->retryFailed($row['id'], $e);
            $this->logger->error(sprintf('[Offers] reprise de l\'abonnement %d en échec, essai %d (%s)', $row['subscription_id'], $attempts, $e::class));
            if ($attempts >= self::ALERT_AFTER && !$row['alerted']) {
                $this->incidents->recordPlatformIssue($row['tenant_code'], 'offres/abonnement/' . $row['subscription_id'], 'error',
                    'Paiements des offres : droits non posés après 5 reprises', ['component' => 'offers', 'action' => 'retry_failed', 'attempts' => $attempts, 'exception' => $e::class]);
                $this->payments->markAlerted($row['id']);
            }

            return false;
        }
    }
}
