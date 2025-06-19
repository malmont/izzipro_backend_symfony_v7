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
        // ... constructeur inchangé ...
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

        // ... le reste de votre logique ne change pas ...
        $tenantCode = $request->headers->get('X-Tenant-Code')
            ?: (\str_contains($host, '.') ? \explode('.', $host, 2)[0] : null);

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
        $this->tenantConnectionProvider->switchTenant($targetDb, $tenantCode);
    }
}