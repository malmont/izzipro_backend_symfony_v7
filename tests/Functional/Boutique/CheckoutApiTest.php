<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Adress;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use App\Tests\Fake\FakeSubscriptionStripeGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Paiement d'un panier (09/10/2026) : un intent par panier, commande unique par paiement (navigateur ou webhook),
 * idempotente ; commande refusée = autorisation annulée ; pays déduit d'une province canadienne ; abonnement en double.
 * Stripe simulé (FakeCheckoutPaymentGateway, FakeSubscriptionStripeGateway).
 */
class CheckoutApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';

    private KernelBrowser $client;
    private array $session = [];
    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-paiement@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        FakeCheckoutPaymentGateway::reset();
        FakeSubscriptionStripeGateway::reset();
    }

    public function testOneIntentPerCartAndOneOrderPerPaymentForACustomer(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $variant = $this->variant('DEMO-CASQUETTE');
        $order = ['addressId' => $this->address(), 'carrierId' => 6, 'items' => [['productVariantId' => $variant, 'quantity' => 1]]];

        $first = $this->json($this->request('POST', '/api/stripe/create-intent', json_encode(['items' => $order['items'], 'carrierId' => 6, 'order' => $order])));
        $pi = $first['paymentIntentId'];
        $this->assertFalse($first['reused']);
        $this->assertStringStartsWith($pi, $first['clientSecret']);
        $two = $this->json($this->request('POST', '/api/stripe/create-intent', json_encode(['items' => [['productVariantId' => $variant, 'quantity' => 2]], 'carrierId' => 6, 'paymentIntentId' => $pi])));
        $this->assertSame([$pi, true], [$two['paymentIntentId'], $two['reused']], 'même panier : même intent');
        $this->assertSame($two['calculatedAmount'], FakeCheckoutPaymentGateway::$intents[$pi]['amount'], 'intent remis au nouveau montant');
        $back = $this->json($this->request('POST', '/api/stripe/create-intent', json_encode(['items' => $order['items'], 'carrierId' => 6, 'paymentIntentId' => $pi])));
        $this->assertSame($first['calculatedAmount'], $back['calculatedAmount']);

        $unpaid = $this->request('POST', '/api/order/create', json_encode($order + ['paymentIntentId' => $pi]));
        $this->assertSame(402, $unpaid->getStatusCode(), 'paiement pas encore autorisé');

        FakeCheckoutPaymentGateway::confirm($pi);
        $created = $this->request('POST', '/api/order/create', json_encode($order + ['paymentIntentId' => $pi]));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $orderId = json_decode($created->getContent(), true)['orderId'];
        $again = $this->request('POST', '/api/order/create', json_encode($order + ['paymentIntentId' => $pi]));
        $this->assertSame(200, $again->getStatusCode(), $again->getContent());
        $this->assertSame([$orderId, true], [json_decode($again->getContent(), true)['orderId'], json_decode($again->getContent(), true)['alreadyCreated']]);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM payments WHERE stripe_payment_id = ?', [$pi]), 'une seule commande pour ce paiement');
        $this->assertSame('succeeded', FakeCheckoutPaymentGateway::$intents[$pi]['status'], 'paiement capturé');
        $this->assertSame(['ordered', $orderId], array_values($this->db->fetchAssociative('SELECT status, order_id FROM checkout_session WHERE payment_intent_id = ?', [$pi])));
        $this->assertSame('visa', $this->db->fetchOne('SELECT stripe_card_brand FROM payments WHERE stripe_payment_id = ?', [$pi]));

        $intruder = $this->session;
        $this->loginAs(['ROLE_USER_INTERNET']);
        $stolen = $this->request('POST', '/api/order/create', json_encode(['addressId' => $this->address(), 'items' => $order['items'], 'paymentIntentId' => $pi]));
        $this->assertSame(409, $stolen->getStatusCode(), 'paiement d\'un autre client');
        $other = $this->json($this->request('POST', '/api/stripe/create-intent', json_encode(['items' => $order['items'], 'paymentIntentId' => $pi])));
        $this->assertNotSame($pi, $other['paymentIntentId'], 'l\'intent d\'un autre client n\'est jamais repris');
        $this->session = $intruder;
    }

    public function testGuestOrderIsIdempotentAndCountryIsDeducedFromTheProvince(): void
    {
        $this->session = [];
        $variant = $this->variant('DEMO-CASQUETTE');
        $body = $this->guestBody($variant, 'invite-' . bin2hex(random_bytes(3)) . '@example.invalid');
        $pi = $this->json($this->request('POST', '/api/stripe/create-intent', json_encode(['items' => $body['items'], 'carrierId' => 6, 'shippingAddress' => ['province' => 'QC']])))['paymentIntentId'];
        FakeCheckoutPaymentGateway::confirm($pi);

        $created = $this->request('POST', '/api/order/create-guest', json_encode($body + ['paymentIntentId' => $pi]));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $first = json_decode($created->getContent(), true);
        $this->assertNotEmpty($first['guestToken']);
        $again = json_decode($this->request('POST', '/api/order/create-guest', json_encode($body + ['paymentIntentId' => $pi]))->getContent(), true);
        $this->assertSame([$first['orderId'], $first['guestToken'], true], [$again['orderId'], $again['guestToken'], $again['alreadyCreated']], 'même paiement, même e-mail : commande et jeton renvoyés');
        $other = $body;
        $other['guestInfo']['email'] = 'quelqu-un-d-autre@example.invalid';
        $this->assertSame(409, $this->request('POST', '/api/order/create-guest', json_encode($other + ['paymentIntentId' => $pi]))->getStatusCode());

        $row = $this->db->fetchAssociative('SELECT a.country, o.total_tax FROM "order" o JOIN adress a ON a.id = o.shipping_adress_id WHERE o.id = ?', [$first['orderId']]);
        $this->assertSame('CA', $row['country'], 'pays absent : Canada déduit de la province');
        $this->assertGreaterThan(0, (float) $row['total_tax'], 'taxes du Québec appliquées');
    }

    public function testTheWebhookCreatesTheOrderWhenTheBrowserNeverDid(): void
    {
        $this->session = [];
        $variant = $this->variant('DEMO-CASQUETTE');
        $email = 'onglet-ferme-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $body = $this->guestBody($variant, $email);
        $pi = $this->json($this->request('POST', '/api/stripe/create-intent', json_encode(['items' => $body['items'], 'carrierId' => 6, 'order' => $body])))['paymentIntentId'];
        FakeCheckoutPaymentGateway::confirm($pi);

        $webhook = $this->webhook('payment_intent.amount_capturable_updated', $pi);
        $this->assertSame('processed', $webhook['status'], json_encode($webhook));
        $orderId = (int) $this->db->fetchOne('SELECT order_id FROM checkout_session WHERE payment_intent_id = ?', [$pi]);
        $this->assertGreaterThan(0, $orderId);
        $this->assertSame($email, $this->db->fetchOne('SELECT u.email FROM "order" o JOIN "user" u ON u.id = o.user_id_id WHERE o.id = ?', [$orderId]));
        $this->assertSame('succeeded', FakeCheckoutPaymentGateway::$intents[$pi]['status']);
        $this->assertSame('ignored', $this->webhook('payment_intent.amount_capturable_updated', $pi)['status'], 'évènement rejoué');
        $late = json_decode($this->request('POST', '/api/order/create-guest', json_encode($body + ['paymentIntentId' => $pi]))->getContent(), true);
        $this->assertSame([$orderId, true], [$late['orderId'], $late['alreadyCreated']], 'le navigateur revenu plus tard reçoit la commande du webhook');
        $this->assertNotEmpty($late['guestToken']);

        // Sans corps de commande gardé à create-intent : rien n'est créé
        $bare = $this->json($this->request('POST', '/api/stripe/create-intent', json_encode(['items' => $body['items']])))['paymentIntentId'];
        FakeCheckoutPaymentGateway::confirm($bare);
        $this->assertSame('ignored', $this->webhook('payment_intent.amount_capturable_updated', $bare)['status']);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM payments WHERE stripe_payment_id = ?', [$bare]));
    }

    public function testARefusedOrderReleasesThePayment(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $variant = $this->variant('DEMO-CASQUETTE');
        $order = ['addressId' => $this->address(), 'items' => [['productVariantId' => $variant, 'quantity' => 1]]];
        $pi = $this->json($this->request('POST', '/api/stripe/create-intent', json_encode(['items' => $order['items']])))['paymentIntentId'];
        FakeCheckoutPaymentGateway::confirm($pi);
        $stock = (int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$variant]);
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 0 WHERE id = ?', [$variant]);
        try {
            $refused = $this->request('POST', '/api/order/create', json_encode($order + ['paymentIntentId' => $pi]));
            $this->assertSame(400, $refused->getStatusCode(), $refused->getContent());
            $this->assertSame('canceled', FakeCheckoutPaymentGateway::$intents[$pi]['status'], 'autorisation libérée : le client n\'est pas débité');
            $this->assertSame('failed', $this->db->fetchOne('SELECT status FROM checkout_session WHERE payment_intent_id = ?', [$pi]));
        } finally {
            $this->db->executeStatement('UPDATE product_variant SET stock_quantity = ? WHERE id = ?', [$stock, $variant]);
        }
    }

    public function testDuplicateSubscriptionsAreReusedOrRefused(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $address = $this->address();
        $plans = $this->json($this->request('GET', '/api/subscription-plans?productId=' . $this->productId('DEMO-PANIER')));
        $body = ['planId' => $plans[0]['id'], 'quantity' => 1, 'addressId' => $address];

        $first = $this->request('POST', '/api/subscriptions', json_encode($body));
        $this->assertSame(201, $first->getStatusCode(), $first->getContent());
        $first = json_decode($first->getContent(), true);
        $again = $this->request('POST', '/api/subscriptions', json_encode($body));
        $this->assertSame(200, $again->getStatusCode(), 'double clic : abonnement incomplet repris');
        $this->assertSame([$first['subscriptionId'], $first['clientSecret'], true], [json_decode($again->getContent(), true)['subscriptionId'], json_decode($again->getContent(), true)['clientSecret'], json_decode($again->getContent(), true)['reused']]);

        $other = $this->request('POST', '/api/subscriptions', json_encode(['planId' => $plans[2]['id']] + $body));
        $this->assertSame(201, $other->getStatusCode(), 'autre formule : l\'incomplet précédent est annulé');
        $this->assertSame('canceled', $this->db->fetchOne('SELECT status FROM subscription WHERE id = ?', [$first['subscriptionId']]));
        $this->db->executeStatement("UPDATE subscription SET status = 'trialing' WHERE id = ?", [json_decode($other->getContent(), true)['subscriptionId']]);
        $refused = $this->request('POST', '/api/subscriptions', json_encode($body));
        $this->assertSame(409, $refused->getStatusCode(), 'abonnement en cours au même produit');
    }

    private function guestBody(int $variant, string $email): array
    {
        return [
            'guestInfo' => ['email' => $email, 'firstName' => 'Invité', 'lastName' => 'Test', 'phone' => '5145550100'],
            'items' => [['productVariantId' => $variant, 'quantity' => 1]], 'carrierId' => 6,
            // Sans pays : le Canada est déduit de la province
            'shippingAddress' => ['firstname' => 'Invité', 'lastname' => 'Test', 'address' => '1 rue du Test', 'city' => 'Québec', 'province' => 'QC', 'postalCode' => 'G1R 4P5'],
        ];
    }

    private function webhook(string $type, string $pi): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/stripe/webhook', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['id' => 'evt_' . bin2hex(random_bytes(4)), 'type' => $type, 'data' => ['object' => ['id' => $pi, 'object' => 'payment_intent', 'metadata' => ['tenant_code' => MV_TEST_TENANT_CODE]]]]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function variant(string $code): int
    {
        return (int) $this->db->fetchOne('SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = ? ORDER BY v.id LIMIT 1', [$code]);
    }

    private function productId(string $code): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM product WHERE code = ?', [$code]);
    }

    private function address(): int
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $this->session['email']]);
        $address = (new Adress())->setFirstname('Test')->setLastname('Paiement')->setFullname('Test Paiement')->setAddress('1 rue du Test')->setCity('Montréal')
            ->setCodepostal('H1A 1A1')->setCountry('CA')->setPhone('+1 514 555 0000')->setProvince('QC')->setUserAdress($user);
        $em->persist($address);
        $em->flush();

        return (int) $address->getId();
    }

    private function json(Response $response): array
    {
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function request(string $method, string $path, ?string $body = null): Response
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($this->session as $name => $value) {
            if ($name !== 'email') {
                $jar->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
            }
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $this->session['XSRF-TOKEN_' . MV_TEST_TENANT_CODE] ?? null,
        ]), $body);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): void
    {
        $email = 'paiement-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Client')->setLastname('Paiement')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->session = ['email' => $email];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $this->session[$cookie->getName()] = $cookie->getValue();
        }
    }
}
