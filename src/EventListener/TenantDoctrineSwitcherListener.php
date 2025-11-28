<?php

namespace App\EventListener;

use App\Services\TenantConnectionProvider;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Psr\Log\LoggerInterface;

/**
 * Listener "Bilingue" : Gère le tenant depuis le header OU le host.
 *
 * 1. (Priorité) Lit le header 'X-Tenant-Host' (envoyé par le frontend React).
 * 2. (Fallback) S'il est absent, lit le host de la requête (pour EasyAdmin, Postman...).
 *
 * Applique ensuite la logique de détection (P1: Custom Domain, P2: Subdomain).
 */
class TenantDoctrineSwitcherListener
{
    private \PDO $pdoMaster;
    private LoggerInterface $logger;
    private TenantConnectionProvider $tenantConnectionProvider;
    private string $frontendMainDomain; 
    private string $backendMainDomain; 

    public function __construct(
        TenantConnectionProvider $tenantConnectionProvider,
        LoggerInterface $logger,
        string $masterDatabaseUrl,
        string $frontendMainDomain, 
        string $backendMainDomain  
    ) {
        $parts = parse_url($masterDatabaseUrl);
        $scheme = $parts['scheme'] === 'postgresql' ? 'pgsql' : $parts['scheme'];
        $host   = $parts['host'];
        $port   = $parts['port'] ?? 5432;
        $db     = ltrim($parts['path'], '/');
        $user   = rawurldecode($parts['user'] ?? '');
        $pass   = rawurldecode($parts['pass'] ?? '');
        $pdoDsn = sprintf('%s:host=%s;port=%d;dbname=%s', $scheme, $host, $port, $db);
        
        $this->pdoMaster = new \PDO($pdoDsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->tenantConnectionProvider = $tenantConnectionProvider;
        $this->logger = $logger;
        $this->frontendMainDomain = $frontendMainDomain; 
        $this->backendMainDomain = $backendMainDomain;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // 1. Chemins exclus (restent sur la BDD master)
        $excludedPaths = [
            '/api/tenant/check',
            '/setup/new-store',
        ];

        $currentPath = $request->getPathInfo();
        if (in_array($currentPath, $excludedPaths)) {
            $this->logger->info("Chemin '{$currentPath}' exclu. Le listener ne s'applique pas.");
            return;
        }
        
        // --- LOGIQUE "BILINGUE" ---
        // 2. Identification du Host
        $host = $request->headers->get('X-Tenant-Host');
        $source = "Header X-Tenant-Host";

        if (!$host) {
            // Fallback pour EasyAdmin / Accès direct
            $host = $request->getHost();
            $source = "Request Host";
        }
        // --- FIN LOGIQUE BILINGUE ---


        $tenantCode = null;
        $targetDb = null;
        
        // 3. Priorité 1 : Vérifier si c'est un DOMAINE PERSONNALISÉ
        try {
            $altHost = $host;
            if (str_starts_with($host, 'www.')) {
                $altHost = substr($host, 4); 
            } else {
                $altHost = 'www.' . $host;
            }

            $stmt = $this->pdoMaster->prepare(
                'SELECT code, dbname FROM tenants WHERE custom_domain = :host OR custom_domain = :altHost'
            );
            $stmt->execute(['host' => $host, 'altHost' => $altHost]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row && $row['dbname']) {
                $tenantCode = $row['code'];
                $targetDb = $row['dbname'];
                $this->logger->info("Tenant trouvé par domaine personnalisé (P1) via {$source}: '$host'. Code: '$tenantCode'.");
            }
        } catch (\PDOException $e) {
            $this->logger->error("Erreur lors de la recherche du custom_domain: " . $e->getMessage());
        }
        
        // 4. Priorité 2 : Vérifier si c'est un SOUS-DOMAINE
        if (!$tenantCode) {
            $isFrontendSubdomain = str_ends_with($host, $this->frontendMainDomain) && $host !== $this->frontendMainDomain;
            $isBackendSubdomain = str_ends_with($host, $this->backendMainDomain) && $host !== $this->backendMainDomain;

            if ($isFrontendSubdomain || $isBackendSubdomain) {
                
                $hostParts = explode('.', $host);
                
                if ($hostParts[0] === 'www') {
                    $tenantCode = $hostParts[1] ?? null;
                } else {
                    $tenantCode = $hostParts[0];
                }
                
                if ($tenantCode) {
                     $this->logger->info("Tenant trouvé par sous-domaine (P2) via {$source}: '$host'. Code: '$tenantCode'.");
                }
            }
        }
        
        // 5. Priorité 3 : Domaine racine par défaut (NE S'APPLIQUE QUE SI LA REQUETE VIENT DU BACKEND)
        if (!$tenantCode && $host === $this->backendMainDomain) {
            $this->logger->info("Domaine racine Backend (P3) détecté. Application du tenant par défaut 'tenantdefaut'.");
            $tenantCode = 'tenantdefaut'; // Assurez-vous que 'tenantdefaut' existe
        }

        if (!$tenantCode) {
            $this->logger->info("Pas de tenant détecté pour le host '$host' (lu depuis {$source}).");
            return;
        }

        // 6. Si on a trouvé le tenant par P2 ou P3, on doit chercher sa BDD
        if (!$targetDb) {
            try {
                $stmt = $this->pdoMaster->prepare('SELECT dbname FROM tenants WHERE code = :c');
                $stmt->execute(['c' => $tenantCode]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                if (!$row || !$row['dbname']) {
                    $this->logger->warning("Tenant '$tenantCode' (P2/P3) non trouvé en base master.");
                    return;
                }
                $targetDb = $row['dbname'];
            } catch (\PDOException $e) {
                $this->logger->error("Erreur lors de la recherche du tenant par code '$tenantCode' : " . $e->getMessage());
                return;
            }
        }

        // 7. Logique de switch
        $currentDb = $this->tenantConnectionProvider->getConnection()->getParams()['dbname'] ?? null;

        if ($targetDb !== $currentDb) {
            $this->logger->info("Changement de contexte de BDD requis. Actuelle: '$currentDb', Cible: '$targetDb'.");
            $this->tenantConnectionProvider->switchTenant($targetDb, $tenantCode);
        } else {
            $this->tenantConnectionProvider->switchTenant($targetDb, $tenantCode);
            $this->logger->info("Contexte de BDD déjà correct. Cible: '$targetDb'. (Tenant: $tenantCode)");
        }
    }
}