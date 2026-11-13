<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Entreprise;
use App\Entity\User;
use App\Gestion\Services\GestionModuleTransitionService;
use App\Services\FeatureAccess\ModuleAccessService;
use App\Services\Platform\PlatformModeService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot MOD (11/11/2026, contrat validé par l'application) : l'application de gestion devient un module activable (droit app.management).
 * Garde « management » : mêmes préfixes que la règle ROLE_ADMIN de la gestion dans security.yaml, sauf la caisse (/api/caisse, statistiques
 * /pos), vérifiée route par route sur tout le routeur ; seulement en mode plateforme live ; 403 MODULE_INACTIVE avec la clé à demander,
 * « Demander » possible ou non, demande en cours, essai ; autres modules inchangés ; unités IA (GET /api/gestion/ia) ; transition.
 */
class ManagementModuleApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private TenantEntityManagerProvider $provider;
    private string $adminToken = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'module-gestion-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $admin = (new User())->setEmail($email)->setUsername($email)->setFirstname('Module')->setLastname('Gestion')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('x');
        $this->provider->getEntityManager()->persist($admin);
        $this->provider->getEntityManager()->flush();
        $this->adminToken = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($admin);
        $this->cleanMaster();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(PlatformModeService::class)->setMode('demo', 'test');
        $this->setManagement(false);
        $this->cleanMaster();
        parent::tearDown();
    }

    public function testPathMapFollowsTheSecurityRuleRouteByRouteExceptTheCashRegister(): void
    {
        // Règle ROLE_ADMIN de la gestion lue dans security.yaml : toute dérive de cette règle fait échouer le test
        $rule = null;
        foreach (file(static::getContainer()->getParameter('kernel.project_dir') . '/config/packages/security.yaml') as $line) {
            if (str_contains($line, 'notes-de-frais|') && preg_match("/path: (\\^\\/api\\/\\([^)]+\\))/", $line, $m)) {
                $rule = '#' . $m[1] . '#';
            }
        }
        $this->assertNotNull($rule, 'règle de la gestion trouvée dans security.yaml');
        $cash = fn (string $path) => (bool) preg_match('#^/api/caisse(/|$)|^/api/statistiques/(chiffre-affaires|nombre-commandes|panier-moyen)/pos(/|$)|^/api/payments/statistics/pos(/|$)#', $path);
        $guarded = 0;
        foreach (static::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            $path = preg_replace('#\{[^}]+\}#', '1', $route->getPath());
            if (!str_starts_with($path, '/api/')) {
                continue;
            }
            foreach ($route->getMethods() ?: ['GET'] as $method) {
                $expected = preg_match($rule, $path) && !$cash($path);
                $module = ModuleAccessService::moduleForPath($path, $method);
                if ($expected) {
                    $this->assertSame(ModuleAccessService::MANAGEMENT, $module, "$method $path ($name) : route de la gestion");
                    ++$guarded;
                } else {
                    $this->assertNotSame(ModuleAccessService::MANAGEMENT, $module, "$method $path ($name) : hors gestion");
                }
            }
        }
        $this->assertGreaterThan(100, $guarded, 'toutes les routes de la gestion');
        foreach (['/api/caisse/open', '/api/caisse/report/x', '/api/statistiques/chiffre-affaires/pos', '/api/statistiques/nombre-commandes/pos', '/api/payments/statistics/pos', '/api/orders', '/api/order/create-multi-payment', '/api/pos/quote'] as $free) {
            $this->assertNotSame(ModuleAccessService::MANAGEMENT, ModuleAccessService::moduleForPath($free, 'GET'), "$free : jamais gardée par la gestion");
        }
        foreach (['/api/gestion/ia', '/api/periodes/courante', '/api/statistiques/chiffre-affaires', '/api/payments/statistics', '/api/frais-de-port/1', '/api/commandes'] as $path) {
            $this->assertSame(ModuleAccessService::MANAGEMENT, ModuleAccessService::moduleForPath($path, 'GET'), $path);
        }
        $this->assertSame(ModuleAccessService::BOUTIQUE, ModuleAccessService::moduleForPath('/api/cart/quote', 'POST'), 'autres modules inchangés');
    }

    public function testLiveModeGuardsTheManagementAppWithRequestHintsAndLeavesOtherModulesAlone(): void
    {
        $others = fn () => [$this->call('GET', '/api/boussole/referentials')->getStatusCode(), $this->call('GET', '/api/memoires/book-types')->getStatusCode(),
            $this->call('POST', '/api/cart/quote', ['items' => []])->getStatusCode(), $this->call('GET', '/api/landingpage-ai/usage')->getStatusCode(),
            $this->call('GET', '/api/caisse/denominations', null, true)->getStatusCode(), $this->call('GET', '/api/statistiques/chiffre-affaires/pos', null, true)->getStatusCode()];
        // Mode demo (plateforme actuelle) : module éteint mais tout est servi
        $this->setManagement(false);
        $this->assertSame(200, $this->call('GET', '/api/gestion/ia', null, true)->getStatusCode(), 'mode demo : rien de gardé');

        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->setManagement(true);
        $onOthers = $others();
        $this->assertSame(200, $this->call('GET', '/api/gestion/ia', null, true)->getStatusCode(), 'live, module allumé : servi');
        $this->setManagement(false);
        $r = $this->call('GET', '/api/gestion/ia', null, true);
        $body = json_decode((string) $r->getContent(), true);
        $this->assertSame([403, 'MODULE_INACTIVE', 'MODULE_INACTIVE', 'management', 'app.management', true, null, ['allowed' => true, 'days' => 30]],
            [$r->getStatusCode(), $body['error'], $body['code'], $body['module'], $body['feature'], $body['requestable'], $body['request'], $body['trial']]);
        $this->assertStringContainsString('Application de gestion', $body['message']);
        $this->assertSame(403, $this->call('GET', '/api/periodes/courante', null, true)->getStatusCode());
        $this->assertSame(403, $this->call('POST', '/api/gestion/promotions/variantes/apercu', [], false)->getStatusCode(), 'avant le pare-feu : même réponse sans jeton');
        $this->assertSame($onOthers, $others(), 'autres modules et caisse : mêmes réponses, gestion allumée ou éteinte');

        // Demande en attente : « Demander » n'est plus proposé ; essai déjà consommé : plus d'essai
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $master->prepare("INSERT INTO tenant_feature_requests (tenant_code, feature_key, requested_by, status, created_at) VALUES (?, 'app.management', 'test', 'pending', NOW())")->execute([MV_TEST_TENANT_CODE]);
        $body = json_decode((string) $this->call('GET', '/api/gestion/ia', null, true)->getContent(), true);
        $this->assertSame([false, ['status' => 'pending']], [$body['requestable'], $body['request']]);
        $master->prepare("INSERT INTO tenant_entitlements (tenant_code, feature_key, source, status, starts_at, ends_at, created_at, updated_at) VALUES (?, 'app.management', 'trial', 'expired', NOW() - INTERVAL '40 days', NOW() - INTERVAL '10 days', NOW(), NOW())")->execute([MV_TEST_TENANT_CODE]);
        $body = json_decode((string) $this->call('GET', '/api/gestion/ia', null, true)->getContent(), true);
        $this->assertSame(['allowed' => false, 'days' => 0], $body['trial'], 'un seul essai par site');
    }

    public function testAiUsageRouteReportsTierCreditsCostsAndTodayLimits(): void
    {
        $u = json_decode((string) $this->call('GET', '/api/gestion/ia', null, true)->getContent(), true);
        $this->assertSame(['tier', 'credits', 'costs', 'limits', 'today'], array_keys($u));
        $this->assertContains($u['tier'], ['none', 'help', 'full']);
        $this->assertSame(['monthly', 'used', 'remaining', 'resetAt'], array_keys(array_intersect_key($u['credits'], array_flip(['monthly', 'used', 'remaining', 'resetAt']))));
        $this->assertSame([['advice' => 1, 'comment' => 1, 'document' => 1, 'console' => 1], ['commentsPerDay' => 10, 'adviceRefreshPerDay' => 3]], [$u['costs'], $u['limits']]);
        $this->assertSame(['comments', 'adviceRefresh'], array_keys($u['today']));
        $site = json_decode((string) $this->call('GET', '/api/site/assistant/credits', null, true)->getContent(), true);
        $this->assertSame($site['credits']['remaining'] ?? null, $u['credits']['remaining'], 'même réserve que l\'assistant du site');
        $this->assertSame(401, $this->call('GET', '/api/gestion/ia')->getStatusCode());
    }

    public function testTransitionGrantsTheModuleToSitesThatUseItAndIsReplayable(): void
    {
        $conn = $this->provider->getEntityManager()->getConnection();
        $conn->executeStatement("INSERT INTO note_de_frais (id, name, montant, date) VALUES (nextval('note_de_frais_id_seq'), 'Transition MOD', 100, CURRENT_DATE)");
        $transition = static::getContainer()->get(GestionModuleTransitionService::class);
        $dry = $transition->run(false, MV_TEST_TENANT_CODE)[0];
        $this->assertSame(['to_grant', false], [$dry['action'], $dry['alreadyEnabled']]);
        $this->assertNotEmpty(array_filter($dry['reasons'], fn ($r) => str_contains($r, 'notes de frais')));
        $this->assertFalse($this->managementFlag(), 'simulation : rien d\'écrit');
        $this->assertSame('granted', $transition->run(true, MV_TEST_TENANT_CODE, 'test')[0]['action']);
        $this->assertTrue($this->managementFlag(), 'droit projeté sur la fiche');
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $row = $master->query(sprintf("SELECT source, ends_at, note FROM tenant_entitlements WHERE tenant_code = %s AND feature_key = 'app.management' AND status = 'active'", $master->quote(MV_TEST_TENANT_CODE)))->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['manual', null], [$row['source'], $row['ends_at']]);
        $this->assertStringStartsWith(GestionModuleTransitionService::NOTE, $row['note']);
        $this->assertSame('already', $transition->run(true, MV_TEST_TENANT_CODE, 'test')[0]['action'], 'rejouable sans effet');
        $conn->executeStatement("DELETE FROM note_de_frais WHERE name = 'Transition MOD'");
    }

    public function testDailyNotificationsSkipASiteWhoseModuleIsOffInLiveModeOnly(): void
    {
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel);
        $run = function () use ($application): string {
            $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find('app:gestion:notifications:envoyer'));
            $tester->execute(['--tenant' => MV_TEST_TENANT_CODE, '--force' => true]);

            return $tester->getDisplay();
        };
        $this->setManagement(false);
        $this->assertStringNotContainsString('non activée', $run(), 'mode demo : passage normal');
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->assertStringContainsString('application de gestion non activée, aucune notification', $run());
        $this->setManagement(true);
        $this->assertStringNotContainsString('non activée', $run(), 'module allumé : passage normal');
    }

    /** Avis sur l'année et préparation (store) : un refus faute de crédits ou de palier n'est ni gardé ni compté ; un avis rédigé l'est */
    public function testRefusedGuideAnswersAreNeitherStoredNorCounted(): void
    {
        $conn = $this->provider->getEntityManager()->getConnection();
        $pid = (int) $conn->fetchOne("INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, '2003-01-01', '2003-03-31', false, 'MOD', false, 'period', ?) RETURNING id", ['mod-' . bin2hex(random_bytes(3))]);
        $period = $this->provider->getEntityManager()->find(\App\Entity\Collections::class, $pid);
        $service = static::getContainer()->get(\App\Gestion\Services\GestionSimulationCommentService::class);
        $count = fn () => (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_simulation_comment WHERE period_id = ?', [$pid]);
        $service->store($period, 'h1', '2003-01-15', ['ai' => ['status' => 'credits_exhausted']], 'credits_exhausted', null, null);
        $service->store($period, 'h2', '2003-01-15', ['ai' => ['status' => 'not_included']], 'not_included', null, null);
        $this->assertSame(0, $count(), 'refus : rien de gardé');
        $service->store($period, 'h3', '2003-01-15', ['ai' => ['status' => 'ok']], 'ok', null, null);
        $this->assertSame(1, $count());
        $conn->executeStatement('DELETE FROM gestion_simulation_comment WHERE period_id = ?', [$pid]);
        $conn->executeStatement('DELETE FROM collections WHERE id = ?', [$pid]);
    }

    // ----- helpers -----

    private function cleanMaster(): void
    {
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $master->prepare("DELETE FROM tenant_feature_requests WHERE tenant_code = ? AND feature_key = 'app.management'")->execute([MV_TEST_TENANT_CODE]);
        $master->prepare("DELETE FROM tenant_entitlement_events WHERE entitlement_id IN (SELECT id FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'app.management')")->execute([MV_TEST_TENANT_CODE]);
        $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'app.management'")->execute([MV_TEST_TENANT_CODE]);
    }

    /** Écriture Doctrine (vide le cache des indicateurs de modules) */
    private function setManagement(bool $on): void
    {
        $em = $this->provider->getEntityManager();
        $em->clear();
        $em->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC'])->setIsManagementAppActive($on);
        $em->flush();
    }

    private function managementFlag(): bool
    {
        return in_array($this->provider->getEntityManager()->getConnection()->fetchOne('SELECT is_management_app_active FROM entreprise ORDER BY id LIMIT 1'), [true, 't', 1, '1'], true);
    }

    private function call(string $method, string $path, ?array $body = null, bool $admin = false): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $admin ? 'Bearer ' . $this->adminToken : null,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }
}
