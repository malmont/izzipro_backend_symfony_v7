<?php

/*
 * Amorçage des tests : isolation complète de la production.
 *
 * Les tests tournent dans le conteneur applicatif, où les variables de production sont déjà définies
 * (elles priment sur .env.test). On les redirige donc explicitement ici, avant le démarrage du noyau :
 *   - base maître  : master_mv_test, qui ne contient qu'un tenant fictif « mvtest » (mvtest.test) ;
 *   - base tenant  : db_mv_contract_test, clonée à chaque lancement depuis db_mv_test_booktypes ;
 *   - Redis        : base 2 (cache, sessions) ; Messenger en mémoire ; mails vers null://.
 * Les adresses de test sont dérivées de DATABASE_URL : aucun mot de passe n'est écrit dans le dépôt.
 */

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

const MV_TEST_TENANT_CODE = 'mvtest';
const MV_TEST_TENANT_HOST = 'mvtest.test';
const MV_TEST_TENANT_DB = 'db_mv_contract_test';
const MV_TEST_MASTER_DB = 'master_mv_test';
const MV_TEST_TEMPLATE_DB = 'db_mv_test_booktypes';
// Second tenant fictif : contrôles d'isolation entre tenants
const MV_TEST_TENANT2_CODE = 'mvtest2';
const MV_TEST_TENANT2_HOST = 'mvtest2.test';
const MV_TEST_TENANT2_DB = 'db_mv_contract_test2';

$prodUrl = getenv('DATABASE_URL') ?: ($_SERVER['DATABASE_URL'] ?? null);
if (!$prodUrl) {
    fwrite(STDERR, "DATABASE_URL absent : lancez les tests dans le conteneur applicatif.\n");
    exit(1);
}
$u = parse_url($prodUrl);
$urlFor = fn (string $db) => sprintf('%s://%s:%s@%s:%d/%s?serverVersion=15&charset=utf8',
    $u['scheme'], rawurlencode(urldecode($u['user'])), rawurlencode(urldecode($u['pass'])), $u['host'], $u['port'] ?? 5432, $db);

$overrides = [
    'APP_ENV' => 'test',
    // Doctrine ajoute le suffixe « _test » (when@test) : db_mv_contract + _test = base du tenant fictif
    'DATABASE_URL' => $urlFor('db_mv_contract'),
    'MASTER_DATABASE_URL' => $urlFor(MV_TEST_MASTER_DB),
    'REDIS_URL' => 'redis://redis:6379/2',
    'MESSENGER_TRANSPORT_DSN' => 'in-memory://',
    'MAILER_DSN' => 'null://null',
    'ANTHROPIC_API_KEY' => 'test-sans-appel-reel',
    // Clé de l'assistant des landing pages neutralisée, sauf pour le test d'intégration optionnel (LANDING_AI_REAL_TEST=1)
    'ANTHROPIC_API_KEY_LANDING' => 'test-sans-appel-reel',
    'OPENAI_API_KEY' => 'test-sans-appel-reel',
    'STRIPE_SECRET_KEY' => 'sk_test_sans_appel_reel',
    // Synchronisation de la configuration des landing pages (faux frontend, voir config/services_test.yaml)
    'DEPLOY_SYNC_TOKEN' => 'jeton-de-deploiement-de-test-0123456789abcdef',
];
if (getenv('LANDING_AI_REAL_TEST')) {
    unset($overrides['ANTHROPIC_API_KEY_LANDING']); // lue dans .env
}
foreach ($overrides as $name => $value) {
    putenv("$name=$value");
    $_ENV[$name] = $_SERVER[$name] = $value;
}

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
}

// Bases de test recréées à chaque lancement
$pdo = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=postgres', $u['host'], $u['port'] ?? 5432), urldecode($u['user']), urldecode($u['pass']), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB, MV_TEST_MASTER_DB] as $db) {
    $pdo->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$db' AND pid <> pg_backend_pid()");
    $pdo->exec("DROP DATABASE IF EXISTS \"$db\"");
}
$pdo->exec(sprintf('CREATE DATABASE "%s" WITH TEMPLATE "%s"', MV_TEST_TENANT_DB, MV_TEST_TEMPLATE_DB));
$pdo->exec(sprintf('CREATE DATABASE "%s" WITH TEMPLATE "%s"', MV_TEST_TENANT2_DB, MV_TEST_TEMPLATE_DB));
$pdo->exec(sprintf('CREATE DATABASE "%s"', MV_TEST_MASTER_DB));

$master = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $u['host'], $u['port'] ?? 5432, MV_TEST_MASTER_DB), urldecode($u['user']), urldecode($u['pass']), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$master->exec('CREATE TABLE tenants (id SERIAL PRIMARY KEY, code VARCHAR(50) NOT NULL UNIQUE, name VARCHAR(255) NOT NULL, dbname VARCHAR(255) NOT NULL, dbuser VARCHAR(255), dbpass VARCHAR(255), gemsuite_token TEXT, is_internal_store BOOLEAN DEFAULT false, custom_domain VARCHAR(255) UNIQUE)');
$master->exec('CREATE TABLE landing_config_sync (id SERIAL PRIMARY KEY, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, action VARCHAR(10) NOT NULL, version VARCHAR(100) DEFAULT NULL, author VARCHAR(255) NOT NULL, result VARCHAR(32) NOT NULL, details TEXT DEFAULT NULL)');
$insertTenant = $master->prepare('INSERT INTO tenants (code, name, dbname, custom_domain) VALUES (?, ?, ?, ?)');
$insertTenant->execute([MV_TEST_TENANT_CODE, 'Tenant de test', MV_TEST_TENANT_DB, MV_TEST_TENANT_HOST]);
$insertTenant->execute([MV_TEST_TENANT2_CODE, 'Second tenant de test', MV_TEST_TENANT2_DB, MV_TEST_TENANT2_HOST]);

// Configuration des landing pages de test (versions synchronisées) repartie de zéro
exec('rm -rf ' . escapeshellarg(dirname(__DIR__) . '/var/landingpage-config-test'));

// Redis de test vidé (base 2 uniquement)
if (class_exists(Redis::class)) {
    $redis = new Redis();
    if (@$redis->connect('redis', 6379, 2.0)) {
        $redis->select(2);
        $redis->flushDB();
    }
}
