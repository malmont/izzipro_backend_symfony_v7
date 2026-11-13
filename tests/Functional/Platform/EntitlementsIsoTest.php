<?php

namespace App\Tests\Functional\Platform;

use App\Entity\User;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\EntitlementService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\AiRightTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot DROITS-ISO (13/11/2026, `docs/console-pilotage.md` § 2) :
 * - `endsAt` conservé à l'identique (`Y-m-d H:i:s` UTC, ou null sans fin) ;
 * - `endsAtIso` ajouté à côté de chaque `endsAt` en ISO 8601 avec décalage (`+00:00`, ou null sans fin) sur toutes les sorties qui
 *   rendent `endsAt` (`EntitlementService::effective()`, `GET /api/platform/sites`, `GET /api/platform/entitlements`,
 *   `GET /api/site/features`, `PUT /api/site/features`).
 */
class EntitlementsIsoTest extends WebTestCase
{
    private KernelBrowser $client;
    private \PDO $master;
    private \Doctrine\DBAL\Connection $db;
    private array $flagsBefore;
    private ?string $localeBefore;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->flagsBefore = $this->db->fetchAssociative('SELECT ' . implode(', ', FeatureCatalog::GOVERNED) . ' FROM entreprise ORDER BY id LIMIT 1') ?: [];
        $this->localeBefore = $this->db->fetchOne('SELECT locale FROM entreprise ORDER BY id LIMIT 1') ?: null;
        $this->cleanMaster();
    }

    protected function tearDown(): void
    {
        $sets = [];
        $params = [];
        $types = [];
        foreach (FeatureCatalog::GOVERNED as $column) {
            if (array_key_exists($column, $this->flagsBefore)) {
                $sets[] = "$column = ?";
                $params[] = (bool) $this->flagsBefore[$column];
                $types[] = \Doctrine\DBAL\ParameterType::BOOLEAN;
            }
        }
        $sets[] = 'locale = ?';
        $params[] = $this->localeBefore;
        $types[] = \Doctrine\DBAL\ParameterType::STRING;
        $this->db->executeStatement(
            'UPDATE entreprise SET ' . implode(', ', $sets) . ' WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)',
            $params,
            $types
        );
        $this->cleanMaster();
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        AiRightTestHelper::restoreFullAi(static::getContainer());
        parent::tearDown();
    }

    public function testEndsAtAndEndsAtIsoAreExposedAcrossEffectiveAndAllApiOutputs(): void
    {
        $service = static::getContainer()->get(EntitlementService::class);
        $endInstant = (new \DateTimeImmutable('+45 days', new \DateTimeZone('UTC')))->setTime(5, 0, 0);
        $expectedEndsAt = $endInstant->format('Y-m-d H:i:s');
        $expectedIso = $endInstant->format(\DateTimeInterface::ATOM);

        // Un droit daté (type essai/fin fixe) + un droit sans fin + une fonctionnalité inactive
        $service->grant(MV_TEST_TENANT_CODE, 'module.boussole', 'trial', $endInstant, 'test-iso@example.invalid');
        $service->grant(MV_TEST_TENANT_CODE, 'module.landing', 'free', null, 'test-iso@example.invalid');
        $this->db->executeStatement(
            'UPDATE entreprise SET is_memoire_vivante_active = false WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)'
        );
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();

        // 1. EntitlementService::effective()
        $effective = $service->effective(MV_TEST_TENANT_CODE);
        $this->assertDatedEntitlement($effective['module.boussole'], $expectedEndsAt, $expectedIso, $endInstant);
        $this->assertEndlessOrEmptyEntitlement($effective['module.landing'], true);
        $this->assertEndlessOrEmptyEntitlement($effective['module.memoires'], false);

        // 2. GET /api/platform/sites (lit effective() via PlatformConsoleService::grid)
        $r = $this->api('GET', '/api/platform/sites', null, 'super');
        $this->assertSame(200, $r->getStatusCode(), (string) $r->getContent());
        $sites = array_column(json_decode((string) $r->getContent(), true)['sites'], null, 'code');
        $this->assertArrayHasKey(MV_TEST_TENANT_CODE, $sites);
        $this->assertDatedEntitlement($sites[MV_TEST_TENANT_CODE]['features']['module.boussole'], $expectedEndsAt, $expectedIso, $endInstant);
        $this->assertEndlessOrEmptyEntitlement($sites[MV_TEST_TENANT_CODE]['features']['module.landing'], true);
        $this->assertEndlessOrEmptyEntitlement($sites[MV_TEST_TENANT_CODE]['features']['module.memoires'], false);

        // 3. GET /api/platform/entitlements?tenant=... (lit effective())
        $r = $this->api('GET', '/api/platform/entitlements?tenant=' . MV_TEST_TENANT_CODE, null, 'super');
        $this->assertSame(200, $r->getStatusCode(), (string) $r->getContent());
        $body = json_decode((string) $r->getContent(), true);
        $this->assertDatedEntitlement($body['effective']['module.boussole'], $expectedEndsAt, $expectedIso, $endInstant);
        $this->assertEndlessOrEmptyEntitlement($body['effective']['module.landing'], true);
        $this->assertEndlessOrEmptyEntitlement($body['effective']['module.memoires'], false);

        // 4. GET /api/site/features (construit son propre tableau entitlement dans SiteFeatureStateService::all)
        $r = $this->api('GET', '/api/site/features', null, 'admin');
        $this->assertSame(200, $r->getStatusCode(), (string) $r->getContent());
        $features = array_column(json_decode((string) $r->getContent(), true)['features'], null, 'key');
        $this->assertDatedEntitlement($features['module.boussole']['entitlement'], $expectedEndsAt, $expectedIso, $endInstant);
        $this->assertEndlessOrEmptyEntitlement($features['module.landing']['entitlement'], true);
        $this->assertNull($features['module.memoires']['entitlement']);

        // 5. PUT /api/site/features (retourne le même tableau reconstruit via SiteFeaturesController::show)
        $r = $this->api('PUT', '/api/site/features', ['values' => ['site.locale' => 'fr']], 'admin');
        $this->assertSame(200, $r->getStatusCode(), (string) $r->getContent());
        $featuresAfterPut = array_column(json_decode((string) $r->getContent(), true)['features'], null, 'key');
        $this->assertDatedEntitlement($featuresAfterPut['module.boussole']['entitlement'], $expectedEndsAt, $expectedIso, $endInstant);
        $this->assertEndlessOrEmptyEntitlement($featuresAfterPut['module.landing']['entitlement'], true);
        $this->assertNull($featuresAfterPut['module.memoires']['entitlement']);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function assertDatedEntitlement(array $item, string $expectedEndsAt, string $expectedIso, \DateTimeImmutable $expectedInstant): void
    {
        $this->assertSame($expectedEndsAt, $item['endsAt'], 'endsAt inchangé au caractère près');
        $this->assertArrayHasKey('endsAtIso', $item);
        $this->assertSame($expectedIso, $item['endsAtIso']);
        $this->assertMatchesRegularExpression('/[+-]\d{2}:\d{2}$/', (string) $item['endsAtIso']);
        $parsed = new \DateTimeImmutable((string) $item['endsAtIso']);
        $this->assertSame($expectedInstant->getTimestamp(), $parsed->getTimestamp(), 'instant identique à celui écrit en base');
        $this->assertEquals($expectedInstant, $parsed);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function assertEndlessOrEmptyEntitlement(array $item, ?bool $expectedEnabled = null): void
    {
        if ($expectedEnabled !== null && array_key_exists('enabled', $item)) {
            $this->assertSame($expectedEnabled, $item['enabled']);
        }
        $this->assertArrayHasKey('endsAt', $item);
        $this->assertArrayHasKey('endsAtIso', $item);
        $this->assertNull($item['endsAt']);
        $this->assertNull($item['endsAtIso']);
    }

    private function cleanMaster(): void
    {
        foreach (['tenant_entitlement_events', 'tenant_entitlements', 'tenant_feature_requests'] as $table) {
            $this->master->prepare("DELETE FROM $table WHERE tenant_code = ?")->execute([MV_TEST_TENANT_CODE]);
        }
    }

    private function api(string $method, string $path, ?array $body, string $as): \Symfony\Component\HttpFoundation\Response
    {
        $server = [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token($as),
        ];
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function token(string $as): string
    {
        $container = static::getContainer();
        $container->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $container->get(TenantEntityManagerProvider::class)->getEntityManager();
        $email = $as === 'super' ? 'super-admin-droits-iso@example.invalid' : 'admin@arkanoa-media.com';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $this->assertSame('super', $as);
            $user = (new User())
                ->setEmail($email)
                ->setUsername($email)
                ->setFirstname('Super')
                ->setLastname('DroitsIso')
                ->setRoles(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'])
                ->setPassword('inutilisable')
                ->setIsVerified(true);
            $em->persist($user);
            $em->flush();
        }

        return $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}

