<?php

namespace App\Tests\Functional\Platform;

use App\Entity\User;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformModeService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Cheminement de préparation du site (05/11/2026) : GET /api/site/readiness, étapes par module accordé, fonctionnalités à obtenir, avancement ; grille de la plateforme */
class SiteReadinessTest extends WebTestCase
{
    private KernelBrowser $client;
    private \PDO $master;
    private \Doctrine\DBAL\Connection $db;
    private array $flagsBefore;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->flagsBefore = $this->db->fetchAssociative('SELECT is_boussole_esg_active, is_boutique_active FROM entreprise ORDER BY id LIMIT 1');
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('UPDATE entreprise SET is_boussole_esg_active = ?, is_boutique_active = ? WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)', [(bool) $this->flagsBefore['is_boussole_esg_active'], (bool) $this->flagsBefore['is_boutique_active']], [\Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::BOOLEAN]);
        $this->clean();
        static::getContainer()->get(PlatformModeService::class)->setShopUrl(null, 'test');
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testStepsFollowGrantedFeaturesAndTheirConfiguration(): void
    {
        $this->assertSame(401, $this->api(null)->getStatusCode());
        $this->assertSame(403, $this->api('client')->getStatusCode());
        $r = $this->api('admin');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $body = json_decode((string) $r->getContent(), true);
        foreach (['code', 'percent', 'done', 'total', 'next', 'steps'] as $k) {
            $this->assertArrayHasKey($k, $body);
        }
        $keys = array_column($body['steps'], 'key');
        foreach (['site.identity', 'site.address', 'site.localisation', 'site.email_sender', 'site.logo'] as $k) {
            $this->assertContains($k, $keys, 'socle du site toujours présent');
        }
        $byKey = array_column($body['steps'], null, 'key');
        $this->assertContains($byKey['site.identity']['status'], ['done', 'todo']);
        $this->assertSame(['backend', 'screen', 'entreprise'], [$byKey['site.identity']['where'], $byKey['site.identity']['action']['type'], $byKey['site.identity']['action']['screen']]);
        $this->assertSame(trim((string) $this->db->fetchOne('SELECT email FROM entreprise ORDER BY id LIMIT 1')) !== '' ? 'done' : 'todo', $byKey['site.identity']['status'], 'fait dès que nom et courriel sont renseignés');
        $this->assertSame('editor', $byKey['site.localisation']['where']);
        $this->assertContains('obtain.module.boussole', $keys, 'fonctionnalité non accordée : à obtenir');
        $this->assertSame(['optional', 'platform', 'trial', 30], [$byKey['obtain.module.boussole']['status'], $byKey['obtain.module.boussole']['where'], $byKey['obtain.module.boussole']['action']['type'], $byKey['obtain.module.boussole']['action']['days']], 'politique par défaut : essai immédiat de 30 jours');
        $this->assertNotContains('boussole.questions', $keys);
        $this->assertSame($body['total'], count(array_filter($body['steps'], fn ($s) => in_array($s['status'], ['done', 'todo'], true))), 'les étapes à obtenir ne comptent pas');
        $this->assertSame((int) round($body['done'] * 100 / max(1, $body['total'])), $body['percent']);

        // droit accordé : les étapes de configuration du module remplacent « obtenir »
        static::getContainer()->get(EntitlementService::class)->grant(MV_TEST_TENANT_CODE, 'module.boussole', 'manual', null, 'test', null, null, null, false);
        static::getContainer()->get(PlatformModeService::class)->setShopUrl('https://forfaits.example.invalid/', 'test');
        $body = json_decode((string) $this->api('admin')->getContent(), true);
        $byKey = array_column($body['steps'], null, 'key');
        $this->assertArrayHasKey('boussole.questions', $byKey);
        $this->assertArrayNotHasKey('obtain.module.boussole', $byKey);
        $this->assertSame([(int) $this->db->fetchOne('SELECT COUNT(*) FROM esg_diag_question WHERE is_active') > 0 ? 'done' : 'todo', 'esg_questions'], [$byKey['boussole.questions']['status'], $byKey['boussole.questions']['action']['screen']]);
        $this->assertSame(['trial', 'https://forfaits.example.invalid/'], [$byKey['obtain.module.memoires']['action']['type'], $byKey['obtain.module.memoires']['action']['shopUrl']], 'essai possible, boutique de forfaits connue');

        // grille de la plateforme : avancement par site
        $sites = array_column(json_decode((string) $this->api('super', '/api/platform/sites')->getContent(), true)['sites'], null, 'code');
        $this->assertSame($body['percent'], $sites[MV_TEST_TENANT_CODE]['readiness']['percent']);
    }

    private function clean(): void
    {
        foreach (['tenant_entitlement_events', 'tenant_entitlements', 'tenant_feature_requests'] as $table) {
            $this->master->prepare("DELETE FROM $table WHERE tenant_code = ?")->execute([MV_TEST_TENANT_CODE]);
        }
    }

    private function api(?string $as, string $path = '/api/site/readiness'): \Symfony\Component\HttpFoundation\Response
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json'];
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token($as);
        }
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server);

        return $this->client->getResponse();
    }

    private function token(string $as): string
    {
        $container = static::getContainer();
        $container->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $container->get(TenantEntityManagerProvider::class)->getEntityManager();
        $email = match ($as) { 'admin' => 'admin@arkanoa-media.com', 'super' => 'super-admin-readiness@example.invalid', default => 'client-readiness@example.invalid' };
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname(ucfirst($as))->setLastname('Test')->setRoles($as === 'super' ? ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] : ['ROLE_USER_INTERNET'])->setPassword('inutilisable')->setIsVerified(true);
            $em->persist($user);
            $em->flush();
        }

        return $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
