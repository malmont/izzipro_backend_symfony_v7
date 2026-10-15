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

        // Vérification si c'est un sous-domaine valide de la plateforme (Frontend ou Backend V7)
        $isPlatformSubdomain = str_ends_with($cleanHost, $this->frontendBaseDomain) 
                            || str_ends_with($cleanHost, $this->backendBaseDomain);

        // AJOUT : Vérification pour le développement (Localhost et sous-domaines .localhost)
        if (!$isPlatformSubdomain) {
            if ($cleanHost === 'localhost' || $cleanHost === '127.0.0.1' || str_ends_with($cleanHost, '.localhost')) {
                $isPlatformSubdomain = true;
            }
        }

        if ($isPlatformSubdomain) {

            $isLocal = ($cleanHost === 'localhost' || str_ends_with($cleanHost, '.localhost'));
            $protocol = $isLocal ? 'http://' : 'https://';

            $currentSubdomain = $this->extractSubdomainCode($cleanHost);

            if ($isLocal) {
                $port = $request->getPort();
                $portSuffix = ($port && !in_array($port, [80, 443])) ? ':' . $port : '';
                if ($currentSubdomain && $currentSubdomain !== 'www') {
                    $targetDomain = $currentSubdomain . '.localhost' . $portSuffix;
                } else {
                    $targetDomain = 'localhost' . $portSuffix;
                }
            } else {
                if ($currentSubdomain && $currentSubdomain !== 'www') {
                    $absoluteSetupUrl = $protocol . $this->backendBaseDomain . '/setup/new-store?subdomain=' . urlencode($currentSubdomain);
                } else {
                    $absoluteSetupUrl = $protocol . $this->backendBaseDomain . '/setup/new-store';
                }
            }

            return new JsonResponse([
                'exists' => false,
                'status' => 'not_found',
                'action' => 'redirect_create',
                'message' => "Cette boutique n'existe pas encore. Voulez-vous la créer ?",
                'setup_url' => $absoluteSetupUrl
            ], 404);
        }

        return new JsonResponse([
            'exists' => false,
            'status' => 'error',
            'message' => 'Domaine non reconnu.'
        ], 400);
    }

    /**
     * Choix du site par les applications mobiles (15/10/2026) : X-Tenant-Host = saisie de l'utilisateur, nettoyée par
     * l'application (code « demo » ou domaine « boutique.ca »). Résolution identique à celle de toutes les requêtes
     * suivantes (TenantConnectionManager::findTenantConfigByHost, lecture seule). Rend l'hôte COMPLET à envoyer ensuite
     * dans X-Tenant-Host : le domaine propre du site, sinon <code>.<domaine de base du frontend> (les liens des e-mails
     * sont construits avec cette valeur : un code nu donnerait des liens faux). Route distincte de check(), celle du
     * frontend web, qui n'est pas modifiée. Pas de redirection vers la création d'un site.
     */
    #[Route('/api/tenant/check-mobile', name: 'api_tenant_check_mobile', methods: ['GET'])]
    public function checkMobile(Request $request, TenantConnectionManager $tenantManager, \App\Services\TenantPublicHostResolver $hosts): JsonResponse
    {
        $input = strtolower(trim((string) $request->headers->get('X-Tenant-Host', '')));
        $input = explode(':', explode('/', preg_replace('#^https?://#', '', $input))[0])[0]; // tolérance : schéma, chemin, port
        if ($input === '') {
            return new JsonResponse(['error' => 'TENANT_HOST_MISSING', 'message' => 'Indiquez le code ou le domaine de votre boutique'], 400);
        }

        $config = $tenantManager->findTenantConfigByHost($input);
        if (!$config) {
            return new JsonResponse(['exists' => false, 'error' => 'TENANT_NOT_FOUND', 'message' => 'Boutique introuvable'], 404);
        }

        try {
            $tenant = $hosts->describe($config->getCode());
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'MASTER_UNAVAILABLE', 'message' => 'Annuaire des sites indisponible'], 500);
        }
        if (!$tenant) {
            return new JsonResponse(['exists' => false, 'error' => 'TENANT_NOT_FOUND', 'message' => 'Boutique introuvable'], 404);
        }

        return new JsonResponse(['exists' => true, 'tenant' => $tenant]);
    }

    /**
     * Helper pour extraire "boutique" de "boutique.mon-saas.com" ou "boutique.localhost"
     */
    private function extractSubdomainCode(string $host): ?string
    {
        if ($host === 'localhost' || $host === '127.0.0.1') {
            return null; // Pas de sous-domaine sur la racine locale
        }

        // Cas Production Backend V7
        if (str_ends_with($host, $this->backendBaseDomain)) {
            $prefix = substr($host, 0, -strlen($this->backendBaseDomain));
            return rtrim($prefix, '.');
        }

        // Cas Production Frontend
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
