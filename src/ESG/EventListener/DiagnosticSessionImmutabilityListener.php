<?php

namespace App\ESG\EventListener;

use App\ESG\Entity\DiagnosticSession;
use App\ESG\Enum\SessionStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: DiagnosticSession::class)]
class DiagnosticSessionImmutabilityListener
{
    public function preUpdate(DiagnosticSession $session, PreUpdateEventArgs $args): void
    {
        if ($args->hasChangedField('status')) {
            $oldStatus = $args->getOldValue('status');
            $newStatus = $args->getNewValue('status');

            // Handle when status is parsed as an Enum or string
            $oldStatusStr = $oldStatus instanceof SessionStatusEnum ? $oldStatus->value : (string) $oldStatus;
            $newStatusStr = $newStatus instanceof SessionStatusEnum ? $newStatus->value : (string) $newStatus;

            if ($oldStatusStr === SessionStatusEnum::COMPLETED->value && $newStatusStr === SessionStatusEnum::IN_PROGRESS->value) {
                throw new \LogicException('Une session COMPLETED est définitivement immuable et ne peut pas repasser en cours.');
            }
        }
    }
}
