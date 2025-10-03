<?php


namespace App\EventListener;

use App\Services\TenantConnectionProvider;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Psr\Log\LoggerInterface;

class TenantDoctrineSwitcherListener
{
    private \PDO $pdoMaster;
    private LoggerInterface $logger;
    private TenantConnectionProvider $tenantConnectionProvider;

    public function __construct(
        TenantConnectionProvider $tenantConnectionProvider,
        LoggerInterface $logger,
        string $masterDatabaseUrl
    ) {
        // ... votre constructeur reste inchangé ...
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

        // --- DÉBUT DE LA MODIFICATION (Version Robuste) ---
        // Liste des CHEMINS D'URL qui ne doivent PAS déclencher le changement de tenant.
        // On se base sur le chemin, pas le nom de la route.
        $excludedPaths = [
            '/api/tenant/check',
        ];

        // On récupère le chemin de la requête actuelle (ex: /api/tenant/check)
        $currentPath = $request->getPathInfo();

        // Si le chemin actuel est dans notre liste d'exceptions, on arrête tout de suite.
        if (in_array($currentPath, $excludedPaths)) {
            $this->logger->info("Chemin '{$currentPath}' exclu. Le listener de tenant ne s'applique pas.");
            return;
        }
        // --- FIN DE LA MODIFICATION ---

        // Le reste de votre code est identique...
        $host = $request->getHost();
        $tenantCode = $request->headers->get('X-Tenant-Code');

        if (!$tenantCode) {
            $hostParts = explode('.', $host);
            if (count($hostParts) > 2) {
                $tenantCode = $hostParts[0];
            }
        }
        
        // ...etc.
        if (!$tenantCode && $host === 'gem-portal-backend.com') {
            $this->logger->info("Domaine racine détecté. Application du tenant par défaut 'tenantdefaut'.");
            $tenantCode = 'tenantdefaut';
        }
        
        if (!$tenantCode) {
            $this->logger->info("Pas de tenant détecté : base par défaut utilisée.");
            return;
        }

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

