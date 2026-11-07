<?php

namespace App\Tests\Functional\Platform;

use App\Entity\User;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\FeatureRequestRepository;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Console de la plateforme par l'API (lot 4, 04/11/2026) : grille, accorder / agir, politiques, demandes, journal ; super administrateur seulement */
class PlatformConsoleApiTest extends WebTestCase
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
        $this->flagsBefore = $this->db->fetchAssociative('SELECT is_boussole_esg_active, is_memoire_vivante_active FROM entreprise ORDER BY id LIMIT 1');
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('UPDATE entreprise SET is_boussole_esg_active = ?, is_memoire_vivante_active = ? WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)', [(bool) $this->flagsBefore['is_boussole_esg_active'], (bool) $this->flagsBefore['is_memoire_vivante_active']], [\Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::BOOLEAN]);
        $this->clean();
        $this->master->exec("DELETE FROM platform_feature_policies WHERE feature_key = 'module.memoires'");
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testGridGrantActAndJournal(): void
    {
        $this->assertSame(403, $this->api('GET', '/api/platform/sites', null, 'admin')->getStatusCode());
        $r = $this->api('GET', '/api/platform/sites', null, 'super');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $body = json_decode((string) $r->getContent(), true);
        $this->assertSame(10, count($body['features']), 'cinq modules, trois applications, deux paliers IA (lot 6)');
        $sites = array_column($body['sites'], null, 'code');
        $this->assertArrayHasKey(MV_TEST_TENANT_CODE, $sites);
        $this->assertArrayNotHasKey('gmasuite', $sites, 'le modèle n\'est pas un site');
        $this->assertFalse($sites[MV_TEST_TENANT_CODE]['features']['module.boussole']['enabled']);

        $r = $this->api('POST', '/api/platform/entitlements', ['tenant' => MV_TEST_TENANT_CODE, 'feature' => 'module.boussole', 'source' => 'trial', 'days' => 15, 'note' => 'essai console'], 'super');
        $this->assertSame(201, $r->getStatusCode(), $r->getContent());
        $e = json_decode((string) $r->getContent(), true)['entitlement'];
        $this->assertSame(['trial', 'active', 'essai console'], [$e['source'], $e['status'], $e['note']]);
        $this->assertTrue((bool) $this->db->fetchOne('SELECT is_boussole_esg_active FROM entreprise ORDER BY id LIMIT 1'), 'projeté');

        $r = $this->api('POST', '/api/platform/entitlements', ['tenant' => 'nulle-part', 'feature' => 'module.x', 'source' => 'bof', 'days' => 9999], 'super');
        $this->assertSame(422, $r->getStatusCode());
        $this->assertSame(['tenant', 'feature', 'source', 'days'], array_column(json_decode((string) $r->getContent(), true)['errors'], 'path'));

        $r = $this->api('PATCH', '/api/platform/entitlements/' . $e['id'], ['action' => 'extend', 'endsAt' => (new \DateTimeImmutable())->modify('+40 days')->format('Y-m-d')], 'super');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $this->assertSame((new \DateTimeImmutable())->modify('+40 days')->format('Y-m-d') . ' 23:59:59', json_decode((string) $r->getContent(), true)['entitlement']['ends_at'], 'date seule : fin de journée');
        $r = $this->api('PATCH', '/api/platform/entitlements/' . $e['id'], ['action' => 'suspend', 'note' => 'test'], 'super');
        $this->assertSame('suspended', json_decode((string) $r->getContent(), true)['entitlement']['status']);
        $this->assertSame(409, $this->api('PATCH', '/api/platform/entitlements/' . $e['id'], ['action' => 'suspend'], 'super')->getStatusCode(), 'déjà suspendu : POLICY_FORBIDDEN');
        $this->assertSame(200, $this->api('PATCH', '/api/platform/entitlements/' . $e['id'], ['action' => 'resume'], 'super')->getStatusCode());
        $this->assertSame(404, $this->api('PATCH', '/api/platform/entitlements/999999', ['action' => 'cancel'], 'super')->getStatusCode());
        $this->assertSame(422, $this->api('PATCH', '/api/platform/entitlements/' . $e['id'], ['action' => 'explode'], 'super')->getStatusCode());

        $r = $this->api('GET', '/api/platform/entitlements?tenant=' . MV_TEST_TENANT_CODE, null, 'super');
        $body = json_decode((string) $r->getContent(), true);
        $this->assertSame([true, 'trial'], [$body['effective']['module.boussole']['enabled'], $body['effective']['module.boussole']['source']]);
        $this->assertGreaterThanOrEqual(1, count($body['entitlements']));
        $this->assertSame(422, $this->api('GET', '/api/platform/entitlements?tenant=nulle-part', null, 'super')->getStatusCode());

        $events = json_decode((string) $this->api('GET', '/api/platform/events?tenant=' . MV_TEST_TENANT_CODE, null, 'super')->getContent(), true)['events'];
        $this->assertSame(['resumed', 'suspended', 'extended', 'trial_started'], array_column($events, 'event'));
    }

    public function testPoliciesAndRequestsDecisions(): void
    {
        $r = $this->api('GET', '/api/platform/policies', null, 'super');
        $this->assertSame(10, count(json_decode((string) $r->getContent(), true)['policies']));
        $r = $this->api('PUT', '/api/platform/policies/module.memoires', ['trialAllowed' => false, 'graceDays' => 3], 'super');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $this->assertSame([false, 3, 30], [json_decode((string) $r->getContent(), true)['policy']['trial_allowed'], json_decode((string) $r->getContent(), true)['policy']['grace_days'], json_decode((string) $r->getContent(), true)['policy']['trial_days']]);
        $this->assertSame(422, $this->api('PUT', '/api/platform/policies/module.memoires', ['trialDays' => 0], 'super')->getStatusCode());
        $r = $this->api('POST', '/api/platform/entitlements', ['tenant' => MV_TEST_TENANT_CODE, 'feature' => 'module.memoires', 'source' => 'trial'], 'super');
        $this->assertSame(409, $r->getStatusCode(), 'essai interdit par la politique');
        $this->assertSame('POLICY_FORBIDDEN', json_decode((string) $r->getContent(), true)['code']);

        // demande d'un site, décision de la plateforme
        $this->db->executeStatement('UPDATE entreprise SET is_boussole_esg_active = false WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)');
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        $req = static::getContainer()->get(FeatureRequestRepository::class)->insert(MV_TEST_TENANT_CODE, 'module.boussole', 'admin@arkanoa-media.com', 'svp');
        $pending = json_decode((string) $this->api('GET', '/api/platform/requests', null, 'super')->getContent(), true)['requests'];
        $this->assertContains((int) $req['id'], array_map('intval', array_column($pending, 'id')));
        $this->assertSame(422, $this->api('PATCH', '/api/platform/requests/' . $req['id'], ['action' => 'maybe'], 'super')->getStatusCode());
        $r = $this->api('PATCH', '/api/platform/requests/' . $req['id'], ['action' => 'grant', 'source' => 'free'], 'super');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $body = json_decode((string) $r->getContent(), true);
        $this->assertSame(['granted', 'free'], [$body['request']['status'], $body['entitlement']['source']]);
        $this->assertTrue(static::getContainer()->get(EntitlementService::class)->isEnabled(MV_TEST_TENANT_CODE, 'module.boussole'));
        $this->assertSame(409, $this->api('PATCH', '/api/platform/requests/' . $req['id'], ['action' => 'refuse'], 'super')->getStatusCode(), 'déjà traitée');
        $this->assertSame(404, $this->api('PATCH', '/api/platform/requests/999999', ['action' => 'refuse'], 'super')->getStatusCode());
    }

    private function clean(): void
    {
        foreach (['tenant_entitlement_events', 'tenant_entitlements', 'tenant_feature_requests'] as $table) {
            $this->master->prepare("DELETE FROM $table WHERE tenant_code = ?")->execute([MV_TEST_TENANT_CODE]);
        }
    }

    private function api(string $method, string $path, ?array $body, ?string $as): \Symfony\Component\HttpFoundation\Response
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test'];
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token($as);
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function token(string $as): string
    {
        $container = static::getContainer();
        $container->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $container->get(TenantEntityManagerProvider::class)->getEntityManager();
        $email = $as === 'super' ? 'super-admin-console@example.invalid' : 'admin@arkanoa-media.com';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Super')->setLastname('Test')->setRoles(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'])->setPassword('inutilisable')->setIsVerified(true);
            $em->persist($user);
            $em->flush();
        }

        return $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
