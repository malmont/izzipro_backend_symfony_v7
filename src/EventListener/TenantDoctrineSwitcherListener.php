<?php

namespace App\EventListener;

use App\Services\TenantConnectionProvider;
use App\Services\TenantConnectionManager;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Psr\Log\LoggerInterface;

class TenantDoctrineSwitcherListener
{
    public function __construct(
        private TenantConnectionProvider $tenantConnectionProvider,
        private TenantConnectionManager $tenantManager,
        private LoggerInterface $logger,
        private string $backendMainDomain
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $excludedPaths = ['/api/tenant/check', '/setup/new-store'];
        if (in_array($request->getPathInfo(), $excludedPaths)) {
            return;
        }

        $host = $request->headers->get('X-Tenant-Host');

        if (!$host) {
            $host = $request->getHost();
        }


        $tenantConfig = $this->tenantManager->findTenantConfigByHost($host);


        if (!$tenantConfig && $host === $this->backendMainDomain) {
             $tenantConfig = $this->tenantManager->findTenantConfigByHost('tenantdefaut');
             if ($tenantConfig) {
                 // $this->logger->info("Backend Admin détecté, switch vers tenantdefaut.");
             }
        }


        if (!$tenantConfig) {
            return;
        }

        // On récupère la configuration ACTUELLE du Provider
        $currentDb = $this->tenantConnectionProvider->getConnection()->getParams()['dbname'] ?? null;
        $currentCode = $this->tenantConnectionProvider->getTenantCode();

        // On récupère la configuration CIBLE
        $targetDb = $tenantConfig->getDbname();
        $targetCode = $tenantConfig->getCode();

        // (Cela couvre le cas où on est déjà sur la DB Master au démarrage mais que le code est null)
        if ($targetDb !== $currentDb || $targetCode !== $currentCode) {
            $this->tenantConnectionProvider->switchTenant($targetDb, $targetCode);
            // $this->logger->info("Switch tenant OK : Code=$targetCode / DB=$targetDb");
        }
    }
}