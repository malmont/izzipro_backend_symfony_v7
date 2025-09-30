<?php
// src/EventListener/TenantDoctrineSwitcherListener.php

namespace App\EventListener;

use App\Services\TenantConnectionProvider;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Psr\Log\LoggerInterface;

class TenantDoctrineSwitcherListener
{
    private \PDO $pdoMaster;
    private LoggerInterface $logger;
    private TenantConnectionProvider $tenantConnectionProvider;

    // Le constructeur ne change pas
    public function __construct(
        TenantConnectionProvider $tenantConnectionProvider,
        LoggerInterface $logger,
        string $masterDatabaseUrl
    ) {
        // ... (votre code de constructeur existant)
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
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $host = $request->getHost();
        $tenantCode = null;

        // 1. On essaie de récupérer via l'en-tête, pour les APIs par exemple
        $tenantCode = $request->headers->get('X-Tenant-Code');

        // 2. Sinon, on essaie de récupérer via le sous-domaine
        if (!$tenantCode) {
            $hostParts = explode('.', $host);
            // Un sous-domaine valide aura au moins 3 parties (ex: tenant.domaine.com)
            if (count($hostParts) > 2) {
                $tenantCode = $hostParts[0];
            }
        }

        // 3. Si on n'a toujours rien trouvé (on est sur le domaine racine), on applique le tenant par défaut
        if (!$tenantCode && $host === 'gem-portal-backend.com') {
            $this->logger->info("Domaine racine détecté. Application du tenant par défaut 'tenantdefaut'.");
            $tenantCode = 'tenantdefaut';
        }
        
        // 4. Si après tout ça on n'a pas de code, on ne fait rien
        if (!$tenantCode) {
            $this->logger->info("Pas de tenant détecté : base par défaut utilisée.");
            return;
        }

        // La suite du code pour changer de base de données ne change pas...
        $stmt = $this->pdoMaster->prepare('SELECT dbname FROM tenants WHERE code = :c');
        $stmt->execute(['c' => $tenantCode]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || !$row['dbname']) {
            $this->logger->warning("Tenant '$tenantCode' non trouvé en base master.");
            return;
        }

        $targetDb = $row['dbname'];
        $currentDb = $this->tenantConnectionProvider->getConnection()->getParams()['dbname'] ?? null;

        if ($targetDb !== $currentDb) {
            $this->logger->info("Changement de contexte de BDD requis. Actuelle: '$currentDb', Cible: '$targetDb'.");
            $this->tenantConnectionProvider->switchTenant($targetDb, $tenantCode);
        }
    }
}