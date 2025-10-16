<?php
// src/Services/TenantConnectionManager.php

namespace App\Services;

use PDO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use App\Dto\TenantConfig;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Symfony\Component\Console\Output\NullOutput;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Metadata\ExecutedMigrations;
use Doctrine\Migrations\Version\MigrationVersion;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\MigratorConfiguration;
use App\Services\TenantConnectionProvider;



class TenantConnectionManager
{
    private PDO              $pdoMaster;
    private array            $tenantParams;
    private array            $masterParams;
    private string           $consolePath;
    private string           $defaultTenantUrl;
    private Connection       $connection;
    private LoggerInterface  $logger;
    private string           $projectDir;
    private TenantConnectionProvider $connectionProvider; 


    public function __construct(
        string $masterDatabaseUrl,
        string $defaultTenantUrl,
        string $projectDir,
        Connection $connection,
        LoggerInterface $logger,
        TenantConnectionProvider $connectionProvider,

    ) {
        $this->logger           = $logger;
        $this->defaultTenantUrl = $defaultTenantUrl;
        $this->projectDir       = rtrim($projectDir, '/');
        $this->consolePath      = $this->projectDir . '/bin/console';
        $this->connection       = $connection;
        $this->connectionProvider = $connectionProvider;


        // parse and validate master DSN
        $parts = parse_url($masterDatabaseUrl);
        if ($parts === false) {
            throw new \InvalidArgumentException("MASTER_DATABASE_URL invalide");
        }

        $scheme = $parts['scheme'] === 'postgresql' ? 'pgsql' : $parts['scheme'];
        $host   = $parts['host'] ?? throw new \InvalidArgumentException("Hôte manquant dans MASTER_DATABASE_URL");
        $port   = $parts['port'] ?? 5432;
        $db     = isset($parts['path']) ? ltrim($parts['path'], '/') : throw new \InvalidArgumentException("Nom de DB manquant");
        $user   = rawurldecode($parts['user'] ?? '');
        $pass   = rawurldecode($parts['pass'] ?? '');

        $pdoDsn = sprintf('%s:host=%s;port=%d;dbname=%s', $scheme, $host, $port, $db);
        $this->pdoMaster = new PDO($pdoDsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // capture tenant default params from Doctrine connection
        $this->tenantParams = $connection->getParams();
        $this->masterParams = [
            'driver'   => $this->tenantParams['driver'],
            'host'     => $host,
            'port'     => $port,
            'dbname'   => $db,
            'user'     => $user,
            'password' => $pass,
        ];
    }

    public function getPdoMaster(): PDO
    {
        return $this->pdoMaster;
    }

    // -------------------------------------------------------------------
    // CRUD tenant + migrations
    // -------------------------------------------------------------------

     public function createTenant(string $code, string $name, string $dbname, ?string $gemsuiteToken = null): void
        {
            if (!preg_match('/^[a-z0-9_]+$/i', $code) || !preg_match('/^[a-z0-9_]+$/i', $dbname)) {
                throw new \InvalidArgumentException("Code ou dbname invalide : seuls [a-z0-9_] sont autorisés");
            }

            try {
                $templateDbName = 'gmasuite'; 
                $this->logger->info(sprintf('Tentative de terminaison des connexions pour la base template "%s"', $templateDbName));
                try {
                    $stmt = $this->pdoMaster->prepare(
                        "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :datname AND pid <> pg_backend_pid()"
                    );
                    $stmt->execute(['datname' => $templateDbName]);
                    $this->logger->info('Les connexions existantes ont été terminées.');
                } catch (\Throwable $termEx) {
                    $this->logger->warning('Impossible de terminer les connexions existantes: ' . $termEx->getMessage());
                }
                $this->pdoMaster->exec(
                    sprintf('CREATE DATABASE "%s" WITH TEMPLATE gmasuite', $dbname)
                );
                $stmt = $this->pdoMaster->prepare(
                    'INSERT INTO tenants(code, name, dbname, gemsuite_token) VALUES(:c, :n, :d, :t)'
                );
                $stmt->execute([
                    'c' => $code,
                    'n' => $name,
                    'd' => $dbname,
                    't' => $gemsuiteToken
                ]);

            } catch (\Throwable $e) {
                $this->logger->error("Échec création tenant '{$code}' / '{$dbname}': " . $e->getMessage());
                try {
                    $this->pdoMaster->exec(sprintf('DROP DATABASE IF EXISTS "%s"', $dbname));
                    $this->logger->info("DROP DATABASE \"{$dbname}\" après échec");
                } catch (\Throwable $dropEx) {
                    $this->logger->warning("Échec DROP DATABASE '{$dbname}' : " . $dropEx->getMessage());
                }
                throw $e;
            }
        }

    public function migrateTenant(string $dbname): void
    {
        $stmt = $this->pdoMaster->prepare('SELECT 1 FROM tenants WHERE dbname = :db');
        $stmt->execute(['db' => $dbname]);
        if (!$stmt->fetchColumn()) {
            throw new \InvalidArgumentException("La base tenant “{$dbname}” n’existe pas.");
        }
        $this->runMigrations($dbname);
    }

    public function migrateAllTenants(): void
    {
        $dbs = $this->pdoMaster
            ->query('SELECT dbname FROM tenants')
            ->fetchAll(PDO::FETCH_COLUMN);

        foreach ($dbs as $dbname) {
            $this->runMigrations($dbname);
        }
    }

    // -------------------------------------------------------------------
    // Runtime switch
    // -------------------------------------------------------------------

    public function switchToTenant(TenantConfig $tenant): void
    {
        $params = $this->tenantParams;
        $params['dbname'] = $tenant->getDbname();
        $this->reconnect($params);
    }

    public function switchToMaster(): void
    {
        $this->reconnect($this->masterParams);
    }

    private function reconnect(array $params): void
    {
        if ($this->connection->isConnected()) {
            $this->connection->close();
        }
        $this->connection = DriverManager::getConnection(
            $params,
            $this->connection->getConfiguration(),
            $this->connection->getEventManager()
        );
    }

    // -------------------------------------------------------------------
    // Helper: exécute doctrine:migrations:migrate CLI
    // -------------------------------------------------------------------


 
private function runMigrations(string $dbname): void
{
    // 1. Switch DBAL sur la nouvelle base
    $params = $this->connection->getParams();
    $params['dbname'] = $dbname;
    $this->connection->close();
    $this->connection = DriverManager::getConnection($params);

    // Log la base utilisée
    $currentDb = $this->connection->fetchOne('SELECT current_database()');

    // 2. Initialise la table de tracking des migrations
    $configuration = new ConfigurationArray([
        'migrations_paths' => [
            'DoctrineMigrations' => $this->projectDir . '/migrations',
        ],
        'table_storage' => [
            'table_name' => 'doctrine_migration_version',
        ],
    ]);
    $dependencyFactory = DependencyFactory::fromConnection(
        $configuration,
        new ExistingConnection($this->connection)
    );
    $dependencyFactory->getMetadataStorage()->ensureInitialized();

    // 3. Log tables avant migration
    $tables = $this->connection->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname='public'");

    // 4. Récupère les migrations disponibles
    $availableMigrationObjects = $dependencyFactory
        ->getMigrationRepository()
        ->getMigrations()
        ->getItems();

    // 5. Prépare le plan UP
    $migrationVersions = [];
    foreach ($availableMigrationObjects as $availableMigration) {
        $migrationVersions[] = $availableMigration->getVersion();
    }

    $plan = $dependencyFactory
        ->getMigrationPlanCalculator()
        ->getPlanForVersions($migrationVersions, Direction::UP);


    // 6. Config du migrator
    $migratorConfiguration = (new MigratorConfiguration())
        ->setAllOrNothing(true);


    $result = $dependencyFactory
        ->getMigrator()
        ->migrate($plan, $migratorConfiguration);

    // 8. Log les tables après migration
    $tablesAfter = $this->connection->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname='public'");

    // 9. Récupère les versions migrées (compatible toutes versions)
    $migratedVersions = [];
    if (is_object($result) && method_exists($result, 'getMigratedVersions')) {
        foreach ($result->getMigratedVersions() as $mv) {
            $migratedVersions[] = (string)$mv;
        }
    } elseif (is_array($result)) {
        foreach ($result as $mv) {
            if (is_array($mv)) {
                $migratedVersions[] = json_encode($mv);
            } else {
                $migratedVersions[] = (string)$mv;
            }
        }
    }


}


  public function getMasterParams(): array
    {
        return $this->masterParams;
    }

    public function getTenantParams(): array
    {
        return $this->tenantParams;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getAllTenantDbNames(): array
    {
        $stmt = $this->pdoMaster->query('SELECT dbname FROM tenants ORDER BY id');
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }


    public function getTenantToken(string $tenantCode): ?string
    {
        $stmt = $this->pdoMaster->prepare('SELECT gemsuite_token FROM tenants WHERE code = :code');
        $stmt->execute(['code' => $tenantCode]);
        $result = $stmt->fetchColumn();

        return $result ?: null;
    }

     public function getCurrentTenantCode(): ?string
    {
        return $this->connectionProvider->getTenantCode();
    }

    public function switchToTenantByName(string $dbname): void
    {
        $params = $this->tenantParams;
        $params['dbname'] = $dbname;
        $this->reconnect($params);
    }

}



