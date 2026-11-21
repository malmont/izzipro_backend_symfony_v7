<?php

namespace App\Tests\Functional\Security;

use App\Entity\Entreprise;
use App\Services\FeatureAccess\AppRightRoutes;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot ENT-1 (16/11/2026) : le droit d'usage de la caisse (`module.pos`) et de l'e-commerce mobile (`app.mobile_shop`) garde leurs
 * routes, sans jamais couper une vente commencée. Seulement en mode « live » de la plateforme ; en « demo », rien n'est refusé.
 */
class AppRightGateTest extends WebTestCase
{
    private KernelBrowser $client;
    private TenantEntityManagerProvider $provider;
    /** @var array{0: bool, 1: bool} */
    private array $before = [false, false];
    /** @var list<int> */
    private array $created = [];
    /** @var list<string> */
    private array $keys = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        static::getContainer()->get(\App\Services\Platform\PlatformModeService::class)->setMode('live', 'test');
        $e = $this->entreprise();
        $this->before = [(bool) $e->isPosActive(), (bool) $e->isMobileShopActive()];
    }

    protected function tearDown(): void
    {
        $db = $this->provider->getEntityManager()->getConnection();
        foreach ($this->created as $id) {
            $db->executeStatement('DELETE FROM pos_terminal_payment WHERE id = ?', [$id]);
        }
        foreach ($this->keys as $key) {
            $db->executeStatement('DELETE FROM pos_idempotency_key WHERE idem_key = ?', [$key]);
        }
        $db->executeStatement("DELETE FROM cash_register WHERE name = 'Poste ENT-1'");
        $this->setFlags($this->before[0], $this->before[1]);
        static::getContainer()->get(\App\Services\Platform\PlatformModeService::class)->setMode('demo', 'test');
        parent::tearDown();
    }

    /** FERMÉ PAR DÉFAUT : chaque route de la caisse est rangée (début ou toujours servie) ; une route nouvelle non rangée fait échouer cet essai */
    public function testEveryCashRegisterRouteIsClassified(): void
    {
        $unclassified = [];
        $count = 0;
        foreach (static::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            $path = preg_replace('/\.1$/', '', (string) preg_replace('/\{[^}]+\}/', '1', $route->getPath()));
            if (preg_match(AppRightRoutes::POS_PATHS, $path) !== 1) {
                continue;
            }
            foreach ($route->getMethods() ?: ['GET', 'POST'] as $method) {
                ++$count;
                if (!AppRightRoutes::classified($method, $path)) {
                    $unclassified[] = "$method $path ($name)";
                }
            }
        }
        $this->assertGreaterThan(60, $count, 'les routes de la caisse');
        $this->assertSame([], $unclassified, 'route de la caisse non rangée : la ranger dans AppRightRoutes::POS_STARTS ou POS_SERVED');
        // la décision par le chemin seul (la suite d'une vente commencée se vérifie en base : PosRightGuard)
        $this->assertSame(AppRightRoutes::START, AppRightRoutes::pos('POST', '/api/order/create-multi-payment'));
        $this->assertSame(AppRightRoutes::START, AppRightRoutes::pos('POST', '/api/pos/gift-cards'));
        $this->assertSame(AppRightRoutes::SERVED, AppRightRoutes::pos('POST', '/api/pos/terminal/connection-token'), 'le lecteur se reconnecte aussi pour finir un paiement ou rembourser');
        $this->assertSame(AppRightRoutes::SERVED, AppRightRoutes::pos('POST', '/api/caisse/close'));
        $this->assertSame(AppRightRoutes::START, AppRightRoutes::pos('POST', '/api/pos/une-route-nouvelle'), 'non rangée : un début, fermé par défaut');
        $this->assertNull(AppRightRoutes::pos('GET', '/api/products'));
        $this->assertSame(AppRightRoutes::SERVED, AppRightRoutes::pos('HEAD', '/api/caisse'));
        // les références de paiement, à toute profondeur (vente, carte-cadeau, échange, caution)
        $this->assertSame(['tp_1', 'pi_2', '7'], \App\Services\FeatureAccess\PosRightGuard::references(['paymentMethods' => [['type' => 1, 'terminalPaymentId' => 'tp_1']], 'sale' => ['paymentMethods' => [['stripePaymentId' => 'pi_2']]], 'deposit' => ['terminalPaymentId' => 7], 'other' => ['terminalPaymentId' => '  '], 'amount' => 5]));
    }

    public function testWithoutTheRightTheCashRegisterCannotStartButAStartedSaleEnds(): void
    {
        $this->setFlags(false, true);
        foreach (['/api/caisse/open', '/api/pos/terminal/payments', '/api/pos/exchanges', '/api/pos/rentals/deposits', '/api/pos/gift-cards'] as $path) {
            $body = $this->json($this->post($path, ['amount' => 20, 'paymentMethods' => [['type' => 2, 'amount' => 20]]]));
            $this->assertSame(['MODULE_INACTIVE', 'pos', 'Le droit à la caisse de ce site a pris fin.'], [$body['code'] ?? null, $body['module'] ?? null, $body['message'] ?? null], $path);
        }
        $this->assertSame('MODULE_INACTIVE', $this->json($this->post('/api/order/create-multi-payment', ['paymentMethods' => [['type' => 0, 'amount' => 10]]]))['code'] ?? null);
        // une FAUSSE référence ne vaut rien : vendre sans droit en inventant un paiement au terminal reste refusé
        $this->assertSame('MODULE_INACTIVE', $this->json($this->post('/api/order/create-multi-payment', ['orderSource' => 1, 'paymentMethods' => [['type' => 1, 'amount' => 10, 'terminalPaymentId' => 'inventee']]]))['code'] ?? null);
        $this->assertSame('MODULE_INACTIVE', $this->json($this->post('/api/pos/gift-cards', ['amount' => 50, 'terminalPaymentId' => 'x', 'paymentMethods' => [['type' => 2, 'amount' => 50]]]))['code'] ?? null, 'un champ au mauvais endroit ne passe pas');

        // la SUITE d'une vente commencée : un paiement au terminal vérifié en base, sans vente encore
        $payment = $this->terminalPayment('authorized', null);
        foreach ([['/api/order/create-multi-payment', ['paymentMethods' => [['type' => 1, 'amount' => 10, 'terminalPaymentId' => (string) $payment]]]],
            ['/api/pos/gift-cards', ['amount' => 10, 'paymentMethods' => [['type' => 1, 'amount' => 10, 'terminalPaymentId' => $payment]]]],
            ['/api/pos/exchanges', ['sale' => ['paymentMethods' => [['type' => 1, 'amount' => 10, 'terminalPaymentId' => $payment]]]]],
            ['/api/pos/rentals/deposits', ['terminalPaymentId' => $payment]]] as [$path, $body]) {
            $this->assertNotSame('MODULE_INACTIVE', $this->json($this->post($path, $body))['code'] ?? null, "$path : un paiement autorisé avant la fin du droit se termine");
        }
        // … mais pas un paiement déjà rattaché à une vente, ni un paiement échoué
        $used = $this->terminalPayment('captured', 4242);
        $this->assertSame('MODULE_INACTIVE', $this->json($this->post('/api/order/create-multi-payment', ['paymentMethods' => [['type' => 1, 'amount' => 10, 'terminalPaymentId' => (string) $used]]]))['code'] ?? null);
        $failed = $this->terminalPayment('failed', null);
        $this->assertSame('MODULE_INACTIVE', $this->json($this->post('/api/order/create-multi-payment', ['paymentMethods' => [['type' => 1, 'amount' => 10, 'terminalPaymentId' => (string) $failed]]]))['code'] ?? null);
        // un retour libre (typeOrder 2) et le rejeu d'une requête déjà reçue sont servis
        $this->assertNotSame('MODULE_INACTIVE', $this->json($this->post('/api/order/create-multi-payment', ['typeOrder' => 2, 'paymentMethods' => [['type' => 0, 'amount' => 10]]]))['code'] ?? null);
        $key = $this->idempotencyKey();
        $this->assertNotSame('MODULE_INACTIVE', $this->json($this->post('/api/order/create-multi-payment', ['paymentMethods' => [['type' => 0, 'amount' => 10]]], ['HTTP_IDEMPOTENCY_KEY' => $key]))['code'] ?? null, 'rejeu : la réponse déjà donnée, pas une nouvelle vente');

        // la fermeture, les reçus, les ventes hors ligne, le lecteur, les lectures : jamais refusés par le droit
        foreach ([['POST', '/api/caisse/close', []], ['POST', '/api/pos/offline-sales', []], ['POST', '/api/pos/receipts/reprint', []], ['GET', '/api/caisse', null],
            ['POST', '/api/pos/terminal/payments/1/cancel', []], ['POST', '/api/pos/terminal/connection-token', []]] as [$method, $path, $body]) {
            $response = $method === 'GET' ? $this->get($path) : $this->post($path, $body);
            $this->assertNotSame('MODULE_INACTIVE', $this->json($response)['code'] ?? null, "$method $path");
        }
        // le droit revenu : servi aussitôt
        $this->setFlags(true, true);
        $this->assertNotSame('MODULE_INACTIVE', $this->json($this->post('/api/caisse/open', []))['code'] ?? null);
    }

    public function testTheMobileAppIsGuardedByItsOwnRightWhenItSaysItIsTheMobileApp(): void
    {
        $boutique = (bool) $this->entreprise()->isBoutiqueActive();
        try {
            // la boutique ALLUMÉE, l'application mobile sans droit : refusée au nom de l'application mobile
            $this->setBoutique(true);
            $this->setFlags(true, false);
            $mobile = $this->json($this->post('/api/cart/quote', [], ['HTTP_X_APP' => 'mobile']));
            $this->assertSame(['MODULE_INACTIVE', 'mobile_shop', 'Le droit à l\'application mobile de ce site a pris fin.'], [$mobile['code'] ?? null, $mobile['module'] ?? null, $mobile['message'] ?? null]);
            $this->assertSame('MODULE_INACTIVE', $this->json($this->post('/api/stripe/create-intent', [], ['HTTP_X_APP' => 'Mobile']))['code'] ?? null);
            $this->assertSame('MODULE_INACTIVE', $this->json($this->post('/api/subscriptions', [], ['HTTP_X_APP' => 'mobile']))['code'] ?? null);
            // sans l'en-tête (le web, ou une application ancienne) : servi comme le web (limite écrite dans la fiche)
            $this->assertNotSame('MODULE_INACTIVE', $this->json($this->post('/api/cart/quote', []))['code'] ?? null);
            // une lecture de l'application mobile n'est pas un début de parcours
            $this->assertNotSame('MODULE_INACTIVE', $this->json($this->get('/api/products/bestsellers', ['HTTP_X_APP' => 'mobile']))['code'] ?? null);
            // le droit mobile présent, la boutique éteinte : refusée au nom de la boutique (les deux droits s'appliquent)
            $this->setFlags(true, true);
            $this->setBoutique(false);
            $this->assertSame('boutique', $this->json($this->post('/api/cart/quote', [], ['HTTP_X_APP' => 'mobile']))['module'] ?? null);
        } finally {
            $this->setBoutique($boutique);
        }
    }

    /** Une clé JAMAIS utilisée ne s'active plus sans droit (nouvel appareil) ; une clé déjà en service se réactive (finir, fermer, rembourser) */
    public function testANewDeviceStaysOutButADeviceAlreadyInServiceComesBack(): void
    {
        $switches = static::getContainer()->get(\App\Services\Platform\FeatureSwitchRepository::class);
        $master = static::getContainer()->get(\App\Services\TenantConnectionManager::class)->getPdoMaster();
        $key = null;
        try {
            $switches->set(MV_TEST_TENANT_CODE, 'app.mobile_shop', false, 'essai ENT-1');
            $key = static::getContainer()->get(\App\Services\AppKeyService\AppKeyService::class)->generate(MV_TEST_TENANT_CODE, 'mobile', 'Essai ENT-1', 'test', null)['key'];
            $activate = fn () => $this->post('/api/app/activate', ['key' => $key, 'app' => 'mobile']);
            $refused = $activate();
            $this->assertSame(403, $refused->getStatusCode(), (string) $refused->getContent());
            $this->assertSame('MODULE_INACTIVE', $this->json($refused)['error'] ?? $this->json($refused)['code'] ?? null);
            $this->assertSame('0', (string) $master->query("SELECT use_count FROM tenant_app_keys WHERE label = 'Essai ENT-1'")->fetchColumn(), 'refusée : la clé reste neuve');
            // la même clé déjà en service sur un appareil : réactivée
            $master->exec("UPDATE tenant_app_keys SET use_count = 1, last_used_at = NOW() WHERE label = 'Essai ENT-1'");
            $this->assertSame(200, $activate()->getStatusCode());
            // en mode « demo » de la plateforme, la clé neuve s'active comme avant
            $master->exec("UPDATE tenant_app_keys SET use_count = 0, last_used_at = NULL WHERE label = 'Essai ENT-1'");
            static::getContainer()->get(\App\Services\Platform\PlatformModeService::class)->setMode('demo', 'test');
            $this->assertSame(200, $activate()->getStatusCode());
        } finally {
            $switches->clear(MV_TEST_TENANT_CODE, 'app.mobile_shop');
            $master->exec("DELETE FROM tenant_app_keys WHERE label = 'Essai ENT-1'");
        }
    }

    public function testInDemoModeNothingIsRefusedByTheseRights(): void
    {
        $this->setFlags(false, false);
        static::getContainer()->get(\App\Services\Platform\PlatformModeService::class)->setMode('demo', 'test');
        $this->assertNotSame('MODULE_INACTIVE', $this->json($this->post('/api/caisse/open', []))['code'] ?? null);
        $this->assertNotSame('MODULE_INACTIVE', $this->json($this->post('/api/cart/quote', [], ['HTTP_X_APP' => 'mobile']))['code'] ?? null);
    }

    /** Un paiement au terminal du site de test (état, vente rattachée), pour essayer la suite d'une vente commencée */
    private function terminalPayment(string $status, ?int $orderId): int
    {
        $db = $this->provider->getEntityManager()->getConnection();
        $register = $db->fetchOne('SELECT id FROM cash_register ORDER BY id LIMIT 1');
        if ($register === false) {
            $db->executeStatement("INSERT INTO cash_register (id, name, is_active, created_at, terminal_enabled, stripe_reader_simulated) VALUES (nextval('cash_register_id_seq'), 'Poste ENT-1', true, NOW(), false, false)");
            $register = $db->fetchOne('SELECT id FROM cash_register ORDER BY id LIMIT 1');
        }
        $user = (int) $db->fetchOne('SELECT id FROM "user" ORDER BY id LIMIT 1');
        $intent = 'pi_ent1_' . bin2hex(random_bytes(6));
        $db->executeStatement("INSERT INTO pos_terminal_payment (id, stripe_payment_intent_id, cash_register_id, user_id, reader_id, amount, currency, status, quote_total, other_payments, quote_hash, application_fee, idempotency_key, created_at, updated_at, order_id)
            VALUES (nextval('pos_terminal_payment_id_seq'), ?, ?, ?, 'tmr_ent1', 1000, 'CAD', ?, 1000, 0, 'h', 0, ?, NOW(), NOW(), ?)", [$intent, (int) $register, $user, $status, 'ent1-' . bin2hex(random_bytes(6)), $orderId]);
        $id = (int) $db->fetchOne('SELECT id FROM pos_terminal_payment WHERE stripe_payment_intent_id = ?', [$intent]);
        $this->created[] = $id;

        return $id;
    }

    private function idempotencyKey(): string
    {
        $key = sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff));
        $this->provider->getEntityManager()->getConnection()->executeStatement("INSERT INTO pos_idempotency_key (id, idem_key, user_id, route, body_hash, status, created_at) VALUES (nextval('pos_idempotency_key_id_seq'), ?, 0, 'create-multi-payment', 'h', 'done', NOW())", [$key]);
        $this->keys[] = $key;

        return $key;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true);

        return is_array($data) ? $data : [];
    }

    private function entreprise(): Entreprise
    {
        $e = $this->provider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC']);
        $this->assertInstanceOf(Entreprise::class, $e);

        return $e;
    }

    private function setBoutique(bool $on): void
    {
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->entreprise()->setIsBoutiqueActive($on);
        $this->provider->getEntityManager()->flush();
        $this->provider->getEntityManager()->clear();
        static::getContainer()->get(\App\EventSubscriber\CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }

    private function setFlags(bool $pos, bool $mobile): void
    {
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $this->provider->getEntityManager();
        $this->entreprise()->setIsPosActive($pos)->setIsMobileShopActive($mobile);
        $em->flush();
        $em->clear();
        static::getContainer()->get(\App\EventSubscriber\CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }

    private function get(string $path, array $server = []): Response
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json'] + $server);

        return $this->client->getResponse();
    }

    private function post(string $path, array $body, array $server = []): Response
    {
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'] + $server, json_encode($body));

        return $this->client->getResponse();
    }
}
