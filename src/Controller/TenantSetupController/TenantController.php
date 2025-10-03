<?php

namespace App\Controller\TenantSetupController;

use App\Services\TenantConnectionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class TenantController extends AbstractController
{

    #[Route('/api/tenant/check', name: 'api_tenant_check', methods: ['GET'])]
    public function check(Request $request, TenantConnectionManager $tenantManager): JsonResponse
    {
        // 1. On récupère le sous-domaine (qui correspond à votre 'code' de tenant)
        $host = $request->getHost();
        $subdomain = explode('.', $host)[0];
        
        // Si le sous-domaine est vide ou est 'www', on considère qu'il n'existe pas
        if (empty($subdomain) || in_array($subdomain, ['www', 'app', 'api'])) {
             return new JsonResponse(['exists' => false]);
        }

        try {
            // 2. On récupère la connexion PDO directe à la base MASTER depuis votre service
            $pdoMaster = $tenantManager->getPdoMaster();

            // 3. On prépare et exécute une requête sécurisée pour vérifier l'existence
            $stmt = $pdoMaster->prepare('SELECT COUNT(*) FROM tenants WHERE code = :code');
            $stmt->execute(['code' => $subdomain]);
            
            $count = (int) $stmt->fetchColumn();

            // 4. On retourne la réponse
            return new JsonResponse(['exists' => ($count > 0)]);

        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Impossible de vérifier le tenant.'], 500);
        }
    }
}