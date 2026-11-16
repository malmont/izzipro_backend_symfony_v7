<?php

namespace App\MessageHandler;

use App\Entity\Subscription;
use App\Message\CancelPlatformSubscriptionMessage;
use App\Services\Messenger\WorkerContext;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\SubscriptionService\SubscriptionService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lot MOD-1 : résilie à la fin de la période l'abonnement désigné par la référence d'un droit (`subscription:<site>:<numéro>`). Se
 * place sur le site vendeur, puis revient au site d'avant. Hors d'un worker (transport en direct), il ne bascule jamais une connexion
 * en plein travail : il n'agit que si le site vendeur est déjà le site courant, sinon il le journalise. Jamais d'exception vers la file :
 * un abonnement introuvable ou déjà résilié est journalisé.
 */
#[AsMessageHandler]
final class CancelPlatformSubscriptionHandler
{
    public function __construct(
        private readonly TenantDatabaseConnector $connector,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly SubscriptionService $subscriptions,
        private readonly LoggerInterface $logger,
        private readonly ?WorkerContext $worker = null,
    ) {
    }

    public function __invoke(CancelPlatformSubscriptionMessage $message): void
    {
        if (preg_match('/^subscription:([a-z0-9][a-z0-9_-]*):(\d+)$/', $message->reference, $m) !== 1) {
            $this->logger->warning('[Entitlements] résiliation : référence d\'abonnement illisible');

            return;
        }
        [, $code, $id] = $m;
        $tenant = $this->connector->tenant($code);
        if ($tenant === null) {
            $this->logger->warning(sprintf('[Entitlements] résiliation : site vendeur %s introuvable', $code));

            return;
        }
        $current = (string) $this->tenants->getCurrentTenantCode();
        $previous = null;
        if ($current !== $code) {
            if ($this->worker?->inWorker() !== true) {
                $this->logger->warning(sprintf('[Entitlements] résiliation de l\'abonnement %s : hors d\'un worker, aucune bascule vers %s', $id, $code));

                return;
            }
            $previous = $current !== '' ? [(string) ($this->emProvider->getEntityManager()->getConnection()->getParams()['dbname'] ?? ''), $current] : null;
            $this->emProvider->switchTenant((string) $tenant['dbname'], (string) $tenant['code']);
        }
        try {
            $subscription = $this->emProvider->getEntityManager()->find(Subscription::class, (int) $id);
            if (!$subscription instanceof Subscription || $subscription->getStatus() === Subscription::STATUS_CANCELED) {
                $this->logger->notice(sprintf('[Entitlements] résiliation : abonnement %s de %s introuvable ou déjà résilié', $id, $code));

                return;
            }
            $this->subscriptions->cancel($subscription, true);
            $this->logger->notice(sprintf('[Entitlements] abonnement %s de %s résilié à la fin de la période (droit retiré par %s)', $id, $code, $message->by ?? 'la plateforme'));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Entitlements] résiliation de l\'abonnement %s de %s impossible (%s)', $id, $code, $e::class));
        } finally {
            if ($previous !== null && $previous[0] !== '') {
                $this->emProvider->switchTenant($previous[0], $previous[1]);
            }
        }
    }
}
