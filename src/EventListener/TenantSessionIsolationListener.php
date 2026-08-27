<?php
// src/EventListener/TenantSessionIsolationListener.php

namespace App\EventListener;

use App\Services\TenantConnectionProvider;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Psr\Log\LoggerInterface;

/**
 * Assure que la session PHP ne "fuit" pas d'un tenant à l'autre.
 */
class TenantSessionIsolationListener
{
    private TenantConnectionProvider $tenantProvider;
    private LoggerInterface $logger;

    public function __construct(TenantConnectionProvider $tenantProvider, LoggerInterface $logger)
    {
        $this->tenantProvider = $tenantProvider;
        $this->logger = $logger;
    }

    public function onKernelRequest(RequestEvent $event)
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        
        // On vérifie si une session est active sans la démarrer si elle ne l'est pas
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        
        // On récupère le tenant stocké en session lors du login
        $sessionTenant = $session->get('_tenant_code');

        if (!$sessionTenant) {
            return;
        }

        // On récupère le tenant actuel de la requête (déterminé par le switcher)
        $currentTenant = $this->tenantProvider->getTenantCode();

        if ($currentTenant && $sessionTenant !== $currentTenant) {
            $this->logger->warning("Session Isolation Mismatch: Session for tenant '$sessionTenant' used on tenant '$currentTenant'. Ignoring session for this request.");
            
            // On ne détruit plus la session (pour ne pas déconnecter les autres onglets)
            // On se contente de vider les données de sécurité pour cette requête si nécessaire
            $session->remove('_security_main'); 
        }
    }
}
