<?php

namespace App\EventListener;

use App\Services\TenantConnectionProvider;
use App\Services\TenantConnectionManager;
use Symfony\Component\HttpFoundation\JsonResponse;
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

        // Routes qui reçoivent volontairement un site inconnu : elles répondent elles-mêmes (vérification, création)
        $excludedPaths = ['/api/tenant/check', '/api/tenant/check-mobile', '/setup/new-store'];
        if (in_array($request->getPathInfo(), $excludedPaths)) {
            return;
        }

        $host = $request->headers->get('X-Tenant-Host');
        // Site demandé explicitement (en-tête ou paramètre) : inconnu, la requête est refusée (voir plus bas)
        $explicit = (bool) $host;

        // Support du paramètre ?tenant= ou ?t= pour l'accès direct à EasyAdmin dans le navigateur
        if (!$host) {
            $tenantQuery = $request->query->get('tenant') ?? $request->query->get('t');
            if ($tenantQuery) {
                $host = $tenantQuery;
                $explicit = true;
                if ($request->hasSession()) {
                    $request->getSession()->set('_active_admin_tenant', $tenantQuery);
                }
            }
        }

        // Si aucun header/paramètre, vérifier si un tenant est déjà sélectionné en session
        if (!$host && $request->hasSession() && $request->getSession()->get('_active_admin_tenant')) {
            $host = $request->getSession()->get('_active_admin_tenant');
        }

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
            // Site demandé mais inconnu (17/10/2026) : 404 au lieu de servir la base maître. Des robots essayaient des
            // sous-domaines au hasard sous *.arkanoa-media.com et recevaient les données de cette base. Le domaine du
            // backend et ses sous-domaines, la sélection gardée en session et les requêtes sans site ne changent pas.
            if ($explicit && !$this->isBackendHost((string) $host)) {
                $event->setResponse(new JsonResponse(['error' => 'TENANT_NOT_FOUND', 'message' => 'Site inconnu'], 404));
            }

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

    private function isBackendHost(string $host): bool
    {
        $host = strtolower(explode(':', $host)[0]);

        return $host === $this->backendMainDomain || str_ends_with($host, '.' . $this->backendMainDomain);
    }
}
