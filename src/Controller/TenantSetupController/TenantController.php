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
        private string $frontendBaseDomain,
        private string $backendBaseDomain // Nouvelle injection
    ) {}

    #[Route('/api/tenant/check', name: 'api_tenant_check', methods: ['GET', 'POST'])]
    public function check(Request $request, TenantConnectionManager $tenantManager): JsonResponse
    {
        // 1. Récupération du Host
        $host = $request->headers->get('X-Tenant-Host');
        if (!$host) {
            $host = $request->getHost();
        }
        
        // Nettoyage du port (ex: localhost:3000 -> localhost)
        $cleanHost = explode(':', $host)[0];

        // 2. Connexion au Master (Annuaire)
        try {
            $pdoMaster = $tenantManager->getPdoMaster();
        } catch (\Throwable $e) {
            return new JsonResponse(['status' => 'error', 'message' => 'Erreur critique connexion Master.'], 500);
        }

        // 3. Recherche du Tenant
        $tenantData = null;

        try {
            $altHost = str_starts_with($cleanHost, 'www.') ? substr($cleanHost, 4) : 'www.' . $cleanHost;

            // Recherche par domaine personnalisé
            $stmt = $pdoMaster->prepare(
                'SELECT code, name FROM tenants WHERE custom_domain = :host OR custom_domain = :altHost'
            );
            $stmt->execute(['host' => $cleanHost, 'altHost' => $altHost]);
            $tenantData = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Recherche par sous-domaine (code)
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

        // --- CAS B : Le Tenant N'EXISTE PAS ---

        // Vérification si c'est un sous-domaine valide de la plateforme (Prod)
        $isPlatformSubdomain = str_ends_with($cleanHost, $this->frontendBaseDomain);
        
        // AJOUT : Vérification pour le développement (Localhost et sous-domaines .localhost)
        if (!$isPlatformSubdomain) {
            if ($cleanHost === 'localhost' || $cleanHost === '127.0.0.1' || str_ends_with($cleanHost, '.localhost')) {
                $isPlatformSubdomain = true; 
            }
        }

        if ($isPlatformSubdomain) {
            
            $protocol = ($cleanHost === 'localhost' || str_ends_with($cleanHost, '.localhost')) ? 'http://' : 'https://';
            
            $currentSubdomain = $this->extractSubdomainCode($cleanHost);

            if ($currentSubdomain && $currentSubdomain !== 'www') {
                $targetDomain = $currentSubdomain . '.' . $this->backendBaseDomain;
            } else {
                $targetDomain = $this->backendBaseDomain;
            }

            $absoluteSetupUrl = $protocol . $targetDomain . '/setup/new-store';

            return new JsonResponse([
                'exists' => false,
                'status' => 'not_found',
                'action' => 'redirect_create',
                'message' => "Cette boutique n'existe pas encore. Voulez-vous la créer ?",
                'setup_url' => $absoluteSetupUrl 
            ], 404);
        }
    }

    /**
     * Helper pour extraire "boutique" de "boutique.mon-saas.com" ou "boutique.localhost"
     */
    private function extractSubdomainCode(string $host): ?string
    {
        if ($host === 'localhost' || $host === '127.0.0.1') {
            return null; // Pas de sous-domaine sur la racine locale
        }

        // Cas Production
        if (str_ends_with($host, $this->frontendBaseDomain)) {
            $prefix = substr($host, 0, -strlen($this->frontendBaseDomain));
            return rtrim($prefix, '.');
        }

        // Cas Développement (.localhost)
        if (str_ends_with($host, '.localhost')) {
            $prefix = substr($host, 0, -strlen('.localhost'));
            return rtrim($prefix, '.');
        }

        // Fallback générique (ex: www.boutique.com)
        $parts = explode('.', $host);
        if (count($parts) >= 3) {
            return $parts[0] === 'www' ? ($parts[1] ?? null) : $parts[0];
        }

        return null;
    }
}