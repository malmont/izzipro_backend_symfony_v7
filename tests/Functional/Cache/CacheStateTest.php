<?php

namespace App\Tests\Functional\Cache;

use App\Controller\Admin\RedisAdminController;
use App\DependencyInjection\FixedCacheNamespacePass;
use App\Entity\User;
use App\Services\Platform\Essai\EssaiJobStore;
use App\Services\SubscriptionService\StripeObjectRefStore;
use App\Services\SubscriptionService\SubscriptionStripeGateway;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\Worker\WorkerMonitor;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot CACHE-ETAT (20/11/2026, contrat validé par le chef) :
 * - les pools d'état ont un préfixe FIXE (une montée de version ne les perd plus) et survivent au vidage du cache applicatif ;
 * - « Vider Redis » n'accepte que POST avec jeton, et épargne les préfixes d'état et les laissez-passer à usage unique ;
 * - identifiants Stripe en base maître : un objet par compte + mode + genre + paramètres, créé une seule fois, recréé (génération
 *   suivante) s'il a disparu chez Stripe ;
 * - état des remplissages d'essai en base maître ;
 * - les trois actions POST de la caisse dans EasyAdmin exigent leur jeton CSRF.
 */
class CacheStateTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        $this->master()->exec('DELETE FROM stripe_object_ref');
    }

    public function testStatePoolsHaveAFixedPrefixThatSurvivesTheAppCacheClear(): void
    {
        $c = static::getContainer();
        // Nom RÉEL des clés dans Redis (base des essais) : une clé écrite par chaque pool commence par son préfixe fixe
        $url = (string) ($_ENV['REDIS_URL'] ?? $_SERVER['REDIS_URL'] ?? getenv('REDIS_URL'));
        $redis = new \Redis();
        $redis->connect((string) parse_url($url, PHP_URL_HOST), (int) (parse_url($url, PHP_URL_PORT) ?: 6379), 2.0);
        $redis->select(RedisAdminController::databaseOf($url));
        $this->assertSame(2, RedisAdminController::databaseOf($url), 'base Redis des essais');
        foreach (FixedCacheNamespacePass::NAMESPACES as $id => $namespace) {
            $pool = $c->get($id);
            $probe = 'sonde_prefixe_' . bin2hex(random_bytes(4));
            $pool->save($pool->getItem($probe)->set(1)->expiresAfter(60));
            $keys = $redis->keys('*' . $probe . '*') ?: [];
            $this->assertNotSame([], $keys, "clé écrite par $id");
            foreach ($keys as $key) {
                $this->assertStringStartsWith($namespace . ':', $key, "préfixe fixe de $id");
                $this->assertTrue(RedisAdminController::isProtected($key), "$key épargnée par « Vider Redis »");
            }
            $pool->deleteItem($probe);
        }

        // Un état survit au vidage du cache applicatif (cache:pool:clear cache.app, invalidations de site)
        $state = $c->get('cache.state');
        $key = 'cache_etat_' . bin2hex(random_bytes(4));
        $state->save($state->getItem($key)->set('gardé')->expiresAfter(60));
        $c->get('cache.app')->clear();
        $this->assertSame('gardé', $state->getItem($key)->get(), 'un état ne part pas avec le cache');
        $state->deleteItem($key);

        // Le battement des workers vit dans le pool d'état
        $c->get(WorkerMonitor::class)->beat('email', ['pid' => 1]);
        $this->assertNotNull($c->get(WorkerMonitor::class)->heartbeat('email'));
        $this->assertTrue($state->hasItem('worker_heartbeat.email'), 'battement dans cache.state');
    }

    public function testTheTrafficCountersOfTheTestsStayInTheTestDatabase(): void
    {
        $code = 'cache-etat-' . bin2hex(random_bytes(3));
        static::getContainer()->get(\App\Services\TenantVisitorTrackingService::class)->trackVisit($code, '203.0.113.7', 'essai', '/');
        $url = (string) ($_ENV['REDIS_URL'] ?? $_SERVER['REDIS_URL'] ?? getenv('REDIS_URL'));
        $count = function (int $database) use ($url, $code): int {
            $redis = new \Redis();
            $redis->connect((string) parse_url($url, PHP_URL_HOST), (int) (parse_url($url, PHP_URL_PORT) ?: 6379), 2.0);
            $redis->select($database);

            return count($redis->keys('tenant:traffic:' . $code . ':*') ?: []);
        };
        $this->assertGreaterThan(0, $count(2), 'compteurs écrits dans la base des essais');
        $this->assertSame(0, $count(0), 'rien dans la base 0 (production)');
    }

    public function testRedisFlushSparesStateAndOnlyAcceptsPostWithItsToken(): void
    {
        foreach (['iz_rl:limiter', 'iz_state:app_join.x', 'iz_workers:workers.restart_requested_timestamp', 'admin_pass_used:abc'] as $key) {
            $this->assertTrue(RedisAdminController::isProtected($key), "$key épargnée");
        }
        foreach (['0srbwYjLJM:tagsdb.db_demo', '-Ep2S6ohcP:worker_heartbeat.email', 'iz_statex'] as $key) {
            $this->assertFalse(RedisAdminController::isProtected($key), "$key vidée");
        }
        // le vidage travaille sur la base de l'adresse (les essais : base 2), jamais sur la base 0 par défaut
        $this->assertSame(2, RedisAdminController::databaseOf('redis://redis:6379/2'));
        $this->assertSame(0, RedisAdminController::databaseOf('redis://redis:6379'));
        $this->assertSame(3, RedisAdminController::databaseOf('redis://user:secret@redis:6379/3'));

        $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $app = static::getContainer()->get('cache.app');
        $canary = 'cache_etat_canari_' . bin2hex(random_bytes(4));
        $app->save($app->getItem($canary)->set(1)->expiresAfter(60));
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/redis/flush');
        $this->assertSame(405, $this->client->getResponse()->getStatusCode(), 'un lien ou une image ne vide rien : 405');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/redis/flush');
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/redis/flush', ['_token' => 'faux']);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($app->hasItem($canary), 'POST sans jeton valide : rien de vidé');
        $app->deleteItem($canary);
    }

    public function testStripeObjectsAreCreatedOncePerAccountModeKindAndParameters(): void
    {
        $store = new StripeObjectRefStore(static::getContainer()->get(TenantConnectionManager::class)); // service inliné (un seul usage)
        $created = [];
        $create = function (string $label) use (&$created): callable {
            return function (int $generation) use (&$created, $label): string {
                $created[] = [$label, $generation];

                return 'txr_' . $label . '_g' . $generation;
            };
        };
        $alive = static fn (string $id): bool => true;

        $ref20 = StripeObjectRefStore::refKey(SubscriptionStripeGateway::taxRateParams(['label' => 'TPS', 'rate' => 0.05]));
        $ref20bis = StripeObjectRefStore::refKey(array_reverse(SubscriptionStripeGateway::taxRateParams(['label' => 'TPS', 'rate' => 0.05]), true));
        $this->assertSame($ref20, $ref20bis, 'clé indépendante de l\'ordre des paramètres');
        $first = $store->ensure('acct_x', 'test', 'tax_rate', $ref20, $alive, $create('a'));
        $again = $store->ensure('acct_x', 'test', 'tax_rate', $ref20, $alive, $create('b'));
        $this->assertSame($first, $again, 'le même objet, gardé en base');
        $this->assertSame([['a', 1]], $created, 'une seule création chez Stripe');

        // Deux paramètres différents : deux clés, deux objets (jamais « same idempotency key, different parameters »)
        $ref8 = StripeObjectRefStore::refKey(SubscriptionStripeGateway::taxRateParams(['label' => 'TPS', 'rate' => 0.08]));
        $this->assertNotSame($ref20, $ref8);
        $this->assertNotSame($first, $store->ensure('acct_x', 'test', 'tax_rate', $ref8, $alive, $create('c')));
        $this->assertNotSame(StripeObjectRefStore::idempotencyKey(1, 'tax', 'acct_x', 'test', $ref20), StripeObjectRefStore::idempotencyKey(1, 'tax', 'acct_x', 'test', $ref8));
        $this->assertNotSame(StripeObjectRefStore::idempotencyKey(1, 'tax', 'acct_x', 'test', $ref20), StripeObjectRefStore::idempotencyKey(1, 'tax', 'acct_z', 'test', $ref20), 'le compte est dans la clé d\'idempotence');
        $this->assertNotSame(StripeObjectRefStore::idempotencyKey(1, 'tax', 'acct_x', 'test', $ref20), StripeObjectRefStore::idempotencyKey(1, 'tax', 'acct_x', 'live', $ref20), 'le mode est dans la clé d\'idempotence');
        $this->assertNotSame(StripeObjectRefStore::idempotencyKey(1, 'tax', 'acct_x', 'test', $ref20), StripeObjectRefStore::idempotencyKey(2, 'tax', 'acct_x', 'test', $ref20), 'la génération change la clé');
        [$p1, $q1] = SubscriptionStripeGateway::shippingParams(3, 'Poste', 'site', 1500, 'CAD', 'month', 1);
        [$p2, $q2] = SubscriptionStripeGateway::shippingParams(3, 'Poste', 'site', 1500, 'CAD', 'month', 3);
        $this->assertNotSame(StripeObjectRefStore::refKey(['product' => $p1, 'price' => $q1]), StripeObjectRefStore::refKey(['product' => $p2, 'price' => $q2]), 'intervalle dans la clé');
        [$p3, $q3] = SubscriptionStripeGateway::shippingParams(3, 'Purolator', 'site', 1500, 'CAD', 'month', 1);
        $this->assertNotSame(StripeObjectRefStore::refKey(['product' => $p1, 'price' => $q1]), StripeObjectRefStore::refKey(['product' => $p3, 'price' => $q3]), 'nom du transporteur dans la clé');

        // Le mode et le compte font partie de la clé unique
        $this->assertNotSame($first, $store->ensure('acct_x', 'live', 'tax_rate', $ref20, $alive, $create('d')), 'live distinct de test');
        $this->assertNotSame($first, $store->ensure('platform', 'test', 'tax_rate', $ref20, $alive, $create('e')), 'autre compte');
        $this->assertSame('live', StripeObjectRefStore::modeOf('sk_live_x'));
        $this->assertSame('live', StripeObjectRefStore::modeOf('rk_live_x'));
        $this->assertSame('test', StripeObjectRefStore::modeOf('sk_test_x'));
        $this->assertSame('test', StripeObjectRefStore::modeOf(null));

        // Référence périmée (disparue ou archivée chez Stripe) : recréée sous le même verrou, génération suivante, ligne mise à jour
        $created = [];
        $recreated = $store->ensure('acct_x', 'test', 'tax_rate', $ref20, static fn (string $id): bool => false, $create('f'));
        $this->assertSame([['f', 2]], $created, 'génération 2');
        $this->assertSame('txr_f_g2', $recreated);
        $this->assertSame('txr_f_g2', $store->ensure('acct_x', 'test', 'tax_rate', $ref20, $alive, $create('g')), 'la ligne garde le nouvel objet');
        $this->assertSame(4, (int) $this->master()->query("SELECT COUNT(*) FROM stripe_object_ref WHERE kind = 'tax_rate'")->fetchColumn());

        // Une création qui échoue (Stripe en panne, 500 gardée 24 h sous sa clé) : l'erreur remonte, le verrou est rendu, et la génération
        // AVANCE pour que l'essai suivant prenne une clé d'idempotence neuve
        try {
            $store->ensure('acct_y', 'test', 'tax_rate', $ref20, $alive, static fn (int $g): string => throw new \RuntimeException('Stripe en panne'));
            $this->fail('l\'erreur doit remonter');
        } catch (\RuntimeException) {
        }
        $row = $this->master()->query("SELECT generation, stripe_id FROM stripe_object_ref WHERE account = 'acct_y'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame([2, null], [(int) $row['generation'], $row['stripe_id']], 'génération avancée, aucun identifiant');
        $created = [];
        $this->assertSame('txr_h_g2', $store->ensure('acct_y', 'test', 'tax_rate', $ref20, $alive, $create('h')));
        $this->assertSame([['h', 2]], $created, 'nouvelle génération après l\'échec');
        // Ligne « création en cours » laissée par un processus mort (stripe_id NULL, même génération) : MÊME clé, Stripe rend le même objet
        $this->master()->exec("UPDATE stripe_object_ref SET stripe_id = NULL, generation = 5 WHERE account = 'acct_y'");
        $created = [];
        $store->ensure('acct_y', 'test', 'tax_rate', $ref20, $alive, $create('i'));
        $this->assertSame([['i', 5]], $created, 'même génération après un processus mort');
        // Objet vivant : aucune création, aucun verrou (vérifié hors transaction)
        $created = [];
        $store->ensure('acct_y', 'test', 'tax_rate', $ref20, $alive, $create('j'));
        $this->assertSame([], $created);
    }

    public function testTheEssaiJobStateLivesInTheMasterDatabase(): void
    {
        $jobs = static::getContainer()->get(EssaiJobStore::class);
        $jobs->forget(MV_TEST_TENANT2_CODE);
        $this->assertNull($jobs->get(MV_TEST_TENANT2_CODE));
        $jobs->put(MV_TEST_TENANT2_CODE, ['state' => 'running', 'since' => time(), 'by' => 'essai']);
        static::getContainer()->get('cache.app')->clear();
        $this->assertSame('running', $jobs->get(MV_TEST_TENANT2_CODE)['state'], 'en base : survit au vidage du cache');
        $this->assertTrue($jobs->busy(MV_TEST_TENANT2_CODE));
        $jobs->put(MV_TEST_TENANT2_CODE, ['state' => 'done', 'since' => time()]);
        $this->assertFalse($jobs->busy(MV_TEST_TENANT2_CODE));
        $this->assertSame(1, (int) $this->master()->query("SELECT COUNT(*) FROM essai_job WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "'")->fetchColumn(), 'une ligne par site');
        $jobs->forget(MV_TEST_TENANT2_CODE);
    }

    public function testThePosActionsInEasyAdminNeedTheirCsrfToken(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        foreach (['/admin/pos/coupons', '/admin/pos/archive', '/admin/pos/exports'] as $path) {
            $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . $path, ['action' => 'mark', 'year' => '2025', 'format' => 'summary']);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), "$path : POST sans jeton refusé");
            $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . $path, ['action' => 'mark', 'year' => '2025', 'format' => 'summary', '_token' => 'faux']);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), "$path : faux jeton refusé");
            $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), "$path : page affichée");
            $this->assertSame(1, $crawler->filter('form[method="post"] input[name="_token"]')->count(), "$path : le formulaire porte le jeton");
        }
    }

    private function master(): \PDO
    {
        return static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
    }

    private function loginAs(array $roles): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'cache-etat-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Cache')->setLastname('Etat')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        \App\Tests\Functional\Support\OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
