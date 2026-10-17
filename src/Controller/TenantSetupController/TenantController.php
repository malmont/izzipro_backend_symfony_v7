<?php

namespace App\Controller\TenantSetupController;

use App\Services\TenantConnectionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

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

        // 2. Recherche du site : même règle que toutes les autres routes (17/10/2026,
        // TenantConnectionManager::findTenantConfigByHost). L'ancienne recherche propre à cette route ne reconnaissait pas
        // le domaine du frontend lui-même : arkanoa-media.com était déclaré inexistant et le visiteur envoyé vers la
        // création de site du backend, alors que toutes les autres routes servaient le site de l'agence.
        try {
            $config = $tenantManager->findTenantConfigByHost($cleanHost);
        } catch (\Throwable $e) {
            return new JsonResponse(['status' => 'error', 'message' => 'Erreur critique connexion Master.'], 500);
        }
        $tenantData = $config ? ['code' => $config->getCode(), 'name' => $config->getName()] : null;

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

        // --- CAS B : site inconnu ---
        // Plus de proposition de création (17/10/2026, décision du propriétaire) : la réponse renvoyait le visiteur vers le
        // formulaire de création du backend. La création de site est réservée à l'administrateur de la plateforme :
        // bouton de la console d'édition du site de l'agence, formulaire /setup/new-store protégé par la clé de création.
        return new JsonResponse(['exists' => false, 'status' => 'not_found', 'message' => "Ce site n'existe pas."], 404);
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
     * Hôtes de tous les sites (17/10/2026), pour le serveur du frontend : il refuse tout autre hôte avant le rendu.
     * Publique, sans X-Tenant-Host, hôtes seulement (voir TenantPublicHostResolver::listPublicHosts). Cache d'une minute,
     * vidé avec le tag « tenants » (création d'un site). Jamais de liste vide : 503, le frontend garde sa liste.
     */
    #[Route('/api/tenant/hosts', name: 'api_tenant_hosts', methods: ['GET'])]
    public function hosts(\App\Services\TenantPublicHostResolver $resolver, TagAwareCacheInterface $cache): JsonResponse
    {
        try {
            $hosts = $cache->get('tenant_public_hosts', function (ItemInterface $item) use ($resolver): array {
                $item->expiresAfter(60);
                $item->tag(['tenants']);
                $hosts = $resolver->listPublicHosts();
                if ($hosts === []) {
                    throw new \RuntimeException('Aucun site dans la base maître');
                }

                return $hosts;
            });
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'HOSTS_UNAVAILABLE', 'message' => 'Liste des sites indisponible'], 503);
        }

        return new JsonResponse(['hosts' => $hosts]);
    }
}
