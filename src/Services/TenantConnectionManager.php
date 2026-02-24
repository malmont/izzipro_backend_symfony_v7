<?php
// src/Services/TenantConnectionManager.php

namespace App\Services;

use PDO;
use Psr\Log\LoggerInterface;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use App\Dto\TenantConfig;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\MigratorConfiguration;
use App\Services\TenantConnectionProvider;
// --- AJOUTS POUR LE CACHE ---
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

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

    // Ajout de la propriété Cache
    private TagAwareCacheInterface $cache;

    public function __construct(
        string $masterDatabaseUrl,
        string $defaultTenantUrl,
        string $projectDir,
        Connection $connection,
        LoggerInterface $logger,
        TenantConnectionProvider $connectionProvider,
        TagAwareCacheInterface $cache // <-- Injection automatique par Symfony
    ) {
        $this->logger           = $logger;
        $this->defaultTenantUrl = $defaultTenantUrl;
        $this->projectDir       = rtrim($projectDir, '/');
        $this->consolePath      = $this->projectDir . '/bin/console';
        $this->connection       = $connection;
        $this->connectionProvider = $connectionProvider;
        $this->cache            = $cache; // <-- Stockage

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

    public function createTenant(
        string $code,
        string $name,
        string $dbname,
        ?string $gemsuiteToken = null,
        bool $isInternal = false,
        ?string $customDomain = null
    ): void {
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
            } catch (\Throwable $termEx) {
                $this->logger->warning('Impossible de terminer les connexions existantes: ' . $termEx->getMessage());
            }

            // Création physique
            $this->pdoMaster->exec(
                sprintf('CREATE DATABASE "%s" WITH TEMPLATE gmasuite', $dbname)
            );


            $this->fixSequences($dbname);

            // Insertion en base
            $stmt = $this->pdoMaster->prepare(
                'INSERT INTO tenants(code, name, dbname, gemsuite_token, is_internal_store, custom_domain) 
                 VALUES(:c, :n, :d, :t, :is_internal, :custom_domain)'
            );

            $stmt->bindValue(':c', $code);
            $stmt->bindValue(':n', $name);
            $stmt->bindValue(':d', $dbname);
            $stmt->bindValue(':t', $gemsuiteToken);
            $stmt->bindValue(':is_internal', $isInternal, \PDO::PARAM_BOOL);
            $stmt->bindValue(':custom_domain', $customDomain);

            $stmt->execute();


            $this->cache->invalidateTags(['tenants']);
            $this->logger->info("Cache 'tenants' invalidé après création de '$code'.");
        } catch (\Throwable $e) {
            $this->logger->error("Échec création tenant '{$code}' / '{$dbname}': " . $e->getMessage());
            try {
                $this->pdoMaster->exec(sprintf('DROP DATABASE IF EXISTS "%s"', $dbname));
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
        $this->logger->info("[Manager] Bascule demandée vers : " . $tenant->getDbname());

        $this->connectionProvider->switchTenant(
            $tenant->getDbname(),
            $tenant->getCode()
        );
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
        $params = $this->connection->getParams();
        $params['dbname'] = $dbname;
        $this->connection->close();
        $this->connection = DriverManager::getConnection($params);

        // Initialise la table de tracking
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

        $availableMigrationObjects = $dependencyFactory
            ->getMigrationRepository()
            ->getMigrations()
            ->getItems();

        $migrationVersions = [];
        foreach ($availableMigrationObjects as $availableMigration) {
            $migrationVersions[] = $availableMigration->getVersion();
        }

        $plan = $dependencyFactory
            ->getMigrationPlanCalculator()
            ->getPlanForVersions($migrationVersions, Direction::UP);

        $migratorConfiguration = (new MigratorConfiguration())
            ->setAllOrNothing(true);

        $result = $dependencyFactory
            ->getMigrator()
            ->migrate($plan, $migratorConfiguration);
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

    public function isTenantInternal(string $tenantCode): bool
    {
        try {
            $stmt = $this->pdoMaster->prepare(
                'SELECT is_internal_store FROM tenants WHERE code = :code'
            );
            $stmt->execute(['code' => $tenantCode]);
            $result = $stmt->fetchColumn();
            return $result === true;
        } catch (\Throwable $e) {
            $this->logger->error("Erreur statut interne tenant '{$tenantCode}': " . $e->getMessage());
            return false;
        }
    }

    public function findTenantById(int $tenantId): ?array
    {
        try {
            $stmt = $this->pdoMaster->prepare('SELECT code, dbname FROM tenants WHERE id = :id');
            $stmt->execute(['id' => $tenantId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ?: null;
        } catch (\Throwable $e) {
            $this->logger->error("Échec findTenantById {$tenantId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Trouve un tenant par son code.
     * OPTIMISÉ AVEC CACHE REDIS.
     */
    public function findTenantByCode(string $code): ?array
    {
        // 1. Définition de la clé cache
        $key = 'tenant_data_code_' . $code;

        // 2. Interrogation Redis
        return $this->cache->get($key, function (ItemInterface $item) use ($code) {
            $item->expiresAfter(3600); // 1h
            $item->tag(['tenants']);   // Tag pour invalidation groupée

            try {
                $stmt = $this->pdoMaster->prepare('SELECT id, code, dbname FROM tenants WHERE code = :code');
                $stmt->execute(['code' => $code]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                return $result ?: null;
            } catch (\Throwable $e) {
                $this->logger->error("Échec de findTenantByCode pour '{$code}': " . $e->getMessage());
                return null;
            }
        });
    }

    /**
     * Logique de résolution du tenant (Custom Domain + Subdomain + WWW).
     * OPTIMISÉ AVEC CACHE REDIS.
     */
    public function findTenantConfigByHost(string $host): ?TenantConfig
    {
        // 1. Clé unique basée sur le host (md5 sécurise les caractères spéciaux)
        $cacheKey = 'tenant_conf_host_' . md5($host);

        // 2. Le cache gère tout : si trouvé, il retourne direct. Sinon, il exécute la fonction.
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($host) {

            // CONFIGURATION DE L'ITEM CACHE
            $item->expiresAfter(3600); // Durée de vie : 1 heure
            $item->tag(['tenants']);   // Permet de tout vider avec invalidateTags(['tenants'])

            // --- DÉBUT DE LA LOGIQUE ORIGINALE ---
            $pdo = $this->pdoMaster;
            $tenantCode = null;
            $dbname = null;
            $name = 'Unknown';

            // 1. Nettoyage et gestion du WWW
            $altHost = $host;
            if (str_starts_with($host, 'www.')) {
                $altHost = substr($host, 4);
            } else {
                $altHost = 'www.' . $host;
            }

            // 2. PRIORITÉ 1 : DOMAINE PERSONNALISÉ
            try {
                $stmt = $pdo->prepare(
                    'SELECT code, name, dbname FROM tenants WHERE custom_domain = :host OR custom_domain = :altHost LIMIT 1'
                );
                $stmt->execute(['host' => $host, 'altHost' => $altHost]);

                //tableau associatif
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $tenantCode = $row['code'];
                    $dbname = $row['dbname'];
                    $name = $row['name'] ?? $tenantCode;
                }
            } catch (\Throwable $e) {
                // On loggue juste, on ne retourne pas null pour laisser une chance à la P2
                $this->logger->warning("Erreur SQL recherche custom domain: " . $e->getMessage());
            }

            // 3. PRIORITÉ 2 : SOUS-DOMAINE
            if (!$tenantCode) {
                $cleanHost = explode(':', $host)[0];
                $parts = explode('.', $cleanHost);

                $potentialCode = null;
                // Si www.client.site.com -> on prend 'client' (index 1)
                // Si client.site.com -> on prend 'client' (index 0)
                if ($parts[0] === 'www' && isset($parts[1])) {
                    $potentialCode = $parts[1];
                } else {
                    $potentialCode = $parts[0];
                }

                // Exclusion des mots clés système
                if ($potentialCode && !in_array($potentialCode, ['api', 'admin', 'backend', 'www', 'localhost'])) {
                    try {
                        $stmt = $pdo->prepare('SELECT code, name, dbname FROM tenants WHERE code = :code LIMIT 1');
                        $stmt->execute(['code' => $potentialCode]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($row) {
                            $tenantCode = $row['code'];
                            $dbname = $row['dbname'];
                            $name = $row['name'] ?? $tenantCode;
                        }
                    } catch (\Throwable $e) {
                        // Silence
                    }
                }
            }

            // --- FIN DE LA LOGIQUE ORIGINALE ---

            // Si on a trouvé, on retourne l'objet Config (qui sera sérialisé dans Redis)
            if ($tenantCode && $dbname) {
                $config = new TenantConfig();
                $config->setCode($tenantCode);
                $config->setName($name);
                $config->setDbname($dbname);

                return $config;
            }

            return null; // Redis cachera "null", évitant de refaire la requête pour un domaine invalide
        });
    }

    /**
     * After copying gmasuite with WITH TEMPLATE, PostgreSQL copies sequences but does
     * not always preserve the DEFAULT nextval(...) binding on id columns.
     * This method re-attaches every sequence to its column on the newly created tenant DB.
     * It must be called once right after CREATE DATABASE ... WITH TEMPLATE gmasuite.
     */
    private function fixSequences(string $dbname): void
    {
        $params = $this->tenantParams;
        $params['dbname'] = $dbname;

        // Use a dedicated PDO connection to the new tenant database
        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s',
            'pgsql',
            $params['host'] ?? $this->masterParams['host'],
            $params['port'] ?? $this->masterParams['port'],
            $dbname
        );

        try {
            $user = $params['user'] ?? $params['username'] ?? '';
            $pass = $params['password'] ?? '';
            $pdo  = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            // Find all tables where an _id_seq sequence exists but the column has no DEFAULT
            $stmt = $pdo->query("
                SELECT c.table_name
                FROM information_schema.columns c
                WHERE c.table_schema = 'public'
                  AND c.column_name  = 'id'
                  AND (c.column_default IS NULL OR c.column_default NOT LIKE 'nextval%')
                  AND EXISTS (
                      SELECT 1 FROM pg_class
                      WHERE relkind = 'S'
                        AND relname = c.table_name || '_id_seq'
                  )
                ORDER BY c.table_name
            ");

            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $fixed  = 0;

            foreach ($tables as $table) {
                $seqName = $table . '_id_seq';
                try {
                    // Use double-quoted identifiers for table/sequence names (PostgreSQL)
                    $pdo->exec(sprintf(
                        'ALTER TABLE "%s" ALTER COLUMN id SET DEFAULT nextval(\'%s\'::regclass)',
                        $table,
                        $seqName
                    ));
                    $pdo->exec(sprintf(
                        'ALTER SEQUENCE "%s" OWNED BY "%s".id',
                        $seqName,
                        $table
                    ));
                    $fixed++;
                } catch (\Throwable $e) {
                    $this->logger->warning(sprintf(
                        '[fixSequences] Impossible de fixer "%s".id -> "%s": %s',
                        $table,
                        $seqName,
                        $e->getMessage()
                    ));
                }
            }

            $this->logger->info(sprintf(
                '[fixSequences] %d séquence(s) attachée(s) sur la base "%s".',
                $fixed,
                $dbname
            ));
        } catch (\Throwable $e) {
            // Log but do not block tenant creation – the DB was created successfully
            $this->logger->error(sprintf(
                '[fixSequences] Échec sur la base "%s": %s',
                $dbname,
                $e->getMessage()
            ));
        }
    }
}
