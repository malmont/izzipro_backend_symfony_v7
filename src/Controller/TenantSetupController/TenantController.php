<?php

namespace App\Controller\TenantSetupController;

use App\Services\TenantConnectionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class TenantController extends AbstractController
{
    public function __construct(
        private string $frontendBaseDomain 
    ) {}

    #[Route('/api/tenant/check', name: 'api_tenant_check', methods: ['GET', 'POST'])]
    public function check(Request $request, TenantConnectionManager $tenantManager): JsonResponse
    {
        // 1. Récupération du Host (Header prioritaire pour React, sinon Host standard)
        $host = $request->headers->get('X-Tenant-Host');
        if (!$host) {
            $host = $request->getHost();
        }
        
        $cleanHost = explode(':', $host)[0];

        // 2. Connexion au Master (Annuaire)
        try {
            $pdoMaster = $tenantManager->getPdoMaster();
        } catch (\Throwable $e) {
            return new JsonResponse(['status' => 'error', 'message' => 'Erreur critique connexion Master.'], 500);
        }

        // 3. Recherche du Tenant (Par Custom Domain OU par Code sous-domaine)
        $tenantData = null;

        try {
            $altHost = str_starts_with($cleanHost, 'www.') ? substr($cleanHost, 4) : 'www.' . $cleanHost;

            $stmt = $pdoMaster->prepare(
                'SELECT code, name FROM tenants WHERE custom_domain = :host OR custom_domain = :altHost'
            );
            $stmt->execute(['host' => $cleanHost, 'altHost' => $altHost]);
            $tenantData = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$tenantData) {
                $tenantCode = $this->extractSubdomainCode($cleanHost);
                
                if ($tenantCode) {
                    $stmt = $pdoMaster->prepare('SELECT code, name FROM tenants WHERE code = :code');
                    $stmt->execute(['code' => $tenantCode]);
                    $tenantData = $stmt->fetch(\PDO::FETCH_ASSOC);
                }
            }

        } catch (\Throwable $e) {
            return new JsonResponse(['status' => 'error', 'message' => 'Erreur SQL verification.'], 500);
        }

        // --- CAS A : Le Tenant EXISTE ---
        if ($tenantData) {
            return new JsonResponse([
                'exists' => true,
                'status' => 'found',
                'action' => 'login',
                'tenant' => [
                    'code' => $tenantData['code'],
                    'name' => $tenantData['name'] ?? 'Boutique'
                ]
            ]);
        }


        $isPlatformSubdomain = str_ends_with($cleanHost, $this->frontendBaseDomain);
        
        if ($cleanHost === 'localhost' || $cleanHost === '127.0.0.1') {
            $isPlatformSubdomain = true; 
        }

        if ($isPlatformSubdomain) {

            return new JsonResponse([
                'exists' => false,
                'status' => 'not_found',
                'action' => 'redirect_create',
                'message' => "Cette boutique n'existe pas encore. Voulez-vous la créer ?",
                'setup_url' => '/setup/new-store' // URL vers ton formulaire React
            ], 404);
        } else {

            return new JsonResponse([
                'exists' => false,
                'status' => 'error',
                'action' => 'block',
                'message' => "Ce nom de domaine n'est associé à aucune boutique active sur notre plateforme. Veuillez d'abord créer votre site via un sous-domaine {$this->frontendBaseDomain}."
            ], 404);
        }
    }

    /**
     * Helper pour extraire "boutique" de "boutique.mon-saas.com"
     */
    private function extractSubdomainCode(string $host): ?string
    {
        if ($host === 'localhost' || $host === '127.0.0.1') {
            return null;
        }

        if (str_ends_with($host, $this->frontendBaseDomain)) {
            $prefix = substr($host, 0, -strlen($this->frontendBaseDomain));
            return rtrim($prefix, '.');
        }

        $parts = explode('.', $host);
        if (count($parts) >= 3) {
            return $parts[0] === 'www' ? ($parts[1] ?? null) : $parts[0];
        }

        return null;
    }
}