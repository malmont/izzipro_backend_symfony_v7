<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Adress;
use App\Entity\Order;
use App\Entity\StatusCommande;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use App\UseCase\OrderUseCase\CancelOrderUseCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Commande web annulée deux fois : avant, la seconde annulation réintégrait le stock une seconde fois (et ajoutait un remboursement de 0).
 * Désormais 409 ORDER_NOT_REFUNDABLE, rien n'est écrit ; la première annulation reste inchangée (même réponse, même stock, même
 * remboursement). Même garde pour l'annulation d'EasyAdmin (statut passé à 7 dans le formulaire avant l'appel) : la première passe,
 * un nouvel enregistrement d'une commande déjà annulée ne réintègre plus rien.
 */
class WebOrderDoubleCancelApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';

    private KernelBrowser $client;
    private array $session = [];
    private \Doctrine\DBAL\Connection $db;
    private int $variant;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-annulation@example.invalid', false, false);
        FakeCheckoutPaymentGateway::reset();
        $this->variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 100 WHERE id = ?', [$this->variant]);
    }

    public function testASecondCancelOfAWebOrderIsRefusedAndWritesNothing(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $orderId = $this->webOrder(2);
        $this->assertSame(98, $this->stock(), 'deux casquettes vendues');
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT order_source_id FROM "order" WHERE id = ?', [$orderId]), 'commande web');

        // Première annulation : inchangée (réponse, statut, stock réintégré, un remboursement)
        $before = $this->state($orderId);
        $first = $this->request('POST', "/api/order/cancel/$orderId", json_encode([]));
        $this->assertSame(200, $first->getStatusCode(), $first->getContent());
        $this->assertSame(['message' => 'Order canceled and refunded successfully'], json_decode($first->getContent(), true), 'réponse web inchangée');
        $afterFirst = $this->state($orderId);
        $this->assertSame(7, $afterFirst['status']);
        $this->assertSame(100, $afterFirst['stock'], 'stock réintégré une fois');
        $this->assertSame([$before['refunds'] + 1, $before['movements'] + 1], [$afterFirst['refunds'], $afterFirst['movements']]);

        // Seconde annulation : refusée, rien n'est écrit
        $second = $this->request('POST', "/api/order/cancel/$orderId", json_encode([]));
        $this->assertSame(409, $second->getStatusCode(), $second->getContent());
        $body = json_decode($second->getContent(), true);
        $this->assertSame(['ORDER_NOT_REFUNDABLE', $orderId, 7], [$body['error'], $body['orderId'] ?? null, $body['status'] ?? null]);
        $this->assertSame($afterFirst, $this->state($orderId), 'stock, remboursements, mouvements et statut inchangés');
    }

    public function testEasyAdminCancelStillWorksOnceAndASecondSaveRestocksNothing(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $orderId = $this->webOrder(1);
        $this->assertSame(99, $this->stock());
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $useCase = static::getContainer()->get(CancelOrderUseCase::class);

        // Comme le formulaire d'EasyAdmin : statut passé à 7 sur l'entité, puis annulation (OrderCrudController::updateEntity)
        $order = $em->getRepository(Order::class)->find($orderId);
        $order->setStatus($em->getRepository(StatusCommande::class)->find(7));
        $first = $useCase->execute($orderId);
        $this->assertSame(200, $first->getStatusCode(), $first->getContent());
        $afterFirst = $this->state($orderId);
        $this->assertSame([7, 100], [$afterFirst['status'], $afterFirst['stock']], 'première annulation EasyAdmin : stock réintégré');

        // Nouvel enregistrement de la commande déjà annulée : plus de seconde réintégration
        $em->clear();
        $em->getRepository(Order::class)->find($orderId);
        $second = $useCase->execute($orderId);
        $this->assertSame(409, $second->getStatusCode(), $second->getContent());
        $this->assertSame($afterFirst, $this->state($orderId), 'rien n\'est écrit');
    }

    /** Commande web payée (Stripe simulé) de $quantity casquettes */
    private function webOrder(int $quantity): int
    {
        $order = ['addressId' => $this->address(), 'carrierId' => 6, 'items' => [['productVariantId' => $this->variant, 'quantity' => $quantity]]];
        $pi = $this->json($this->request('POST', '/api/stripe/create-intent', json_encode(['items' => $order['items'], 'carrierId' => 6, 'order' => $order])))['paymentIntentId'];
        FakeCheckoutPaymentGateway::confirm($pi);
        $created = $this->request('POST', '/api/order/create', json_encode($order + ['paymentIntentId' => $pi]));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());

        return (int) json_decode($created->getContent(), true)['orderId'];
    }

    private function stock(): int
    {
        return (int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$this->variant]);
    }

    private function state(int $orderId): array
    {
        return [
            'status' => (int) $this->db->fetchOne('SELECT status_id FROM "order" WHERE id = ?', [$orderId]),
            'stock' => $this->stock(),
            'refunds' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM payments WHERE order_payment_id = ? AND amount <= 0', [$orderId]),
            'movements' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM inventory_movements WHERE product_variant_id = ? AND movement_type_id = 1', [$this->variant]),
        ];
    }

    private function address(): int
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $this->session['email']]);
        $address = (new Adress())->setFirstname('Test')->setLastname('Annulation')->setFullname('Test Annulation')->setAddress('1 rue du Test')->setCity('Montréal')
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
        $email = 'annulation-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Client')->setLastname('Annulation')->setRoles($roles)->setIsVerified(true);
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
