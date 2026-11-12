<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\User;
use App\Services\Platform\EntitlementService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot CHE (12/11/2026, contrat validé par l'application) : cheminement de l'application de gestion dans le cheminement du site
 * (GET /api/site/readiness, groupe `app.management`) et page `/admin/gestion`. Sans module : « Obtenir » seulement, autres groupes inchangés ;
 * module accordé : étapes bloquantes (clé, code court, administrateur, période en cours, exercice, devise et fuseau) et améliorations, chacune
 * avec `appScreen` (code d'écran de l'application) et, quand c'est utile, `figures` et les chiffres dans `description`.
 */
class ManagementReadinessTest extends WebTestCase
{
    private const KEYS = ['management.short_code', 'management.admin', 'management.period', 'management.fiscal_year', 'management.locale',
        'management.periods_calendar', 'management.purchase_cost', 'management.suppliers', 'management.referentials', 'management.cash', 'management.fixed_costs',
        'management.ai', 'management.device', 'management.first_purchase'];

    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private \PDO $master;
    private array $saved = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(\App\Services\BoutiqueDemoService\BoutiqueDemoSeeder::class)->seed('client-cheminement@example.invalid', false, false); // articles du jeu d'exemple
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->saved = [
            'entreprise' => $this->db->fetchAssociative('SELECT fiscal_year_start_month, purchase_period_frequency FROM entreprise ORDER BY id LIMIT 1'),
            'shortCode' => $this->master->query(sprintf('SELECT short_code FROM tenants WHERE code = %s', $this->master->quote(MV_TEST_TENANT_CODE)))->fetchColumn(),
            'currentPeriods' => array_map('intval', $this->db->fetchFirstColumn("SELECT id FROM collections WHERE kind = 'period' AND COALESCE(del, false) = false AND start_date_collection <= NOW() AND end_date_collection >= CURRENT_DATE")),
        ];
        $this->cleanMaster();
    }

    protected function tearDown(): void
    {
        $e = $this->saved['entreprise'];
        $this->db->executeStatement('UPDATE entreprise SET fiscal_year_start_month = ?, purchase_period_frequency = ?', [$e['fiscal_year_start_month'], $e['purchase_period_frequency']]);
        $this->master->prepare('UPDATE tenants SET short_code = ? WHERE code = ?')->execute([$this->saved['shortCode'] ?: null, MV_TEST_TENANT_CODE]);
        $this->db->executeStatement("DELETE FROM collections WHERE nom_collection = 'CHE'");
        if ($this->saved['currentPeriods'] !== []) {
            $this->db->executeStatement('UPDATE collections SET del = false WHERE id IN (?)', [$this->saved['currentPeriods']], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        }
        $this->cleanMaster();
        $this->db->executeStatement('UPDATE entreprise SET is_management_app_active = false');
        parent::tearDown();
    }

    public function testWithoutTheModuleOnlyObtainShowsAndOtherGroupsStayIdentical(): void
    {
        $before = $this->readiness();
        $this->assertSame([], $this->managementSteps($before), 'module non accordé : aucune étape du groupe');
        $this->assertContains('obtain.app.management', array_column($before['steps'], 'key'));
        $this->grant();
        $after = $this->readiness();
        $this->assertSame(self::KEYS, array_column($this->managementSteps($after), 'key'), 'toutes les étapes, dans l\'ordre');
        $this->assertNotContains('obtain.app.management', array_column($after['steps'], 'key'));
        $others = fn (array $r) => array_values(array_filter($r['steps'], fn ($s) => $s['group'] !== 'app.management' && $s['key'] !== 'obtain.app.management'));
        $this->assertSame($others($before), $others($after), 'autres groupes identiques au tableau près (additif)');
        $group = array_values(array_filter($after['groups'], fn ($g) => $g['key'] === 'app.management'))[0];
        $this->assertSame([count(self::KEYS), 'Application de gestion'], [$group['total'], $group['label']]);
    }

    public function testBlockingStepsAreRedWithoutFiscalYearOrPeriodThenTheGroupTurnsGreen(): void
    {
        $this->grant();
        $this->db->executeStatement('UPDATE entreprise SET fiscal_year_start_month = NULL, purchase_period_frequency = NULL');
        if ($this->saved['currentPeriods'] !== []) {
            $this->db->executeStatement('UPDATE collections SET del = true WHERE id IN (?)', [$this->saved['currentPeriods']], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        }
        // une période supprimée qui couvre aujourd'hui ne compte pas
        $this->db->executeStatement("INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, CURRENT_DATE - 5, CURRENT_DATE + 30, true, 'CHE', false, 'period', ?)", ['che-del-' . bin2hex(random_bytes(3))]);
        $steps = array_column($this->managementSteps($this->readiness()), null, 'key');
        foreach (['management.fiscal_year', 'management.period'] as $key) {
            $this->assertSame(['todo', true], [$steps[$key]['status'], $steps[$key]['blocking']], "$key : rouge");
        }
        $this->assertSame(['todo', false, 'periodes'], [$steps['management.periods_calendar']['status'], $steps['management.periods_calendar']['blocking'], $steps['management.periods_calendar']['appScreen']], 'calendrier jamais choisi : orange');

        // Tout le bloquant réglé : code court, administrateur (le compte du modèle), période en cours, exercice, devise et fuseau
        // aucune clé d'application « admin » : l'étape a été retirée le 13/11/2026 (le code court et un compte administrateur suffisent)
        $this->master->prepare("DELETE FROM tenant_app_keys WHERE tenant_code = ? AND app = 'admin'")->execute([MV_TEST_TENANT_CODE]);
        $this->master->prepare('UPDATE tenants SET short_code = ? WHERE code = ?')->execute([$this->saved['shortCode'] ?: 'CHE042', MV_TEST_TENANT_CODE]);
        $this->db->executeStatement("INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, CURRENT_DATE - 5, CURRENT_DATE + 30, false, 'CHE', false, 'period', ?)", ['che-' . bin2hex(random_bytes(3))]);
        $this->db->executeStatement("UPDATE entreprise SET fiscal_year_start_month = 4, purchase_period_frequency = 'quarterly'");
        $body = $this->readiness();
        $steps = array_column($this->managementSteps($body), null, 'key');
        foreach (['management.short_code', 'management.admin', 'management.period', 'management.fiscal_year', 'management.locale', 'management.periods_calendar'] as $key) {
            $this->assertSame('done', $steps[$key]['status'], $key);
        }
        $this->assertStringContainsString('avril', $steps['management.fiscal_year']['description']);
        $group = array_values(array_filter($body['groups'], fn ($g) => $g['key'] === 'app.management'))[0];
        $this->assertSame([0, true], [$group['blockingTodo'], $group['ready']], 'groupe vert : plus rien d\'indispensable');
        $this->assertArrayNotHasKey('management.key', $steps, 'pas d\'étape « clé d\'activation » : le groupe est vert sans clé');
    }

    public function testPurchaseCostIsCountedAndEveryStepCarriesItsAppScreen(): void
    {
        $this->grant();
        $articles = "product p WHERE p.product_type = 'standard' AND COALESCE(p.rental_enabled, false) = false";
        $missing = (int) $this->db->fetchOne("SELECT COUNT(*) FROM $articles AND (p.purchase_price IS NULL OR p.purchase_price <= 0) AND NOT EXISTS (SELECT 1 FROM commande_line l WHERE l.product_id = p.id AND l.unit_cost_cents IS NOT NULL)");
        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM $articles");
        $steps = array_column($this->managementSteps($this->readiness()), null, 'key');
        $cost = $steps['management.purchase_cost'];
        $this->assertSame(['missing' => $missing, 'total' => $total], $cost['figures']);
        if ($missing > 0) {
            $this->assertStringContainsString(sprintf('%d article(s) sur %d sans coût d\'achat', $missing, $total), $cost['description'], 'le chiffre est dans le texte');
        }
        // un article sans coût (fiche et lignes) puis avec une ligne d'achat chiffrée : le compte suit, la ligne d'achat suffit
        $id = $this->db->fetchOne("SELECT p.id FROM $articles AND NOT EXISTS (SELECT 1 FROM commande_line l WHERE l.product_id = p.id AND l.unit_cost_cents IS NOT NULL) ORDER BY p.id LIMIT 1");
        $this->assertNotFalse($id, 'un article du jeu d\'exemple');
        $price = $this->db->fetchOne('SELECT purchase_price FROM product WHERE id = ?', [$id]);
        $this->db->executeStatement('UPDATE product SET purchase_price = NULL WHERE id = ?', [$id]);
        $commande = null;
        try {
            $without = array_column($this->managementSteps($this->readiness()), null, 'key')['management.purchase_cost'];
            $this->assertSame(['todo', false], [$without['status'], $without['blocking']]);
            $commande = (int) $this->db->fetchOne("INSERT INTO commande (id, budget, date, kind) VALUES (nextval('commande_id_seq'), 0, NOW(), 'purchase') RETURNING id");
            $this->db->executeStatement('INSERT INTO commande_line (commande_id, product_id, quantity_ordered, unit_cost_cents, attached_at, created_at, updated_at) VALUES (?, ?, 1, 500, NOW(), NOW(), NOW())', [$commande, $id]);
            $lined = array_column($this->managementSteps($this->readiness()), null, 'key')['management.purchase_cost'];
            $this->assertSame($without['figures']['missing'] - 1, $lined['figures']['missing'], 'coût de la ligne d\'achat : marge connue');
        } finally {
            if ($commande !== null) {
                $this->db->executeStatement('DELETE FROM commande_line WHERE commande_id = ?', [$commande]);
                $this->db->executeStatement('DELETE FROM commande WHERE id = ?', [$commande]);
            }
            $this->db->executeStatement('UPDATE product SET purchase_price = ? WHERE id = ?', [$price, $id]);
        }
        $this->assertSame(['management.short_code' => null, 'management.admin' => null, 'management.period' => 'periodes', 'management.fiscal_year' => 'exercice',
            'management.locale' => null, 'management.periods_calendar' => 'periodes', 'management.purchase_cost' => 'articles', 'management.suppliers' => 'fournisseurs', 'management.referentials' => null,
            'management.cash' => 'tresorerie', 'management.fixed_costs' => 'frais-fixes', 'management.ai' => null, 'management.device' => 'notifications', 'management.first_purchase' => 'commandes'],
            array_map(fn ($s) => $s['appScreen'], $steps), 'codes d\'écran de l\'application (vocabulaire des vignettes)');
        $this->assertSame(['tier', 'remaining', 'monthly'], array_keys($steps['management.ai']['figures']));
    }

    public function testAdminPageShowsTheStepsOrTheWayToGetTheModule(): void
    {
        $this->loginAdmin();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/gestion');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('data-gestion-not-granted', (string) $this->client->getResponse()->getContent(), 'sans module : essai ou demande');
        $this->grant();
        $this->loginAdmin();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/gestion');
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        foreach (self::KEYS as $key) {
            $this->assertStringContainsString('data-step="' . $key . '"', $html);
        }
    }

    // ----- helpers -----

    private function grant(): void
    {
        static::getContainer()->get(EntitlementService::class)->grant(MV_TEST_TENANT_CODE, 'app.management', 'manual', null, 'test', 'CHE', null, null, false);
    }

    private function cleanMaster(): void
    {
        $this->master->prepare("DELETE FROM tenant_entitlement_events WHERE entitlement_id IN (SELECT id FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'app.management')")->execute([MV_TEST_TENANT_CODE]);
        $this->master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'app.management'")->execute([MV_TEST_TENANT_CODE]);
        $this->master->prepare("DELETE FROM tenant_app_keys WHERE tenant_code = ? AND label = 'CHE'")->execute([MV_TEST_TENANT_CODE]);
    }

    /** @return list<array<string, mixed>> */
    private function managementSteps(array $readiness): array
    {
        return array_values(array_filter($readiness['steps'], fn ($s) => $s['group'] === 'app.management' && !str_starts_with($s['key'], 'obtain.')));
    }

    private function readiness(): array
    {
        $container = static::getContainer();
        $user = $container->get(TenantEntityManagerProvider::class)->getEntityManager()->getRepository(User::class)->findOneBy(['email' => 'admin@arkanoa-media.com']);
        $this->assertNotNull($user);
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/site/readiness', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $container->get('lexik_jwt_authentication.jwt_manager')->create($user)]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function loginAdmin(): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $email = 'che-admin-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Che')->setLastname('Admin')->setRoles(['ROLE_USER', 'ROLE_ADMIN'])->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        $this->client->loginUser($user, 'main');
        \App\Tests\Functional\Support\OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
