<?php

namespace App\Services\Messenger;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * Dit si le processus courant est un worker Messenger (`messenger:consume`). Un gestionnaire qui doit changer de site
 * (`switchTenant`) ne le fait que là : dans une requête, ou dans un traitement en direct hors requête (commande, test), changer de
 * site détacherait les entités de l'appelant au milieu de son travail (lot NOTIF-2, 15/11/2026).
 */
final class WorkerContext implements EventSubscriberInterface
{
    private bool $inWorker = false;

    public static function getSubscribedEvents(): array
    {
        return [WorkerStartedEvent::class => 'onWorkerStarted'];
    }

    public function onWorkerStarted(): void
    {
        $this->inWorker = true;
    }

    public function inWorker(): bool
    {
        return $this->inWorker;
    }

    /** Pour les tests : jouer un gestionnaire comme dans un worker */
    public function simulate(bool $inWorker): void
    {
        $this->inWorker = $inWorker;
    }
}
