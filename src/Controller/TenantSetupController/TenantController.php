<?php

namespace App\Controller\TenantSetupController;

use App\Services\TenantConnectionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class TenantController extends AbstractController
{
    // On garde l'injection pour la forme, mais on utilise une logique plus dynamique
    public function __construct(
        private string $frontendBaseDomain 
    ) {}

    #[Route('/api/tenant/check', name: 'api_tenant_check', methods: ['GET'])]
    public function check(Request $request, TenantConnectionManager $tenantManager): JsonResponse
    {
        // 1. Récupération du Host (Priorité au Header envoyé par le Front React)
        // C'est ça qui permet au Front et au Back d'être sur des domaines différents
        $host = $request->headers->get('X-Tenant-Host');

        if (!$host) {
            $host = $request->getHost();
        }
        
        // Nettoyage du port éventuel (ex: localhost:3000 -> localhost)
        $cleanHost = explode(':', $host)[0];

        // 2. Connexion PDO Master
        try {
            $pdoMaster = $tenantManager->getPdoMaster();
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Erreur connexion BDD Master.'], 500);
        }

        $tenantExists = false;

        // --- PRIORITÉ 1 : DOMAINE PERSONNALISÉ (ex: www.dailydrip.ca) ---
        try {
            $altHost = str_starts_with($cleanHost, 'www.') ? substr($cleanHost, 4) : 'www.' . $cleanHost;

            $stmt = $pdoMaster->prepare(
                'SELECT 1 FROM tenants WHERE custom_domain = :host OR custom_domain = :altHost'
            );
            $stmt->execute(['host' => $cleanHost, 'altHost' => $altHost]);
            
            if ($stmt->fetch()) {
                $tenantExists = true;
            }
        } catch (\Throwable $e) {
             // On continue, ce n'est peut-être pas un domaine custom
        }

        // --- PRIORITÉ 2 : SOUS-DOMAINE (ex: testmessenger.gem-portal.ca) ---
        if (!$tenantExists) {
            $tenantCode = null;
            
            // Logique universelle : ne dépend plus strictement de la variable d'env
            if ($cleanHost === 'localhost' || $cleanHost === '127.0.0.1') {
                $tenantCode = 'localtest'; 
            } else {
                // On découpe le domaine par les points
                $parts = explode('.', $cleanHost);
                
                // Si on a au moins 3 parties (ex: boutique.domaine.com)
                // C'est un sous-domaine SaaS classique
                if (count($parts) >= 3) {
                    // Si le premier n'est pas 'www', c'est notre code
                    if ($parts[0] !== 'www') {
                        $tenantCode = $parts[0];
                    } 
                    // Si c'est 'www.boutique.domaine.com', le code est en 2ème position
                    elseif (isset($parts[1])) {
                        $tenantCode = $parts[1];
                    }
                }
            }
            
            // Si on a trouvé un code potentiel, on vérifie s'il existe vraiment en BDD
            if ($tenantCode && !in_array($tenantCode, ['api', 'admin', 'backend', 'www'])) {
                 try {
                    // On vérifie si le CODE ou le DBNAME existe
                    $stmt = $pdoMaster->prepare('SELECT 1 FROM tenants WHERE code = :code OR dbname = :dbname');
                    $stmt->execute([
                        'code' => $tenantCode,
                        'dbname' => 'db_' . $tenantCode
                    ]);
                    
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