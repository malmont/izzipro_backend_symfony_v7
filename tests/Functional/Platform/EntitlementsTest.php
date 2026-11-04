<?php

namespace App\Tests\Functional\Platform;

use App\Entity\User;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\FeaturePolicyRepository;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Droits d'usage (console de pilotage, lot 3, 04/11/2026) : accorder, essai, suspendre, rétablir, annuler ; projection sur la fiche
 * Entreprise du site ; courriels au contact du site ; balayage des échéances et rappels ; politiques ; alignement depuis la fiche ;
 * demandes du site ; amorçage idempotent.
 */
class EntitlementsTest extends WebTestCase
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
        $this->flagsBefore = $this->db->fetchAssociative('SELECT ' . implode(', ', FeatureCatalog::GOVERNED) . ' FROM entreprise ORDER BY id LIMIT 1');
        $this->cleanMaster();
    }

    protected function tearDown(): void
    {
        $sets = [];
        $params = [];
        foreach (FeatureCatalog::GOVERNED as $column) {
            $sets[] = "$column = ?";
            $params[] = (bool) $this->flagsBefore[$column];
        }
        $this->db->executeStatement('UPDATE entreprise SET ' . implode(', ', $sets) . ' WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)', $params, array_fill(0, count($params), \Doctrine\DBAL\ParameterType::BOOLEAN));
        $this->cleanMaster();
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testGrantSuspendResumeCancelProjectTheFlagAndNotifyTheSite(): void
    {
        $s = $this->service();
        $this->setFlag('is_boussole_esg_active', false);
        $this->assertFalse($s->isEnabled(MV_TEST_TENANT_CODE, 'module.boussole'));

        $row = $s->grant(MV_TEST_TENANT_CODE, 'module.boussole', 'manual', null, 'proprietaire@example.invalid', 'offert');
        $this->assertSame(['active', 'manual', null], [$row['status'], $row['source'], $row['ends_at']]);
        $this->assertTrue($s->isEnabled(MV_TEST_TENANT_CODE, 'module.boussole'));
        $this->assertTrue($this->flag('is_boussole_esg_active'), 'droit projeté sur la fiche Entreprise');
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertStringContainsString('Boussole ESG', (string) $email->getSubject());
        $expected = $this->db->fetchOne('SELECT email FROM entreprise ORDER BY id LIMIT 1') ?: $this->db->fetchOne('SELECT from_email FROM email_configuration ORDER BY id LIMIT 1');
        $this->assertSame($expected, $email->getTo()[0]->getAddress(), 'contact du site, sinon expéditeur configuré');

        $second = $s->grant(MV_TEST_TENANT_CODE, 'module.boussole', 'free', null, 'proprietaire@example.invalid');
        $this->assertSame('cancelled', $s->find((int) $row['id'])['status'], 'un seul droit vivant par fonctionnalité : le précédent est remplacé');
        $this->assertSame('free', $s->effective(MV_TEST_TENANT_CODE)['module.boussole']['source']);

        $s->suspend((int) $second['id'], 'proprietaire@example.invalid', 'impayé');
        $this->assertFalse($s->isEnabled(MV_TEST_TENANT_CODE, 'module.boussole'));
        $this->assertFalse($this->flag('is_boussole_esg_active'));
        $s->resume((int) $second['id'], 'proprietaire@example.invalid');
        $this->assertTrue($this->flag('is_boussole_esg_active'));
        $s->cancel((int) $second['id'], 'proprietaire@example.invalid', 'fin');
        $this->assertFalse($this->flag('is_boussole_esg_active'));
        $this->assertSame(['granted', 'granted', 'suspended', 'resumed', 'cancelled'], array_reverse(array_column(array_filter($this->events(), fn ($e) => $e['event'] !== 'cancelled' || (int) $e['entitlement_id'] === (int) $second['id']), 'event')), 'journal des événements');
    }

    public function testTrialsExpireRemindAndFollowThePolicy(): void
    {
        $s = $this->service();
        $this->setFlag('is_memoire_vivante_active', false);
        $trial = $s->trial(MV_TEST_TENANT_CODE, 'module.memoires', 10, 'proprietaire@example.invalid');
        $this->assertSame('trial', $trial['source']);
        $this->assertEqualsWithDelta(time() + 10 * 86400, strtotime($trial['ends_at']), 120);
        $this->assertTrue($this->flag('is_memoire_vivante_active'));
        $this->assertEmailCount(1);

        // rappel à J-7 une seule fois
        $this->master->prepare("UPDATE tenant_entitlements SET ends_at = NOW() + INTERVAL '5 days' WHERE id = ?")->execute([$trial['id']]);
        $this->assertSame(['expired' => 0, 'suspended' => 0, 'reminded' => 1], $s->sweep());
        $this->assertSame(['expired' => 0, 'suspended' => 0, 'reminded' => 0], $s->sweep(), 'rappel déjà envoyé');
        $this->assertEmailCount(2);

        // échéance dépassée : expiré, indicateur retombé, courriel
        $this->master->prepare("UPDATE tenant_entitlements SET ends_at = NOW() - INTERVAL '1 minute' WHERE id = ?")->execute([$trial['id']]);
        $this->assertSame(1, $s->sweep()['expired']);
        $this->assertSame('expired', $s->find((int) $trial['id'])['status']);
        $this->assertFalse($this->flag('is_memoire_vivante_active'));
        $this->assertFalse($s->isEnabled(MV_TEST_TENANT_CODE, 'module.memoires'));
        $this->assertEmailCount(3);

        // lecture paresseuse : un droit échu non balayé est expiré à la lecture
        $late = $s->grant(MV_TEST_TENANT_CODE, 'module.memoires', 'manual', (new \DateTimeImmutable())->modify('+1 day'), 'test');
        $this->master->prepare("UPDATE tenant_entitlements SET ends_at = NOW() - INTERVAL '1 minute' WHERE id = ?")->execute([$late['id']]);
        $this->assertFalse($s->isEnabled(MV_TEST_TENANT_CODE, 'module.memoires'));
        $this->assertSame('expired', $s->find((int) $late['id'])['status']);

        // politique : essai ou gratuité interdits
        $policies = static::getContainer()->get(FeaturePolicyRepository::class);
        $policies->upsert('module.landing', ['trial_allowed' => false, 'free_allowed' => false, 'trial_days' => 14], 'test');
        $this->assertSame([false, 14, false, 7], array_values(array_slice($policies->get('module.landing'), 1, 4)));
        try {
            $s->trial(MV_TEST_TENANT_CODE, 'module.landing', null, 'test');
            $this->fail('essai interdit');
        } catch (\DomainException) {
        }
        try {
            $s->free(MV_TEST_TENANT_CODE, 'module.landing', 'test');
            $this->fail('gratuité interdite');
        } catch (\DomainException) {
        }
        $this->master->exec("DELETE FROM platform_feature_policies WHERE feature_key = 'module.landing'");
        $this->assertSame(FeaturePolicyRepository::DEFAULTS['trial_days'], $policies->get('module.landing')['trial_days'], 'défauts sans ligne');
    }

    public function testFlagsWrittenBySuperAdminAreMirroredIntoEntitlementsAndSitesCanRequestAFeature(): void
    {
        $s = $this->service();
        $this->setFlag('is_boussole_esg_active', false);
        $this->assertFalse($s->isEnabled(MV_TEST_TENANT_CODE, 'module.boussole'));

        // demande d'un site
        $r = $this->api('POST', '/api/site/features/module.boussole/request', ['message' => 'Nous voulons la Boussole'], 'admin');
        $this->assertSame(201, $r->getStatusCode(), $r->getContent());
        $this->assertSame(['pending', 'Nous voulons la Boussole'], [json_decode((string) $r->getContent(), true)['request']['status'], json_decode((string) $r->getContent(), true)['request']['message']]);
        $this->assertSame(409, $this->api('POST', '/api/site/features/module.boussole/request', [], 'admin')->getStatusCode(), 'déjà en attente');
        $this->assertSame(422, $this->api('POST', '/api/site/features/site.locale/request', [], 'admin')->getStatusCode(), 'clé non plateforme');
        $this->assertSame(403, $this->api('POST', '/api/site/features/module.boussole/request', [], 'client')->getStatusCode());
        $features = array_column(json_decode((string) $this->api('GET', '/api/site/features', null, 'admin')->getContent(), true)['features'], null, 'key');
        $this->assertSame(['pending', null], [$features['module.boussole']['request'], $features['module.boussole']['entitlement']]);

        // super administrateur : activation par l'API → droit manuel ; désactivation → droit annulé
        $r = $this->api('PUT', '/api/site/features', ['values' => ['module.boussole' => true]], 'super');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $eff = $s->effective(MV_TEST_TENANT_CODE)['module.boussole'];
        $this->assertSame([true, 'manual', 'active'], [$eff['enabled'], $eff['source'], $eff['status']]);
        $features = array_column(json_decode((string) $r->getContent(), true)['features'], null, 'key');
        $this->assertSame('manual', $features['module.boussole']['entitlement']['source']);
        $this->assertSame(409, $this->api('POST', '/api/site/features/module.boussole/request', [], 'admin')->getStatusCode(), 'déjà active');
        $r = $this->api('PUT', '/api/site/features', ['values' => ['module.boussole' => false]], 'super');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $this->assertFalse($s->isEnabled(MV_TEST_TENANT_CODE, 'module.boussole'));
        $this->assertSame('cancelled', $s->find((int) $eff['id'])['status']);
    }

    public function testBootstrapCreatesMissingRightsFromFlagsAndIsIdempotent(): void
    {
        $this->setFlag('is_landing_page_active', true);
        $this->setFlag('is_boussole_esg_active', false);
        $tester = new CommandTester((new Application(static::$kernel))->find('app:entitlements:bootstrap'));
        $this->assertSame(0, $tester->execute(['--dry-run' => true]));
        $this->assertStringContainsString('(simulation)', $tester->getDisplay());
        $this->assertSame(0, $this->master->query("SELECT COUNT(*) FROM tenant_entitlements WHERE tenant_code = '" . MV_TEST_TENANT_CODE . "'")->fetchColumn(), 'simulation : rien écrit');
        $this->assertSame(0, $tester->execute([]));
        $eff = $this->service()->effective(MV_TEST_TENANT_CODE);
        $this->assertSame([true, 'manual'], [$eff['module.landing']['enabled'], $eff['module.landing']['source']]);
        $this->assertFalse($eff['module.boussole']['enabled']);
        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('0 droit(s) créé(s)', $tester->getDisplay(), 'idempotent');
    }

    private function service(): EntitlementService
    {
        return static::getContainer()->get(EntitlementService::class);
    }

    private function flag(string $column): bool
    {
        return (bool) $this->db->fetchOne("SELECT $column FROM entreprise ORDER BY id LIMIT 1");
    }

    private function setFlag(string $column, bool $value): void
    {
        $this->db->executeStatement("UPDATE entreprise SET $column = ? WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)", [$value], [\Doctrine\DBAL\ParameterType::BOOLEAN]);
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
    }

    private function events(): array
    {
        $stmt = $this->master->prepare('SELECT entitlement_id, event FROM tenant_entitlement_events WHERE tenant_code = ? ORDER BY id DESC');
        $stmt->execute([MV_TEST_TENANT_CODE]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function cleanMaster(): void
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
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path . (str_contains($path, '?') ? '&' : '?') . 'locale=fr', [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function token(string $as): string
    {
        $container = static::getContainer();
        $container->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $container->get(TenantEntityManagerProvider::class)->getEntityManager();
        $email = match ($as) { 'admin' => 'admin@arkanoa-media.com', 'super' => 'super-admin-entitlements@example.invalid', default => 'client-entitlements@example.invalid' };
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $this->assertNotSame('admin', $as);
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname(ucfirst($as))->setLastname('Test')
                ->setRoles($as === 'super' ? ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] : ['ROLE_USER_INTERNET'])->setPassword('inutilisable')->setIsVerified(true);
            $em->persist($user);
            $em->flush();
        }

        return $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
