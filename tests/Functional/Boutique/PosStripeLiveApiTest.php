<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\CashRegister;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\CaisseService\CashRegisterException;
use App\Services\PosAuditService\PosAuditService;
use App\Services\PosStripeService\PosHealthService;
use App\Services\PosStripeService\PosStripeModeService;
use App\Services\PosTerminalService\PosTerminalPaymentService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakePosStripeAccountGateway;
use App\Tests\Fake\FakeTerminalGateway;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Paiements de caisse en production (M1) : mode Stripe par site (test par défaut | réel) visible dans GET /api/pos/terminal ; passage en
 * réel seulement avec clés réelles, compte réel qui encaisse et reçoit les virements, aucun paiement en cours ; lecteurs détachés, plus de
 * simulation ; paiement de l'autre mode refusé par Stripe (remboursement autrement) ; inscription Stripe du compte réel ; surveillance
 * GET /api/pos/health (administrateur) et alertes dédoublonnées. Stripe simulé (FakeTerminalGateway, FakePosStripeAccountGateway).
 */
class PosStripeLiveApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';

    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
    private ?string $key = null;
    private int $register;
    private int $cap;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeTerminalGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-carte-terminal@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false,
            pos_pay_cash = true, pos_pay_declared_card = true, pos_pay_paypal = true, pos_pay_multi = true, pos_declared_card_blocked_with_terminal = false, pos_offline_enabled = false,
            pos_gift_card_enabled = true, pos_store_credit_enabled = false, pos_coupon_enabled = false, pos_terminal_interac = NULL, pos_terminal_fee_card_bp = 100, pos_terminal_fee_interac_bp = 150, pos_tap_to_pay_allowed = false, pos_bluetooth_reader_allowed = false");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, stripe_reader_label = NULL, stripe_reader_simulated = false, terminal_mode = NULL, receipt_prefix = NULL');
        $this->db->executeStatement('UPDATE stripe_config SET terminal_location_id = NULL, live_account_id = NULL, live_is_active = false, live_terminal_location_id = NULL');
        // M1 : caisse en mode test, alertes désactivées, aucun état d'alerte ; clés réelles et adresse de la plateforme absentes
        $this->db->executeStatement("UPDATE entreprise SET pos_stripe_mode = 'test', pos_alerts_enabled = false, pos_alerts_email = NULL");
        $this->db->executeStatement('DELETE FROM pos_health_alert');
        \App\Tests\Fake\FakePosStripeAccountGateway::reset();
        self::env('STRIPE_LIVE_SECRET_KEY', null);
        self::env('POS_ALERT_PLATFORM_EMAIL', null);
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement("UPDATE product SET price = 2900, special_price = NULL WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500, price = NULL WHERE id = ?', [$this->cap]);
    }

    protected function tearDown(): void
    {
        self::env('STRIPE_LIVE_SECRET_KEY', null);
        self::env('POS_ALERT_PLATFORM_EMAIL', null);
        parent::tearDown();
    }

    public function testLiveModeIsGuardedJournaledAndStopsSimulation(): void
    {
        $this->configureSimulatedReader();
        $admin = $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $this->terminal = $this->posTerminal();
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $modes = static::getContainer()->get(PosStripeModeService::class);
        $error = fn (callable $f) => (function () use ($f) { try { $f(); return null; } catch (CashRegisterException $e) { return [$e->status, $e->errorCode]; } })();
        $items = [['productVariantId' => $this->cap, 'quantity' => 1]];

        // Test par défaut, visible par la caisse ; simulation permise
        $t = $this->json($this->request('GET', "/api/pos/terminal?cashRegisterId=$this->register"));
        $this->assertSame(['test', true], [$t['stripeMode'], $t['simulationAllowed']]);

        // Une vente payée au terminal en mode test (rattachée à son mode)
        $tp = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => 3334, 'items' => $items]), 201);
        self::env('STRIPE_LIVE_SECRET_KEY', 'sk_live_simule');
        $this->assertSame([409, 'TERMINAL_PAYMENTS_PENDING'], $error(fn () => $modes->switchMode('live', $admin)), 'paiement en cours : pas de changement de mode');
        $this->json($this->request('POST', '/api/pos/terminal/payments/' . $tp['paymentId'] . '/simulate', ['type' => 'card_present']));
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 1, 'amount' => 33.34, 'terminalPaymentId' => $tp['paymentId']]], 'cashRegisterId' => $this->register]), 201);
        $this->assertSame('test', $this->db->fetchOne('SELECT stripe_mode FROM pos_terminal_payment WHERE id = ?', [$tp['paymentId']]));

        // Sans clés réelles, puis sans compte réel, puis compte réel non prêt : refusé, rien ne change
        self::env('STRIPE_LIVE_SECRET_KEY', null);
        $this->assertSame([409, 'STRIPE_KEYS_MISSING'], $error(fn () => $modes->switchMode('live', $admin)));
        self::env('STRIPE_LIVE_SECRET_KEY', 'sk_live_simule');
        $this->assertSame([409, 'STRIPE_ACCOUNT_NOT_READY'], $error(fn () => $modes->switchMode('live', $admin)), 'aucun compte réel');
        $url = $modes->liveOnboardingLink('https://admin.test/refresh', 'https://admin.test/return', $admin);
        $live = (string) $this->db->fetchOne('SELECT live_account_id FROM stripe_config LIMIT 1');
        $this->assertStringStartsWith('acct_fake', $live);
        $this->assertSame(['https://connect.stripe.test/setup/' . $live, 'live'], [$url, FakePosStripeAccountGateway::$links[0]['mode']]);
        $this->assertNotFalse(strpos((string) $this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'STRIPE_ACCOUNT_UPDATED' ORDER BY id DESC LIMIT 1"), 'onboarding_link'));
        $this->assertStringNotContainsString($live, (string) $this->db->fetchOne("SELECT string_agg(payload, ' ') FROM pos_audit_log WHERE type = 'STRIPE_ACCOUNT_UPDATED'"), 'identifiant tronqué au journal');
        FakePosStripeAccountGateway::set($live, true, false, ['external_account']);
        try {
            $modes->switchMode('live', $admin);
            $this->fail('virements non autorisés : refus attendu');
        } catch (CashRegisterException $e) {
            $this->assertSame(['STRIPE_ACCOUNT_NOT_READY', true, false, ['external_account']], [$e->errorCode, $e->context['chargesEnabled'], $e->context['payoutsEnabled'], $e->context['currentlyDue']]);
        }
        $this->assertSame('test', $this->db->fetchOne('SELECT pos_stripe_mode FROM entreprise LIMIT 1'));

        // Compte prêt : passage en réel, lecteur simulé détaché, journal ; plus de simulation possible
        FakePosStripeAccountGateway::set($live, true, true);
        $journal = (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'STRIPE_MODE_CHANGED'");
        $switch = $modes->switchMode('live', $admin);
        $this->assertSame(['live', true, 1, true], [$switch['mode'], $switch['changed'], count($switch['detachedReaders']), $switch['detachedReaders'][0]['simulated']]);
        $this->assertSame([null, false, true], [$this->db->fetchOne('SELECT stripe_reader_id FROM cash_register WHERE id = ?', [$this->register]), (bool) $this->db->fetchOne('SELECT terminal_enabled FROM cash_register WHERE id = ?', [$this->register]), (bool) $this->db->fetchOne('SELECT live_is_active FROM stripe_config LIMIT 1')]);
        $this->assertSame($journal + 1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'STRIPE_MODE_CHANGED'"));
        $payload = json_decode((string) $this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'STRIPE_MODE_CHANGED' ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame(['test', 'live', $admin->getId()], [$payload['data']['from'] ?? $payload['from'] ?? null, $payload['data']['to'] ?? $payload['to'] ?? null, (int) $this->db->fetchOne("SELECT user_id FROM pos_audit_log WHERE type = 'STRIPE_MODE_CHANGED' ORDER BY id DESC LIMIT 1")]);
        $t = $this->json($this->request('GET', "/api/pos/terminal?cashRegisterId=$this->register"));
        $this->assertSame(['live', false], [$t['stripeMode'], $t['simulationAllowed']]);
        $register = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->find(CashRegister::class, $this->register);
        $this->assertSame([403, 'SIMULATION_FORBIDDEN'], $error(fn () => static::getContainer()->get(PosTerminalPaymentService::class)->createSimulatedReader($register)), 'jamais de lecteur simulé en réel');

        // Vente payée en test, annulée après le passage en réel : remboursement carte refusé (autre mode), espèces possibles
        $cancel = $this->request('POST', "/api/order/cancel/{$sale['orderId']}", ['paymentMethod' => 1, 'cashRegisterId' => $this->register]);
        $this->assertSame([409, 'TERMINAL_PAYMENT_OTHER_MODE', 'test', 'live'], [$cancel->getStatusCode(), json_decode($cancel->getContent(), true)['error'], json_decode($cancel->getContent(), true)['paymentMode'], json_decode($cancel->getContent(), true)['siteMode']]);
        $this->assertSame(200, $this->request('POST', "/api/order/cancel/{$sale['orderId']}", ['paymentMethod' => 2, 'cashRegisterId' => $this->register])->getStatusCode(), 'remboursement en espèces');

        // Surveillance en réel : compte relu, aucun point critique ; retour en test journalisé
        $health = static::getContainer()->get(PosHealthService::class)->report(true);
        $this->assertSame(['live', true, true, true], [$health['stripe']['mode'], $health['stripe']['keys']['live'], $health['stripe']['account']['status']['chargesEnabled'], $health['stripe']['account']['status']['payoutsEnabled']]);
        $this->assertNotContains('STRIPE_ACCOUNT_NOT_READY', array_column($health['checks'], 'code'));
        FakePosStripeAccountGateway::set($live, false, true, ['individual.verification.document']);
        $this->assertContains('STRIPE_ACCOUNT_NOT_READY', array_column(static::getContainer()->get(PosHealthService::class)->report(true)['checks'], 'code'), 'compte suspendu en réel : critique');
        $back = $modes->switchMode('test', $admin);
        $this->assertSame(['test', true], [$back['mode'], $back['changed']]);
        $this->assertSame('test', $this->json($this->request('GET', "/api/pos/terminal?cashRegisterId=$this->register"))['stripeMode']);
    }

    public function testHealthRouteChecksAndDeduplicatedAlerts(): void
    {
        $this->configureSimulatedReader();
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $this->terminal = $this->posTerminal();
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        // Le client relance le noyau entre deux requêtes : service (et son gestionnaire d'entités) repris à chaque appel
        $health = fn () => static::getContainer()->get(PosHealthService::class);
        $codes = fn (array $r) => array_column($r['checks'], 'code');
        $status = static::$kernel->getProjectDir() . '/var/pos-terminal-reconcile.status.test.json';
        @unlink($status);

        // Route : administrateur seulement
        $r = $this->json($this->request('GET', '/api/pos/health'));
        $this->assertSame(['status', 'checkedAt', 'site', 'usesTerminal', 'stripe', 'reconcile', 'checks', 'alerts'], array_keys($r));
        $this->assertSame(['test', true, false, true], [$r['stripe']['mode'], $r['stripe']['keys']['test'], $r['stripe']['keys']['live'], $r['usesTerminal']]);
        $this->assertContains('RECONCILE_STALE', $codes($r), 'réconciliation jamais exécutée');
        $this->assertContains('STRIPE_TEST_MODE', $codes($r));
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->assertSame(403, $this->request('GET', '/api/pos/health')->getStatusCode(), 'responsable : refusé');
        $this->token = null;
        $this->assertSame(401, $this->request('GET', '/api/pos/health')->getStatusCode());
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);

        // Réconciliation exécutée : état daté (fichier des tests), plus d'alerte de retard
        $tester = new CommandTester((new Application(static::$kernel))->find('app:pos:terminal-reconcile'));
        $tester->execute(['--tenant' => MV_TEST_TENANT_CODE, '--no-alerts' => true]);
        $this->assertSame('ok', json_decode((string) file_get_contents($status), true)['last']['outcome']);
        $this->assertNotContains('RECONCILE_STALE', $codes($health()->report(false)));

        // Paiement sans vente au-delà du délai, session ouverte depuis plus de 24 h, échec de capture journalisé
        $tp = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => 3334, 'items' => [['productVariantId' => $this->cap, 'quantity' => 1]]]), 201);
        $this->json($this->request('POST', '/api/pos/terminal/payments/' . $tp['paymentId'] . '/simulate', ['type' => 'card_present']));
        $this->db->executeStatement("UPDATE pos_terminal_payment SET created_at = created_at - INTERVAL '1 hour' WHERE id = ?", [$tp['paymentId']]);
        $this->db->executeStatement("UPDATE caisse SET created_at = NOW() - INTERVAL '25 hours' WHERE is_open = true AND cash_register_id = ?", [$this->register]);
        static::getContainer()->get(PosAuditService::class)->record(PosAuditService::CARD_PAYMENT_FAILED, ['stage' => 'capture', 'paymentId' => 0, 'message' => 'test'], null, null, null, null, null, true);
        $r = $health()->report(false);
        $this->assertSame('critical', $r['status']);
        foreach (['ORPHAN_PAYMENTS', 'SESSIONS_OPEN_24H', 'CAPTURE_FAILURES'] as $c) {
            $this->assertContains($c, $codes($r));
        }
        $orphan = array_values(array_filter($r['checks'], fn ($c) => $c['code'] === 'ORPHAN_PAYMENTS'))[0];
        $this->assertContains($tp['paymentId'], array_column($orphan['details']['payments'], 'paymentId'));
        $this->assertStringNotContainsString('4242', json_encode($r), 'aucune donnée de carte');

        // Alertes : désactivées et sans adresse de plateforme → aucun envoi ; activées → un envoi, puis pas de renvoi de la même situation
        $this->assertSame([], $health()->alert($r));
        $this->db->executeStatement("UPDATE entreprise SET pos_alerts_enabled = true, pos_alerts_email = 'caisse-alertes@example.invalid'");
        self::env('POS_ALERT_PLATFORM_EMAIL', 'plateforme-alertes@example.invalid');
        $this->db->executeStatement('DELETE FROM pos_health_alert');
        static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->clear(); // réglages modifiés en SQL : relus
        $sent = $health()->alert($health()->report(false));
        $this->assertContains('ORPHAN_PAYMENTS', $sent);
        $this->assertNotContains('STRIPE_TEST_MODE', $sent, 'information : jamais envoyée');
        $log = json_decode((string) $this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'HEALTH_ALERT_SENT' ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame(2, $log['data']['recipients'] ?? $log['recipients'] ?? null, 'contact du site et administrateur de la plateforme');
        $this->assertSame([], $health()->alert($health()->report(false)), 'même situation : pas de renvoi avant 6 heures');
        // Situation résolue : état marqué résolu
        $this->db->executeStatement("UPDATE caisse SET created_at = NOW() WHERE is_open = true AND cash_register_id = ?", [$this->register]);
        $health()->alert($health()->report(false));
        $this->assertNotNull($this->db->fetchOne("SELECT resolved_at FROM pos_health_alert WHERE code = 'SESSIONS_OPEN_24H'"));

        // Page EasyAdmin : affichée, formulaire sans jeton refusé (mode inchangé)
        $admin = $this->user(['ROLE_ADMIN']);
        $this->client->loginUser($admin, 'main');
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/pos/stripe', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Paiements de caisse : <strong>test</strong>', $this->client->getResponse()->getContent());
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/pos/stripe', ['action' => 'mode_live', '_token' => 'faux'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertStringContainsString('Formulaire expiré', $this->client->getResponse()->getContent());
        $this->assertSame('test', $this->db->fetchOne('SELECT pos_stripe_mode FROM entreprise LIMIT 1'));
    }

    private static function env(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        } else {
            $_ENV[$name] = $value;
        }
    }

    private function user(array $roles): User
    {
        $email = 'stripe-live-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Stripe')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }

    private function configureSimulatedReader(): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $register = $provider->getEntityManager()->find(CashRegister::class, $this->register);
        $provider->getEntityManager()->refresh($register);
        static::getContainer()->get(PosTerminalPaymentService::class)->createSimulatedReader($register);
    }

    private function posTerminal(): string
    {
        $saved = $this->token;
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $key = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette cartes terminal', 'sendEmail' => false]), 201)['key'];
        $this->token = $saved;
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/app/activate', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['key' => $key, 'app' => 'pos']));

        return json_decode($this->client->getResponse()->getContent(), true)['terminal']['token'];
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function json(Response $response, int $expected = 200): array
    {
        $this->assertSame($expected, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
            'HTTP_X_POS_TERMINAL' => $this->terminal, 'HTTP_IDEMPOTENCY_KEY' => $this->key,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): User
    {
        $email = 'carte-terminal-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Carte')->setLastname('Terminal')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $saved = $this->key;
        $this->key = null;
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $email, 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
        $this->key = $saved;

        return $user;
    }
}
