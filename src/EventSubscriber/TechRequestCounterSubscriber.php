<?php

namespace App\EventSubscriber;

use App\Services\TechMetricsService\TechCounters;
use App\Services\TenantConnectionManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Compte chaque requête web par site et par heure (tableau de bord technique, T1, 03/11/2026) : nombre, durée cumulée, pic mémoire
 * maximal, erreurs 4xx et 5xx. Après l'envoi de la réponse (événement de terminaison, comme RequestPeakLogSubscriber) : l'utilisateur
 * n'attend jamais l'incrément, dont le coût est une poignée de commandes Redis groupées. Aucun chemin, aucun paramètre, aucune donnée
 * personnelle : seulement le code du site. Redis indisponible → rien.
 */
class TechRequestCounterSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TechCounters $counters,
        private readonly TenantConnectionManager $tenants,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => ['onTerminate', -1000]];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $started = (float) $request->server->get('REQUEST_TIME_FLOAT', microtime(true));
        try {
            $site = $this->tenants->getCurrentTenantCode();
        } catch (\Throwable) {
            $site = null;
        }
        $this->counters->countRequest(
            $site,
            (int) round((microtime(true) - $started) * 1000),
            (int) round(memory_get_peak_usage(true) / 1048576),
            $event->getResponse()->getStatusCode(),
        );
    }
}
