<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Adress;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * § 8 : compte client de la boutique réglable : statuts de commande, détail d'une commande (client ou invité avec jeton),
 * liste des commandes en cents, nouveau mot de passe par l'API.
 */
class CustomerAccountApiTest extends WebTestCase
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
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-compte@example.invalid', false, false);
    }

    public function testOrderStatusesAreListedInTheRequestedLanguage(): void
    {
        $fr = $this->json($this->request('GET', '/api/order-statuses'));
        $this->assertSame(7, count($fr));
        $this->assertSame([1, 'Incomplete'], [$fr[0]['id'], $fr[0]['name']]);
        $this->assertSame('Livrée', $fr[5]['name']);
        $en = $this->json($this->request('GET', '/api/order-statuses?locale=en'));
        $this->assertSame('Delivered', $en[5]['name']);
    }

    public function testCustomerReadsHisOrderAndGuestsNeedTheirToken(): void
    {
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']); // membre du personnel : commande créée sans Stripe
        $owner = $this->session['email'];
        $variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE'");
        $created = $this->request('POST', '/api/order/create', json_encode(['addressId' => $this->address(), 'carrierId' => 6, 'items' => [['productVariantId' => $variant, 'quantity' => 1]]]));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $orderId = (int) json_decode($created->getContent())->orderId;
        $this->db->executeStatement('UPDATE "order" SET guest_token = ? WHERE id = ?', ['jeton-de-test-0123456789abcdef', $orderId]);

        $order = $this->json($this->request('GET', "/api/orders/$orderId"));
        $this->assertSame($orderId, $order['id']);
        $this->assertSame('CAD', $order['currency']);
        $this->assertIsInt($order['totalAmount']);
        $price = (int) round((float) $this->db->fetchOne("SELECT price FROM product WHERE code = 'DEMO-CASQUETTE'")); // un autre test peut l'avoir modifié
        $this->assertSame($price, $order['orderItems'][0]['unitPrice'], 'cents entiers');
        $this->assertSame($variant, $order['orderItems'][0]['productVariantId']);
        $this->assertArrayHasKey('customizationId', $order['orderItems'][0]);
        $this->assertSame(['id' => 6, 'name' => $this->db->fetchOne('SELECT name FROM carrier WHERE id = 6')], array_intersect_key($order['carrier'], ['id' => 1, 'name' => 1]));
        $this->assertIsInt($order['statusId']);

        $list = $this->json($this->request('GET', '/api/ordersuser'));
        $this->assertSame($orderId, $list[0]['id']);
        $this->assertSame($order['totalAmount'], $list[0]['totalAmount'], 'même forme dans la liste et le détail');

        // Un autre client : 404 ; anonyme : 401 ; invité avec le bon jeton : 200 ; mauvais jeton : 404
        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->assertSame(404, $this->request('GET', "/api/orders/$orderId")->getStatusCode());
        $this->session = [];
        $this->assertSame(401, $this->request('GET', "/api/orders/$orderId")->getStatusCode());
        $this->assertSame(200, $this->request('GET', "/api/orders/$orderId?token=jeton-de-test-0123456789abcdef")->getStatusCode());
        $this->assertSame(404, $this->request('GET', "/api/orders/$orderId?token=faux")->getStatusCode());
        $this->assertSame(404, $this->request('GET', '/api/orders/999999?token=jeton-de-test-0123456789abcdef')->getStatusCode());
        $this->assertNotEmpty($owner);
    }

    public function testPasswordIsResetThroughTheApiWithTheEmailedToken(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $email = $this->session['email'];
        $this->session = [];

        $this->assertSame(200, $this->request('POST', '/api/password-reset/request', json_encode(['email' => $email]))->getStatusCode());
        $token = $this->db->fetchOne('SELECT reset_token FROM "user" WHERE email = ?', [$email]);
        $this->assertNotEmpty($token, 'jeton posé par la demande');

        $this->assertSame(400, $this->request('POST', '/api/password-reset/confirm', json_encode(['token' => $token, 'password' => 'court']))->getStatusCode());
        $this->assertSame(400, $this->request('POST', '/api/password-reset/confirm', json_encode(['token' => 'inconnu', 'password' => 'Nouveau-mot-de-passe-1!']))->getStatusCode());
        $confirmed = $this->request('POST', '/api/password-reset/confirm', json_encode(['token' => $token, 'password' => 'Nouveau-mot-de-passe-1!']));
        $this->assertSame(200, $confirmed->getStatusCode(), $confirmed->getContent());
        $this->assertNull($this->db->fetchOne('SELECT reset_token FROM "user" WHERE email = ?', [$email]), 'jeton consommé');

        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['username' => $email, 'password' => 'Nouveau-mot-de-passe-1!', 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'connexion avec le nouveau mot de passe');
    }

    private function address(): int
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $this->session['email']]);
        $address = (new Adress())->setFirstname('Test')->setLastname('Compte')->setFullname('Test Compte')->setAddress('1 rue du Test')->setCity('Montréal')
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
        $email = 'compte-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Compte')->setLastname('Client')->setRoles($roles)->setIsVerified(true);
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
