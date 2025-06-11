<?php
namespace App\Services;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Listener qui détecte le tenant à chaque requête HTTP,
 * puis bascule la connexion Doctrine DBAL sur la bonne base.
 */
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
        // Init PDO pour la base master (pour lookup du mapping tenant)
        $parts = parse_url($masterDatabaseUrl);
        $scheme = $parts['scheme'] === 'postgresql' ? 'pgsql' : $parts['scheme'];
        $host   = $parts['host'];
        $port   = $parts['port'] ?? 5432;
        $db     = ltrim($parts['path'], '/');
        $user   = rawurldecode($parts['user'] ?? '');
        $pass   = rawurldecode($parts['pass'] ?? '');

        $pdoDsn = sprintf('%s:host=%s;port=%d;dbname=%s', $scheme, $host, $port, $db);
        $this->pdoMaster = new \PDO($pdoDsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        $this->tenantConnectionProvider = $tenantConnectionProvider;
        $this->logger = $logger;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $host = $request->getHost();

        // 1. Récupère le code du tenant
        $tenantCode = $request->headers->get('X-Tenant-Code')
            ?: (\str_contains($host, '.') ? \explode('.', $host, 2)[0] : null);

        if (!$tenantCode) {
            $this->logger->info("Pas de tenant détecté : base par défaut utilisée.");
            return;
        }

        // 2. Lookup du mapping tenant->dbname (sur la base master)
        $stmt = $this->pdoMaster->prepare('SELECT dbname FROM tenants WHERE code = :c');
        $stmt->execute(['c' => $tenantCode]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || !$row['dbname']) {
            $this->logger->warning("Tenant '$tenantCode' non trouvé en base master.");
            return;
        }

        $targetDb = $row['dbname'];
        $currentDb = $this->tenantConnectionProvider->getConnection()->getParams()['dbname'];

        // 3. Si besoin, switch sur la bonne DB
        if ($targetDb !== $currentDb) {
            $this->logger->info("Switch DBAL : $currentDb → $targetDb pour tenant $tenantCode");
            $this->tenantConnectionProvider->switchTenant($targetDb,$tenantCode);

            // --- Test : Affiche la base courante après switch ---
            $db = $this->tenantConnectionProvider->getConnection()->fetchOne('SELECT current_database()');
            // En prod : enlève le die() ci-dessus !
        } else {
        }
    }
}
