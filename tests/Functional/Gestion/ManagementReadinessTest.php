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
        // aucune clé d'application « admin » : l'étape a été retirée le 12/11/2026 (le code court et un compte administrateur suffisent)
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

    /**
     * Les étapes `management.ai` et `ai.credits` comptent les crédits IA comme le quota qui les applique (12/11/2026) : avant,
     * `management.ai` remplaçait un forfait réglé à 0 par le forfait par défaut et annonçait des crédits que le quota refusait.
     */
    public function testBothAiStepsCountTheCreditsLikeTheQuotaDoes(): void
    {
        $this->grant();
        $savedSetting = $this->db->fetchAllAssociative('SELECT * FROM ai_credit_setting ORDER BY id');
        $savedUsage = (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM ai_usage');
        $quota = fn (): array => array_intersect_key(static::getContainer()->get(\App\Services\LandingAiService\LandingAiQuotaService::class)->credits(), ['monthly' => 1, 'used' => 1, 'remaining' => 1]);
        $steps = fn (): array => array_column($this->readiness()['steps'], null, 'key');
        $figures = fn (array $all): array => ['monthly' => $all['management.ai']['figures']['monthly'], 'remaining' => $all['management.ai']['figures']['remaining']];
        $usage = fn (string $status, int $credits, string $createdAt, ?string $reservedUntil = null) => $this->db->executeStatement(
            "INSERT INTO ai_usage (id, tenant, created_at, mode, component_key, status, credits, attempts, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, prompt_excerpt, reserved_until)
             VALUES (nextval('ai_usage_id_seq'), ?, $createdAt, 'edit', 'test_che', ?, ?, 1, 0, 0, 0, 0, 'test', " . ($reservedUntil ?? 'NULL') . ')', [MV_TEST_TENANT_CODE, $status, $credits]);
        try {
            // Forfait réglé à 0 : aucun crédit, pour les deux étapes comme pour le quota
            $this->db->executeStatement('DELETE FROM ai_credit_setting');
            $this->db->executeStatement("INSERT INTO ai_credit_setting (id, monthly_credits) VALUES (nextval('ai_credit_setting_id_seq'), 0)");
            $all = $steps();
            $this->assertSame(['monthly' => 0, 'remaining' => 0], $figures($all), 'avant : le forfait par défaut, alors que le quota refuse');
            $this->assertSame('todo', $all['management.ai']['status']);
            $this->assertStringContainsString('Aucun crédit IA ce mois-ci', $all['ai.credits']['description']);
            $this->assertSame(0, $quota()['monthly']);

            // Aucun réglage : le forfait par défaut de la plateforme, des deux côtés
            $this->db->executeStatement('DELETE FROM ai_credit_setting');
            $default = static::getContainer()->get(\App\Services\Platform\PlatformModeService::class)->aiDefaultCredits();
            $this->assertSame($default, $figures($steps())['monthly']);
            $this->assertSame($default, $quota()['monthly']);

            // Consommation : réussies et réservations en cours comptent ; échec, réservation échue et mois de facturation précédent non
            $this->db->executeStatement("INSERT INTO ai_credit_setting (id, monthly_credits) VALUES (nextval('ai_credit_setting_id_seq'), 40)");
            $before = $quota();
            $usage('success', 3, 'NOW()');
            $usage('reserved', 2, 'NOW()', "NOW() + INTERVAL '10 minutes'");
            $usage('reserved', 5, 'NOW()', "NOW() - INTERVAL '10 minutes'");
            $usage('failed', 7, 'NOW()');
            $usage('success', 11, "date_trunc('month', NOW() AT TIME ZONE 'UTC') + INTERVAL '2 hours'"); // 2 h UTC le 1er : avant le début du mois de facturation (heure de Toronto)
            $after = $quota();
            $this->assertSame(40, $after['monthly']);
            $this->assertContains($after['used'] - $before['used'], [5, 16], 'réussie 3 + réservée en cours 2 ; + 11 seulement dans les premières heures UTC du mois');
            $all = $steps();
            $this->assertSame(['monthly' => $after['monthly'], 'remaining' => $after['remaining']], $figures($all), 'management.ai : les chiffres du quota');
            // la formulation dépend du reste (« Il reste N crédit(s) sur 40 » quand il est bas), et le reste de ce que les tests d'avant ont consommé ce mois-ci : seuls les chiffres comptent ici
            $this->assertMatchesRegularExpression(sprintf('/(^|\D)%d crédit\(s\)( restant\(s\))? sur 40\b/u', $after['remaining']), $all['ai.credits']['description'], 'ai.credits : les mêmes chiffres');
            $this->assertStringContainsString(sprintf('%d crédit(s) IA restant(s) sur 40', $after['remaining']), $all['management.ai']['description']);

            // Consommation au-dessus du forfait : il reste 0, jamais un nombre négatif
            $this->db->executeStatement('UPDATE ai_credit_setting SET monthly_credits = 1');
            $this->assertSame(['monthly' => 1, 'remaining' => 0], $figures($steps()));
            $this->assertSame(0, $quota()['remaining']);
        } finally {
            $this->db->executeStatement('DELETE FROM ai_usage WHERE id > ?', [$savedUsage]);
            $this->db->executeStatement('DELETE FROM ai_credit_setting');
            foreach ($savedSetting as $row) {
                $this->db->insert('ai_credit_setting', $row);
            }
        }
    }

    public function testReadinessLocaleTranslatesOnlyManagementSupportAndAiAndDefaultsToFrenchEvenOnEnglishSite(): void
    {
        $savedLocale = $this->db->fetchOne('SELECT locale FROM entreprise ORDER BY id LIMIT 1');
        $savedSupportFlag = (bool) $this->db->fetchOne('SELECT is_support_active FROM entreprise ORDER BY id LIMIT 1');
        $entitlements = static::getContainer()->get(EntitlementService::class);
        $grantedSupportId = null;
        $grantedShopId = null;
        $savedShopFlag = (bool) $this->db->fetchOne('SELECT is_boutique_active FROM entreprise ORDER BY id LIMIT 1');

        try {
            $this->db->executeStatement("UPDATE entreprise SET locale = 'en', fiscal_year_start_month = 4");
            $beforeFr = array_column(json_decode($this->readinessRaw(), true)['steps'], null, 'key');
            $beforeEn = array_column(json_decode($this->readinessRaw('?locale=en'), true)['steps'], null, 'key');
            $this->assertSame($beforeFr['obtain.app.management'], $beforeEn['obtain.app.management'], 'obtain.app.management reste en français');

            $this->grant();
            if (!$entitlements->isEnabled(MV_TEST_TENANT_CODE, 'module.support')) {
                $grantedSupportId = (int) $entitlements->grant(MV_TEST_TENANT_CODE, 'module.support', 'manual', null, 'test', 'CHE-LEX', null, null, false)['id'];
            }

            // Un groupe hors des trois, qui possède un libellé anglais au catalogue : il doit rester en français (ajout de la relecture)
            if (!$entitlements->isEnabled(MV_TEST_TENANT_CODE, 'module.boutique')) {
                $grantedShopId = (int) $entitlements->grant(MV_TEST_TENANT_CODE, 'module.boutique', 'manual', null, 'test', 'CHE-LEX', null, null, false)['id'];
            }
            $rawDefault = $this->readinessRaw();
            $this->assertSame($rawDefault, $this->readinessRaw('?locale=fr'));
            $this->assertSame($rawDefault, $this->readinessRaw('?locale=de'));
            $this->assertSame($rawDefault, $this->readinessRaw('?locale='));

            $fr = json_decode($rawDefault, true);
            $frGroups = array_column($fr['groups'], null, 'key');
            $this->assertSame('Application de gestion', $frGroups['app.management']['label']);
            $this->assertSame('Assistance (demandes des clients)', $frGroups['module.support']['label']); // libellé français de FeatureCatalog (attente corrigée à la relecture)
            $this->assertSame('Assistant IA', $frGroups['ai']['label']);
            $this->assertSame('Site : identité et socle', $frGroups['site']['label']);

            $rawEn = $this->readinessRaw('?locale=en');
            $this->assertSame($rawEn, $this->readinessRaw('?locale=EN'));
            $en = json_decode($rawEn, true);
            $enGroups = array_column($en['groups'], null, 'key');

            $this->assertSame(array_keys($frGroups), array_keys($enGroups));
            $this->assertSame('Management app', $enGroups['app.management']['label']);
            $this->assertSame('Customer support', $enGroups['module.support']['label']);
            $this->assertSame('AI assistant', $enGroups['ai']['label']);
            $this->assertArrayHasKey('module.boutique', $frGroups, 'un groupe hors des trois est bien présent');
            $this->assertNotSame(\App\Services\FeatureAccess\FeatureCatalog::label('module.boutique', 'en'), $frGroups['module.boutique']['label'], 'et il a un libellé anglais différent au catalogue');
            foreach ($frGroups as $key => $group) {
                if (!in_array($key, ['app.management', 'module.support', 'ai'], true)) {
                    $this->assertSame($group['label'], $enGroups[$key]['label'], "$key group label reste en français");
                }
            }

            $this->assertCount(count($fr['steps']), $en['steps']);
            $stripText = static fn (array $s): array => array_diff_key($s, ['title' => 1, 'description' => 1]);
            foreach ($fr['steps'] as $i => $frStep) {
                $enStep = $en['steps'][$i];
                $this->assertSame($stripText($frStep), $stripText($enStep), $frStep['key']);
                $inScope = in_array($frStep['group'], ['app.management', 'module.support', 'ai'], true) && !str_starts_with($frStep['key'], 'obtain.');
                if ($inScope) {
                    $this->assertNotSame($frStep['title'], $enStep['title'], $frStep['key'] . ' title traduit');
                    $this->assertNotSame($frStep['description'], $enStep['description'], $frStep['key'] . ' description traduite');
                } else {
                    $this->assertSame($frStep['title'], $enStep['title'], $frStep['key'] . ' title reste FR');
                    $this->assertSame($frStep['description'], $enStep['description'], $frStep['key'] . ' description reste FR');
                }
            }

            $enByKey = array_column($en['steps'], null, 'key');
            $this->assertSame('Site short code', $enByKey['management.short_code']['title']);
            $this->assertSame('Fiscal year starting in April: used for the annual review.', $enByKey['management.fiscal_year']['description']);
            $this->assertSame('An account to answer requests', $enByKey['support.agent']['title']);
            $this->assertSame('Monthly AI credits', $enByKey['ai.credits']['title']);
            $this->assertSame($fr['links'], $en['links']);

            $this->assertSame('Site : identité et socle', \App\Services\Platform\SiteReadinessService::groupLabel('site', 'en'));
            $this->assertSame('Management app', \App\Services\Platform\SiteReadinessService::groupLabel('app.management', 'en'));
            $this->assertSame('Customer support', \App\Services\Platform\SiteReadinessService::groupLabel('module.support', 'en'));
            $this->assertSame('AI assistant', \App\Services\Platform\SiteReadinessService::groupLabel('ai', 'en'));
            $this->assertSame('Application de gestion', \App\Services\Platform\SiteReadinessService::groupLabel('app.management', 'de'));
        } finally {
            $this->db->executeStatement('UPDATE entreprise SET locale = ?', [$savedLocale]);
            if ($grantedShopId !== null) {
                $entitlements->cancel($grantedShopId, 'test', 'CHE-LEX');
                $this->master->prepare("DELETE FROM tenant_entitlement_events WHERE entitlement_id IN (SELECT id FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'module.boutique' AND note LIKE '%CHE-LEX%')")->execute([MV_TEST_TENANT_CODE]);
                $this->master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'module.boutique' AND note LIKE '%CHE-LEX%'")->execute([MV_TEST_TENANT_CODE]);
                $this->db->executeStatement('UPDATE entreprise SET is_boutique_active = ?', [$savedShopFlag], [\Doctrine\DBAL\ParameterType::BOOLEAN]);
            }
            if ($grantedSupportId !== null) {
                $entitlements->cancel($grantedSupportId, 'test', 'CHE-LEX');
                $this->master->prepare("DELETE FROM tenant_entitlement_events WHERE entitlement_id IN (SELECT id FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'module.support' AND note LIKE '%CHE-LEX%')")->execute([MV_TEST_TENANT_CODE]);
                $this->master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'module.support' AND note LIKE '%CHE-LEX%'")->execute([MV_TEST_TENANT_CODE]);
                $this->db->executeStatement('UPDATE entreprise SET is_support_active = ?', [$savedSupportFlag], [\Doctrine\DBAL\ParameterType::BOOLEAN]);
            }
        }
    }

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
        return json_decode($this->readinessRaw(), true);
    }

    private function readinessRaw(string $query = ''): string
    {
        $container = static::getContainer();
        $user = $container->get(TenantEntityManagerProvider::class)->getEntityManager()->getRepository(User::class)->findOneBy(['email' => 'admin@arkanoa-media.com']);
        $this->assertNotNull($user);
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/site/readiness' . $query, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $container->get('lexik_jwt_authentication.jwt_manager')->create($user)]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (string) $this->client->getResponse()->getContent();
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
