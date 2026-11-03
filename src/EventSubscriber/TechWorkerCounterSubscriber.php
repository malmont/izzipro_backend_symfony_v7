<?php

namespace App\EventSubscriber;

use App\Services\TechMetricsService\TechCounters;
use App\Services\TenantConnectionManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Compte les tâches Messenger traitées avec succès, par site, par file et par heure (tableau de bord technique, T1, 03/11/2026).
 * Les gestionnaires basculent sur le site du message avant de travailler : le site courant au moment du succès est celui de la tâche.
 * La file `email` compte les courriels envoyés (un message = un envoi réussi). Aucun gestionnaire modifié ; les workers doivent
 * être relancés pour charger cet abonné (messenger:stop-workers).
 */
class TechWorkerCounterSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TechCounters $counters,
        private readonly TenantConnectionManager $tenants,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageHandledEvent::class => 'onHandled'];
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        try {
            $site = $this->tenants->getCurrentTenantCode();
        } catch (\Throwable) {
            $site = null;
        }
        $this->counters->countJob($site, $event->getReceiverName());
    }
}
