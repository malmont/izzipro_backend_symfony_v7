<?php

namespace App\Tests\Functional\Abo;

use App\Abo\Services\AboCatalogService;
use App\Controller\Admin\SubscriptionPlanCrudController;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\Platform\EntitlementService;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot ABO-CONF (19/11/2026, contrat validé par le chef) : formules et prestations créées et configurées par la gestion web, par le service
 * commun d'EasyAdmin et de l'assistant ; devise du site imposée ; formule branchée refusée ; aperçu sans écriture et phrase « ce que verra
 * l'abonné » ; prix modifié = nouveau prix Stripe pour les nouveaux abonnés seulement, historique des prix (et le même effet par EasyAdmin :
 * le défaut corrigé) ; suppression refusée avec abonnés, archivage ; prestation désactivée avec droits refusée ; liste complète des
 * prestations d'une formule ; assistant (propose_plan_update confirmé).
 */
class AboCatalogTest extends WebTestCase
{
    private KernelBrowser $client;
    private TenantEntityManagerProvider $provider;
    private \Doctrine\DBAL\Connection $db;
    private int $productId;
    private int $demoPlan;
    private bool $boutiqueBefore = true;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeLandingAiClient::reset();
        $this->provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-abo-conf@example.invalid', false, false);
        $this->db = $this->provider->getEntityManager()->getConnection();
        $this->demoPlan = (int) $this->db->fetchOne('SELECT sp.id FROM subscription_plan sp JOIN product p ON p.id = sp.product_id WHERE p.code = ? ORDER BY sp.id LIMIT 1', ['DEMO-PANIER']);
        $this->productId = (int) $this->db->fetchOne('SELECT product_id FROM subscription_plan WHERE id = ?', [$this->demoPlan]);
        $this->boutiqueBefore = in_array($this->db->fetchOne('SELECT is_boutique_active FROM entreprise ORDER BY id LIMIT 1'), [true, 't', 1, '1'], true);
        static::getContainer()->get(EntitlementService::class)->free(MV_TEST_TENANT_CODE, 'ai.full', 'test');
        $this->db->executeStatement('UPDATE entreprise SET is_boutique_active = TRUE'); // APRÈS l'octroi, qui recalcule les modules
        static::getContainer()->get(TenantCacheService::class)->invalidateTenant();
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement("DELETE FROM subscription WHERE plan_id IN (SELECT id FROM subscription_plan WHERE names::text LIKE '%abo-conf%')");
        $this->db->executeStatement('DELETE FROM plan_benefit');
        $this->db->executeStatement("DELETE FROM subscription_plan WHERE names::text LIKE '%abo-conf%'");
        $this->db->executeStatement('DELETE FROM subscription_plan_price');
        $this->db->executeStatement('DELETE FROM benefit');
        $this->db->executeStatement("DELETE FROM content_audit_log WHERE resource IN ('site_assistant', 'subscription_plan')");
        $this->db->executeStatement('UPDATE entreprise SET is_boutique_active = ?', [$this->boutiqueBefore], [\PDO::PARAM_BOOL]);
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key IN ('ai.help', 'ai.full')")->execute([MV_TEST_TENANT_CODE]);
        $master->prepare("INSERT INTO tenant_entitlements (tenant_code, feature_key, source, status, granted_by, note) VALUES (?, 'ai.full', 'manual', 'active', 'bootstrap', 'IA complète du site de test')")->execute([MV_TEST_TENANT_CODE]);
        $this->db->executeStatement('UPDATE entreprise SET is_ai_help_active = FALSE, is_ai_full_active = TRUE');
        static::getContainer()->get(TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testThePlansAreCreatedConfiguredRepricedArchivedAndNeverDeletedWithSubscribers(): void
    {
        $base = '/api/site/abonnements/formules';
        $good = ['productId' => $this->productId, 'names' => ['fr' => 'Panier abo-conf', 'en' => 'Basket abo-conf'], 'price' => 4900, 'interval' => 'month', 'trialDays' => 14, 'minimumTerms' => 3];
        // refus du service commun, avec leur code et leur champ
        foreach ([[['names' => ['fr' => ' ']] + $good, 'PLAN_NAME_REQUIRED'], [['price' => -1] + $good, 'PLAN_PRICE_INVALID'], [['interval' => 'day'] + $good, 'PLAN_INTERVAL_INVALID'],
            [['trialDays' => 400] + $good, 'PLAN_TRIAL_INVALID'], [['maxPauseDays' => 0] + $good, 'PLAN_PAUSE_INVALID'], [['productId' => 2147483000] + $good, 'PRODUCT_NOT_SUBSCRIBABLE'],
            [['fulfillmentType' => 'platform_offer'] + $good, 'PLAN_PLATFORM_ONLY'], [['currency' => 'EUR'] + $good, 'FIELD_UNKNOWN']] as [$body, $code]) {
            $this->assertSame($code, $this->json('POST', $base, $body, 'admin', 422)['code'], json_encode($body));
        }
        $count = fn (): int => (int) $this->db->fetchOne("SELECT COUNT(*) FROM subscription_plan WHERE names::text LIKE '%abo-conf%'");
        // aperçu : rien d'écrit ; la phrase « ce que verra l'abonné » par le serveur
        $preview = $this->json('POST', $base . '/apercu', $good, 'admin', 200);
        $this->assertSame(0, $count());
        $this->assertStringContainsString('par mois. Essai gratuit de 14 jours. Engagement de 3 périodes.', $preview['summary']);
        $this->assertStringContainsString('per month. Free trial of 14 days.', $this->json('POST', $base . '/apercu?locale=en', $good, 'admin', 200)['summary']);
        // création : la devise du site, une ligne d'historique des prix
        $created = $this->json('POST', $base, $good, 'admin', 201);
        $id = (int) $created['plan']['id'];
        $this->assertSame(['CAD', 4900, true, false], [$created['plan']['currency'], $created['plan']['price'], $created['plan']['active'], $created['hasSubscribers']]);
        $this->assertCount(1, $created['priceHistory']);
        $this->assertSame('create', $this->db->fetchOne("SELECT action FROM content_audit_log WHERE resource = 'subscription_plan' AND resource_id = ? ORDER BY id DESC LIMIT 1", [(string) $id]));
        // la formule a vendu (un prix Stripe existe) ; le prix change : nouveau prix pour les NOUVEAUX abonnés, l'ancien prix Stripe gardé à l'historique
        $this->db->executeStatement("UPDATE subscription_plan SET stripe_price_id = 'price_abo_conf_old', stripe_product_id = 'prod_abo_conf' WHERE id = ?", [$id]);
        $this->provider->getEntityManager()->clear();
        $this->assertStringContainsString('nouveaux abonnés seulement', (string) $this->json('POST', $base . '/apercu?planId=' . $id, ['price' => 5900], 'admin', 200)['priceChange']);
        $this->assertNull($this->json('POST', $base . '/apercu?planId=' . $id, ['names' => ['fr' => 'Panier abo-conf bis']], 'admin', 200)['priceChange'], 'un nom n\'est pas un prix');
        $repriced = $this->json('PATCH', "$base/$id", ['price' => 5900], 'admin', 200);
        $this->assertSame([5900, 2], [$repriced['plan']['price'], count($repriced['priceHistory'])]);
        $this->assertSame([null, 'prod_abo_conf'], array_values($this->db->fetchAssociative('SELECT stripe_price_id, stripe_product_id FROM subscription_plan WHERE id = ?', [$id])), 'le prochain abonné fait naître le nouveau prix, sur le même produit Stripe');
        $this->assertSame('price_abo_conf_old', $this->db->fetchOne('SELECT stripe_price_id FROM subscription_plan_price WHERE plan_id = ? AND valid_until IS NOT NULL', [$id]));
        $this->assertSame([5900, null], [$repriced['priceHistory'][0]['price'], $repriced['priceHistory'][0]['until']]);
        // un nom changé n'ouvre pas d'époque de prix
        $this->assertCount(2, $this->json('PATCH', "$base/$id", ['names' => ['fr' => 'Panier abo-conf renommé']], 'admin', 200)['priceHistory']);
        // EasyAdmin : le même effet (le défaut corrigé : un prix modifié gardait l'ancien prix Stripe même pour les nouveaux abonnés)
        $this->db->executeStatement("UPDATE subscription_plan SET stripe_price_id = 'price_abo_conf_mid' WHERE id = ?", [$id]);
        $em = $this->provider->getEntityManager();
        $em->clear();
        $plan = $em->getRepository(SubscriptionPlan::class)->find($id);
        $plan->setPrice(6900);
        static::getContainer()->get(SubscriptionPlanCrudController::class)->updateEntity($em, $plan);
        $this->assertSame([null, 6900], [$this->db->fetchOne('SELECT stripe_price_id FROM subscription_plan WHERE id = ?', [$id]), (int) $this->db->fetchOne('SELECT price FROM subscription_plan WHERE id = ?', [$id])]);
        $this->assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM subscription_plan_price WHERE plan_id = ?', [$id]));
        // archiver, réactiver ; une formule branchée de la plateforme : EasyAdmin seulement
        $this->assertFalse($this->json('POST', "$base/$id/archiver", [], 'admin', 200)['plan']['active']);
        $this->assertTrue($this->json('POST', "$base/$id/reactiver", [], 'admin', 200)['plan']['active']);
        $this->db->executeStatement("UPDATE subscription_plan SET fulfillment_type = 'platform_offer' WHERE id = ?", [$id]);
        $this->assertSame('PLAN_PLATFORM_ONLY', $this->json('PATCH', "$base/$id", ['price' => 1], 'admin', 403)['code']);
        $this->db->executeStatement("UPDATE subscription_plan SET fulfillment_type = 'none' WHERE id = ?", [$id]);
        // une formule qui a eu un abonné ne se supprime pas ; sans abonné, si
        $this->db->executeStatement("INSERT INTO subscription (user_id, plan_id, quantity, status, created_at, updated_at, stripe_subscription_id) SELECT id, ?, 1, 'canceled', NOW(), NOW(), 'sub_abo_conf' FROM \"user\" WHERE email = 'admin@arkanoa-media.com'", [$id]);
        $this->assertSame('PLAN_HAS_SUBSCRIBERS', $this->json('DELETE', "$base/$id", null, 'admin', 409)['code']);
        $other = (int) $this->json('POST', $base, ['names' => ['fr' => 'Autre abo-conf']] + $good, 'admin', 201)['plan']['id'];
        $this->assertSame(204, $this->api('DELETE', "$base/$other", null, 'admin')->getStatusCode());
        // jamais sans le rôle d'administration
        $this->assertSame(403, $this->api('POST', $base, $good, 'client')->getStatusCode());
    }

    public function testBenefitsAndTheCompleteListOfAPlan(): void
    {
        $plans = '/api/site/abonnements/formules';
        $base = '/api/site/abonnements/prestations';
        $yoga = $this->json('POST', $base, ['kind' => 'session', 'names' => ['fr' => 'Yoga abo-conf'], 'durationMinutes' => 60], 'admin', 201);
        $menage = $this->json('POST', $base, ['kind' => 'service', 'names' => ['fr' => 'Ménage abo-conf']], 'admin', 201);
        $this->assertSame('BENEFIT_KIND_INVALID', $this->json('POST', $base, ['kind' => 'cours', 'names' => ['fr' => 'X']], 'admin', 422)['code']);
        $this->assertContains($yoga['id'], array_column($this->json('GET', $base, null, 'admin', 200)['benefits'], 'id'));
        $plan = (int) $this->json('POST', $plans, ['productId' => $this->productId, 'names' => ['fr' => 'Mix abo-conf'], 'price' => 3000, 'interval' => 'week'], 'admin', 201)['plan']['id'];
        // la liste complète : deux prestations, puis une seule (la seconde est retirée)
        $two = $this->json('PUT', "$plans/$plan/prestations", ['benefits' => [['benefitId' => $yoga['id'], 'quantity' => 4], ['benefitId' => $menage['id'], 'quantity' => 1]]], 'admin', 200);
        $this->assertCount(2, $two['benefits']);
        $this->assertStringContainsString('4 séances Yoga abo-conf par semaine', $two['summary']);
        $one = $this->json('PUT', "$plans/$plan/prestations", ['benefits' => [['benefitId' => $yoga['id'], 'quantity' => 4]]], 'admin', 200);
        $this->assertSame([$yoga['id']], array_column($one['benefits'], 'benefitId'));
        $this->assertSame('BENEFIT_NOT_FOUND', $this->json('PUT', "$plans/$plan/prestations", ['benefits' => [['benefitId' => 2147483000, 'quantity' => 1]]], 'admin', 404)['code']);
        // une prestation livrée ne se supprime pas ; une prestation qui a des droits à utiliser ne se désactive pas
        $this->assertSame('BENEFIT_IN_USE', $this->json('DELETE', "$base/{$yoga['id']}", null, 'admin', 409)['code']);
        $this->db->executeStatement("INSERT INTO subscription (user_id, plan_id, quantity, status, created_at, updated_at, stripe_subscription_id) SELECT id, ?, 1, 'active', NOW(), NOW(), 'sub_abo_conf_b' FROM \"user\" WHERE email = 'admin@arkanoa-media.com'", [$plan]);
        $sub = (int) $this->db->fetchOne("SELECT id FROM subscription WHERE stripe_subscription_id = 'sub_abo_conf_b'");
        $this->db->executeStatement("INSERT INTO benefit_occurrence (subscription_id, benefit_id, rank, origin, status, valid_until, created_at) VALUES (?, ?, 1, 'gift', 'available', NOW() + INTERVAL '5 days', NOW())", [$sub, $yoga['id']]);
        $refusal = $this->json('PATCH', "$base/{$yoga['id']}", ['active' => false], 'admin', 409);
        $this->assertSame(['BENEFIT_IN_USE', 1], [$refusal['code'], $refusal['pendingRights']]);
        $this->assertTrue($this->json('GET', $base, null, 'admin', 200)['benefits'][array_search($yoga['id'], array_column($this->json('GET', $base, null, 'admin', 200)['benefits'], 'id'), true)]['active']);
        $this->assertFalse($this->json('PATCH', "$base/{$menage['id']}", ['active' => false], 'admin', 200)['active'], 'sans droit : désactivée');
        // retirée de la formule, la prestation garde les droits au solde (ABO-1)
        $this->json('PUT', "$plans/$plan/prestations", ['benefits' => []], 'admin', 200);
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM benefit_occurrence WHERE subscription_id = ? AND status = 'available'", [$sub]));
        $this->db->executeStatement('DELETE FROM benefit_occurrence WHERE subscription_id = ?', [$sub]);
    }

    public function testTheAssistantProposesAPriceChangeThatTheAdminConfirms(): void
    {
        $plan = static::getContainer()->get(AboCatalogService::class)->savePlan(null, ['productId' => $this->productId, 'names' => ['fr' => 'Assistant abo-conf'], 'price' => 2500, 'interval' => 'month'], 'test');
        $this->answer([['propose_plan_update', ['planId' => $plan->getId(), 'price' => 2900]]]);
        $body = $this->chat('Passe la formule à 29 $');
        $this->assertCount(1, $body['actions']);
        $this->assertStringContainsString('nouveaux abonnés seulement', (string) json_encode($body['actions'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(2500, (int) $this->db->fetchOne('SELECT price FROM subscription_plan WHERE id = ?', [$plan->getId()]), 'jamais de prix sans confirmation');
        $this->assertSame(200, $this->api('POST', '/api/site/assistant/actions/' . $body['actions'][0]['id'] . '/confirm', null, 'admin')->getStatusCode());
        $this->assertSame(2900, (int) $this->db->fetchOne('SELECT price FROM subscription_plan WHERE id = ?', [$plan->getId()]));
    }

    /**
     * Décision A du propriétaire (19/11/2026) : en RÉEL, un site extérieur sans compte Stripe connecté actif ne vend pas d'abonnement
     * (409 STRIPE_ACCOUNT_REQUIRED) ; un site interne de la plateforme encaisse ; en test, rien ne change. La formule prévient le commerçant.
     */
    public function testWithoutAConnectedAccountASiteSellsNoSubscriptionInLiveMode(): void
    {
        $service = static::getContainer()->get(\App\Services\SubscriptionService\SubscriptionService::class);
        $internal = static::getContainer()->get(TenantConnectionManager::class)->isTenantInternal(MV_TEST_TENANT_CODE);
        $configs = $this->db->fetchAllAssociative('SELECT id, is_active FROM stripe_config');
        $key = $_ENV['STRIPE_SECRET_KEY'] ?? null;
        try {
            $this->db->executeStatement('UPDATE stripe_config SET is_active = false');
            $this->provider->getEntityManager()->clear();
            $service->assertCanSellLive(); // en test : rien ne change
            $this->assertSame($internal ? [] : ['STRIPE_ACCOUNT_REQUIRED'], array_column(static::getContainer()->get(AboCatalogService::class)->warnings(), 'code'), 'le commerçant est prévenu');
            $_ENV['STRIPE_SECRET_KEY'] = 'sk_live_cle_factice_de_l_essai'; // le mode réel, simulé pour cet essai seulement
            try {
                $service->assertCanSellLive();
                $this->assertTrue($internal, 'un site extérieur sans compte connecté est refusé en réel');
            } catch (\App\Services\SubscriptionService\SubscriptionException $e) {
                $this->assertFalse($internal, 'un site interne de la plateforme encaisse');
                $this->assertSame([409, 'STRIPE_ACCOUNT_REQUIRED'], [$e->getStatusCode(), $e->code()]);
            }
        } finally {
            if ($key === null) {
                unset($_ENV['STRIPE_SECRET_KEY']);
            } else {
                $_ENV['STRIPE_SECRET_KEY'] = $key;
            }
            foreach ($configs as $c) {
                $this->db->executeStatement('UPDATE stripe_config SET is_active = ? WHERE id = ?', [$c['is_active'], $c['id']], [\PDO::PARAM_BOOL, \PDO::PARAM_INT]);
            }
        }
    }

    /**
     * Décision C du propriétaire (19/11/2026) : commission de la plateforme sur les abonnements, 1 % par défaut, remplacée par le réglage du
     * site s'il est rempli, 0 permis, bornes 0 à 20 % ; seulement avec un compte connecté ; posée à la souscription ; abonnements en cours
     * rattrapés par lots (clé d'idempotence, une erreur n'arrête pas le lot) ; chaque changement au journal de la base maître.
     */
    public function testTheSubscriptionFeeDefaultsToOnePercentIsOverriddenPerSiteAndCatchesUp(): void
    {
        $fees = static::getContainer()->get(\App\Services\SubscriptionService\SubscriptionFeeService::class);
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $master->exec("DELETE FROM platform_settings WHERE key = 'subscription_fee_bp'");
        $master->exec('DELETE FROM platform_fee_log');
        $internal = $master->query("SELECT is_internal_store FROM tenants WHERE code = '" . MV_TEST_TENANT_CODE . "'")->fetchColumn();
        $configs = $this->db->fetchAllAssociative('SELECT id, is_active, account_id, subscription_fee_bp FROM stripe_config');
        \App\Tests\Fake\FakeSubscriptionStripeGateway::reset();
        try {
            $this->assertSame(100, $fees->platformBp(), '1 % par défaut');
            // sans compte connecté : aucune commission
            $this->db->executeStatement('UPDATE stripe_config SET is_active = false');
            $this->provider->getEntityManager()->clear();
            $this->assertNull($fees->effectiveBp());
            // un site extérieur au compte connecté
            $master->prepare('UPDATE tenants SET is_internal_store = false WHERE code = ?')->execute([MV_TEST_TENANT_CODE]);
            if ($this->db->fetchOne('SELECT COUNT(*) FROM stripe_config') == 0) {
                $this->db->executeStatement("INSERT INTO stripe_config (account_id, is_active, online_fee_bp) VALUES ('acct_abo_conf', true, 100)");
            }
            $this->db->executeStatement("UPDATE stripe_config SET is_active = true, account_id = 'acct_abo_conf', subscription_fee_bp = NULL");
            $this->provider->getEntityManager()->clear();
            $this->assertSame(100, $fees->effectiveBp());
            foreach ([-1, 2001] as $bad) {
                try {
                    $fees->setPlatformBp($bad, 'essai');
                    $this->fail("$bad accepté");
                } catch (\InvalidArgumentException) {
                }
            }
            $fees->setPlatformBp(250, 'super@example.invalid');
            $this->assertSame([250, 250], [$fees->platformBp(), $fees->effectiveBp()]);
            $fees->setSiteBp(0, 'super@example.invalid'); // 0 permis, remplace celui de la plateforme
            $this->assertSame(0, $fees->effectiveBp());
            $fees->setSiteBp(null, 'super@example.invalid'); // retour à celui de la plateforme
            $this->assertSame(250, $fees->effectiveBp());
            $journal = $fees->journalRows(10);
            $this->assertSame([['site', 0, null], ['site', null, 0], ['platform', 100, 250]],
                array_map(fn ($r) => [$r['setting'], $r['old_bp'] === null ? null : (int) $r['old_bp'], $r['new_bp'] === null ? null : (int) $r['new_bp']], array_slice($journal, 0, 3)), 'qui, ancien, nouveau');
            $this->assertSame('super@example.invalid', $journal[0]['changed_by']);
            // rattrapage : deux abonnements en cours à une autre commission, un en échec chez Stripe ; repris sans rien refaire
            $plan = static::getContainer()->get(AboCatalogService::class)->savePlan(null, ['productId' => $this->productId, 'names' => ['fr' => 'Commission abo-conf'], 'price' => 2000, 'interval' => 'month'], 'test');
            foreach (['sub_abo_conf_f1', 'sub_abo_conf_f2'] as $stripeId) {
                $this->db->executeStatement("INSERT INTO subscription (user_id, plan_id, quantity, status, created_at, updated_at, stripe_subscription_id, fee_bp_applied) SELECT id, ?, 1, 'active', NOW(), NOW(), ?, 100 FROM \"user\" WHERE email = 'admin@arkanoa-media.com'", [$plan->getId(), $stripeId]);
            }
            $this->assertSame(2, $fees->pendingCount());
            \App\Tests\Fake\FakeSubscriptionStripeGateway::$feeFailure = 'sub_abo_conf_f2';
            $this->assertSame(['updated' => 1, 'failed' => 1], $fees->catchUp());
            $this->assertSame(1, $fees->pendingCount(), 'l\'échec est repris au passage suivant');
            \App\Tests\Fake\FakeSubscriptionStripeGateway::$feeFailure = null;
            $this->assertSame(['updated' => 1, 'failed' => 0], $fees->catchUp());
            $this->assertSame(0, $fees->pendingCount());
            $this->assertSame(2, count(array_filter(\App\Tests\Fake\FakeSubscriptionStripeGateway::$calls, fn ($c) => $c[0] === 'updateFee' && $c[2] === 2.5)), 'une mise à jour par abonnement, au bon pourcentage');
        } finally {
            $master->prepare('UPDATE tenants SET is_internal_store = ? WHERE code = ?')->execute([$internal, MV_TEST_TENANT_CODE]);
            $master->exec("DELETE FROM platform_settings WHERE key = 'subscription_fee_bp'");
            $master->exec('DELETE FROM platform_fee_log');
            $this->db->executeStatement("DELETE FROM subscription WHERE stripe_subscription_id LIKE 'sub_abo_conf_f%'");
            foreach ($configs as $c) {
                $this->db->executeStatement('UPDATE stripe_config SET is_active = ?, account_id = ?, subscription_fee_bp = ? WHERE id = ?', [$c['is_active'], $c['account_id'], $c['subscription_fee_bp'], $c['id']], [\PDO::PARAM_BOOL, \PDO::PARAM_STR, \PDO::PARAM_INT, \PDO::PARAM_INT]);
            }
            if ($configs === []) {
                $this->db->executeStatement("DELETE FROM stripe_config WHERE account_id = 'acct_abo_conf'");
            }
            \App\Tests\Fake\FakeSubscriptionStripeGateway::reset();
        }
    }

    // ---- aides ------------------------------------------------------------------------------------------------------------------------

    private function answer(array $uses): void
    {
        FakeLandingAiClient::reset();
        $content = [];
        foreach ($uses as $i => [$name, $input]) {
            $content[] = (object) ['type' => 'tool_use', 'id' => 'tu_' . $i, 'name' => $name, 'input' => json_decode((string) json_encode((object) $input))];
        }
        FakeLandingAiClient::$queue[] = (object) ['content' => $content, 'usage' => (object) ['input_tokens' => 10, 'output_tokens' => 5]];
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'text', 'text' => 'Voici.']], 'usage' => (object) ['input_tokens' => 10, 'output_tokens' => 5]];
    }

    /** @return array<string, mixed> */
    private function chat(string $message): array
    {
        $r = $this->api('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => $message]]], 'admin');
        $this->assertSame(200, $r->getStatusCode(), (string) $r->getContent());

        return json_decode((string) $r->getContent(), true);
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $path, ?array $body, string $as, int $status): array
    {
        $r = $this->api($method, $path, $body, $as);
        $this->assertSame($status, $r->getStatusCode(), "$method $path : " . substr((string) $r->getContent(), 0, 400));

        return json_decode((string) $r->getContent(), true) ?? [];
    }

    private function api(string $method, string $path, ?array $body, ?string $as): Response
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test'];
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token($as);
        }
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server, $body !== null ? json_encode($body) : null);
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $this->client->getResponse();
    }

    private function token(string $as): string
    {
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $this->provider->getEntityManager();
        $email = $as === 'admin' ? 'admin@arkanoa-media.com' : 'client-abo-conf-api@example.invalid';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Client')->setLastname('Conf')->setRoles(['ROLE_USER_INTERNET'])->setPassword('inutilisable')->setIsVerified(true);
            $em->persist($user);
            $em->flush();
        }

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
