<?php

namespace App\Controller\TenantSetupController;

use App\Services\TenantConnectionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class TenantController extends AbstractController
{
    private string $frontendBaseDomain;

    // On injecte le domaine de base pour pouvoir valider les sous-domaines correctement
    public function __construct(string $frontendBaseDomain)
    {
        $this->frontendBaseDomain = $frontendBaseDomain;
    }

    #[Route('/api/tenant/check', name: 'api_tenant_check', methods: ['GET'])]
    public function check(Request $request, TenantConnectionManager $tenantManager): JsonResponse
    {
        // 1. Récupération du Host (Priorité au Header envoyé par le Front React)
        $host = $request->headers->get('X-Tenant-Host');

        if (!$host) {
            $host = $request->getHost();
        }
        
        // 2. Connexion PDO Master
        try {
            $pdoMaster = $tenantManager->getPdoMaster();
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Erreur connexion BDD Master.'], 500);
        }

        $tenantExists = false;

        // 3. PRIORITÉ 1 : Vérification DOMAINE PERSONNALISÉ
        try {
            $altHost = $host;
            if (str_starts_with($host, 'www.')) {
                $altHost = substr($host, 4); 
            } else {
                $altHost = 'www.' . $host;
            }

            // On vérifie si ce domaine existe dans la colonne custom_domain
            $stmt = $pdoMaster->prepare(
                'SELECT 1 FROM tenants WHERE custom_domain = :host OR custom_domain = :altHost'
            );
            $stmt->execute(['host' => $host, 'altHost' => $altHost]);
            
            if ($stmt->fetch()) {
                $tenantExists = true;
            }

        } catch (\Throwable $e) {
             // On ne bloque pas, on passe à la suite (log en prod conseillé)
        }


        // 4. PRIORITÉ 2 : Vérification SOUS-DOMAINE
        if (!$tenantExists) {
            $tenantCode = null;
            
            // On nettoie les domaines pour éviter les erreurs de port (ex: localhost:3000)
            $cleanHost = explode(':', $host)[0];
            $cleanBase = explode(':', $this->frontendBaseDomain)[0];

            // Si le host finit par le domaine principal (ex: boutique.gem-portal.com)
            if (str_ends_with($cleanHost, $cleanBase) && $cleanHost !== $cleanBase) {
                
                // Extraction "brute" du premier segment
                $hostParts = explode('.', $cleanHost);
                
                // Gestion basique : si www.boutique.domaine.com -> boutique
                if ($hostParts[0] === 'www' && isset($hostParts[1])) {
                    $tenantCode = $hostParts[1];
                } else {
                    $tenantCode = $hostParts[0];
                }
            }
            
            // Si on a isolé un code potentiel, on vérifie s'il existe en BDD
            if ($tenantCode) {
                 try {
                    $stmt = $pdoMaster->prepare('SELECT 1 FROM tenants WHERE code = :code');
                    $stmt->execute(['code' => $tenantCode]);
                    
                    if ($stmt->fetch()) {
                        $tenantExists = true;
                    }
                } catch (\Throwable $e) {
                    return new JsonResponse(['error' => 'Erreur vérification sous-domaine.'], 500);
                }
            }
        }

        return new JsonResponse(['exists' => $tenantExists]);
    }
}