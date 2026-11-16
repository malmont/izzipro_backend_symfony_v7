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
        // un début et sa suite
        $this->assertSame(AppRightRoutes::START, AppRightRoutes::pos('POST', '/api/order/create-multi-payment', ['paymentMethods' => [['type' => 0, 'amount' => 10]]]));
        $this->assertSame(AppRightRoutes::SERVED, AppRightRoutes::pos('POST', '/api/order/create-multi-payment', ['paymentMethods' => [['type' => 0, 'amount' => 5], ['type' => 1, 'amount' => 5, 'terminalPaymentId' => 'tp_1']]]), 'paiement au terminal déjà autorisé : la vente se termine');
        $this->assertSame(AppRightRoutes::SERVED, AppRightRoutes::pos('POST', '/api/order/create-multi-payment', ['paymentMethods' => [['type' => 1, 'amount' => 5, 'stripePaymentId' => 'pi_1']]]));
        $this->assertSame(AppRightRoutes::START, AppRightRoutes::pos('POST', '/api/order/create-multi-payment', ['paymentMethods' => [['type' => 1, 'amount' => 5, 'terminalPaymentId' => '  ']]]), 'une référence vide ne compte pas');
        $this->assertSame(AppRightRoutes::START, AppRightRoutes::pos('POST', '/api/pos/gift-cards', ['amount' => 20]));
        $this->assertSame(AppRightRoutes::SERVED, AppRightRoutes::pos('POST', '/api/pos/gift-cards', ['amount' => 20, 'terminalPaymentId' => 'tp_2']));
        $this->assertSame(AppRightRoutes::SERVED, AppRightRoutes::pos('POST', '/api/caisse/close'));
        $this->assertSame(AppRightRoutes::START, AppRightRoutes::pos('POST', '/api/pos/une-route-nouvelle'), 'non rangée : un début, fermé par défaut');
        $this->assertNull(AppRightRoutes::pos('GET', '/api/products'));
        $this->assertSame(AppRightRoutes::SERVED, AppRightRoutes::pos('HEAD', '/api/caisse'));
    }

    public function testWithoutTheRightTheCashRegisterCannotStartButAStartedSaleEnds(): void
    {
        $this->setFlags(false, true);
        foreach (['/api/caisse/open', '/api/pos/terminal/payments', '/api/pos/terminal/connection-token', '/api/pos/exchanges', '/api/pos/rentals/deposits'] as $path) {
            $body = $this->json($this->post($path, []));
            $this->assertSame(['MODULE_INACTIVE', 'pos', 'Le droit à la caisse de ce site a pris fin.'], [$body['code'] ?? null, $body['module'] ?? null, $body['message'] ?? null], $path);
        }
        $this->assertSame('MODULE_INACTIVE', $this->json($this->post('/api/order/create-multi-payment', ['paymentMethods' => [['type' => 0, 'amount' => 10]]]))['code'] ?? null);
        // la SUITE d'un paiement commencé, la fermeture, les reçus, les ventes hors ligne, les lectures : jamais refusées par le droit
        foreach ([['POST', '/api/order/create-multi-payment', ['paymentMethods' => [['type' => 1, 'amount' => 10, 'terminalPaymentId' => 'tp_1']]]], ['POST', '/api/caisse/close', []],
            ['POST', '/api/pos/offline-sales', []], ['POST', '/api/pos/receipts/reprint', []], ['GET', '/api/caisse', null], ['POST', '/api/pos/terminal/payments/1/cancel', []]] as [$method, $path, $body]) {
            $response = $method === 'GET' ? $this->get($path) : $this->post($path, $body);
            $this->assertNotSame('MODULE_INACTIVE', $this->json($response)['code'] ?? null, "$method $path");
        }
        // le droit revenu : servi aussitôt
        $this->setFlags(true, true);
        $this->assertNotSame('MODULE_INACTIVE', $this->json($this->post('/api/caisse/open', []))['code'] ?? null);
    }

    public function testTheMobileAppIsGuardedByItsOwnRightWhenItSaysItIsTheMobileApp(): void
    {
        $this->setFlags(true, false);
        $mobile = $this->json($this->post('/api/cart/quote', [], ['HTTP_X_APP' => 'mobile']));
        $this->assertSame(['MODULE_INACTIVE', 'mobile_shop', 'Le droit à l\'application mobile de ce site a pris fin.'], [$mobile['code'] ?? null, $mobile['module'] ?? null, $mobile['message'] ?? null]);
        $this->assertSame('MODULE_INACTIVE', $this->json($this->post('/api/stripe/create-intent', [], ['HTTP_X_APP' => 'Mobile']))['code'] ?? null);
        // sans l'en-tête (le web, ou une application ancienne) : servi comme le web (limite écrite dans la fiche)
        $this->assertNotSame('MODULE_INACTIVE', $this->json($this->post('/api/cart/quote', []))['code'] ?? null);
        // une lecture de l'application mobile n'est pas un début de parcours
        $this->assertNotSame('MODULE_INACTIVE', $this->json($this->get('/api/products', ['HTTP_X_APP' => 'mobile']))['code'] ?? null);
    }

    public function testInDemoModeNothingIsRefusedByTheseRights(): void
    {
        $this->setFlags(false, false);
        static::getContainer()->get(\App\Services\Platform\PlatformModeService::class)->setMode('demo', 'test');
        $this->assertNotSame('MODULE_INACTIVE', $this->json($this->post('/api/caisse/open', []))['code'] ?? null);
        $this->assertNotSame('MODULE_INACTIVE', $this->json($this->post('/api/cart/quote', [], ['HTTP_X_APP' => 'mobile']))['code'] ?? null);
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
