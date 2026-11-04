<?php

namespace App\EventSubscriber;

use App\Entity\Subscription;
use App\Event\SubscriptionStatusChangedEvent;
use App\Services\TenantConnectionProvider;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Cycle de vie des abonnements (console de pilotage, lot 3 bis, 04/11/2026) : à chaque flush, les abonnements dont le statut ou la
 * fin de période change sont relevés (onFlush, jeu de modifications) puis, une fois écrits (postFlush), un
 * SubscriptionStatusChangedEvent est émis pour chacun. Aucun des six endroits qui écrivent le statut (souscription, webhooks,
 * résiliation, pause, reprise, synchronisation) n'a à connaître les services branchés. Enregistré par config/services.yaml.
 */
class SubscriptionLifecycleSubscriber implements EventSubscriber
{
    /** @var list<array{subscription: Subscription, from: string, to: string, previousEnd: ?\DateTimeImmutable}> */
    private array $pending = [];

    public function __construct(private readonly EventDispatcherInterface $dispatcher, private readonly TenantConnectionProvider $tenant, private readonly LoggerInterface $logger)
    {
    }

    public function getSubscribedEvents(): array
    {
        return [Events::onFlush, Events::postFlush];
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Subscription) {
                continue;
            }
            $changes = $uow->getEntityChangeSet($entity);
            if (!isset($changes['status']) && !isset($changes['currentPeriodEnd'])) {
                continue;
            }
            $this->pending[] = [
                'subscription' => $entity,
                'from' => (string) ($changes['status'][0] ?? $entity->getStatus()),
                'to' => (string) ($changes['status'][1] ?? $entity->getStatus()),
                'previousEnd' => isset($changes['currentPeriodEnd']) ? ($changes['currentPeriodEnd'][0] instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($changes['currentPeriodEnd'][0]) : null) : $entity->getCurrentPeriodEnd(),
            ];
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pending === []) {
            return;
        }
        $batch = $this->pending;
        $this->pending = [];
        foreach ($batch as $item) {
            $s = $item['subscription'];
            $plan = $s->getPlan();
            try {
                $this->dispatcher->dispatch(new SubscriptionStatusChangedEvent(
                    (int) $s->getId(), $this->tenant->getTenantCode(), $plan?->getFulfillmentType() ?? 'none', $plan?->getFulfillmentRef(), $s->getFulfillmentTarget(),
                    $item['from'], $item['to'], $s->getCurrentPeriodEnd(), $item['previousEnd'],
                ));
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('[Subscription] événement de cycle de vie de l\'abonnement %d non traité : %s', (int) $s->getId(), $e->getMessage()));
            }
        }
    }
}
