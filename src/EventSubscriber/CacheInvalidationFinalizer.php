<?php

namespace App\EventSubscriber;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Second passage de l'invalidation du cache (26/10/2026) : en fin de requête (après l'envoi de la réponse), de commande
 * et de message Messenger, revide le cache des sites écrits pendant ce traitement. Couvre l'écriture faite dans une
 * transaction validée après le flush. Voir CacheInvalidationSubscriber.
 */
class CacheInvalidationFinalizer implements EventSubscriberInterface
{
    public function __construct(private CacheInvalidationSubscriber $invalidation)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'finalize',
            ConsoleEvents::TERMINATE => 'finalize',
            WorkerMessageHandledEvent::class => 'finalize',
            WorkerMessageFailedEvent::class => 'finalize',
        ];
    }

    public function finalize(): void
    {
        $this->invalidation->invalidateWrittenTenants();
    }
}
