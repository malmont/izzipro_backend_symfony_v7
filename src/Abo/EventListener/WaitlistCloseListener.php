<?php

namespace App\Abo\EventListener;

use App\Abo\Entity\BenefitSlot;
use App\Abo\Entity\PlanBenefit;
use App\Abo\Event\SlotFreedEvent;
use App\Abo\Services\AboWaitlist;
use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Lot ABO-4 (21/11/2026) : les écritures faites par l'ORM (statut d'un abonnement par le webhook, la pause ou la résiliation ; prestation
 * retirée d'une formule ; créneau modifié ou supprimé dans EasyAdmin) ferment les attentes qui n'ont plus lieu d'être
 * (`AboWaitlist::closeStale`), quel que soit le chemin. Une capacité de créneau changée propose la place à la liste d'attente
 * (`SlotFreedEvent`, après l'écriture). Jamais bloquant : une panne ici n'empêche pas l'écriture (le passage horaire rattrape).
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class WaitlistCloseListener
{
    private bool $stale = false;
    /** @var list<int> */
    private array $resized = [];

    public function __construct(private readonly ?EventDispatcherInterface $events = null, private readonly ?LoggerInterface $logger = null)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Subscription) {
                $changes = $uow->getEntityChangeSet($entity);
                if (isset($changes['status']) || isset($changes['cancelAt']) || isset($changes['cancelAtPeriodEnd']) || isset($changes['plan'])) {
                    $this->stale = true;
                }
            } elseif ($entity instanceof BenefitSlot) {
                $changes = $uow->getEntityChangeSet($entity);
                if (isset($changes['status']) || isset($changes['startsAt'])) {
                    $this->stale = true;
                }
                if (isset($changes['capacity']) && $entity->getId() !== null) {
                    $this->resized[] = (int) $entity->getId();
                }
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof PlanBenefit || $entity instanceof BenefitSlot || $entity instanceof Subscription) {
                $this->stale = true;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        [$stale, $resized] = [$this->stale, $this->resized];
        $this->stale = false;
        $this->resized = [];
        if ($stale) {
            try {
                $conn = $args->getObjectManager()->getConnection();
                if ($conn->fetchOne("SELECT to_regclass('benefit_waitlist')") !== null) {
                    AboWaitlist::closeStale($conn);
                }
            } catch (\Throwable $e) {
                $this->logger?->error('[ABO-4] Fermeture des attentes en panne (' . $e::class . ') : le passage horaire la rattrape.');
            }
        }
        foreach (array_unique($resized) as $slotId) {
            $this->events?->dispatch(new SlotFreedEvent($slotId));
        }
    }
}
