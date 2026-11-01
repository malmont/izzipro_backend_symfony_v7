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
 * Commandes du client (01/11/2026, application mobile) : GET /api/ordersuser sans pagination inchangé (tableau complet,
 * aucun en-tête), avec ?page&limit (page + X-Total-Count) ; paymentStatus et shipment sur la liste et le détail.
 */
class CustomerOrdersApiTest extends WebTestCase
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
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-commandes@example.invalid', false, false);
    }

    public function testThePlainListIsUnchangedAndThePaginatedListCarriesTheTotal(): void
    {
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']); // membre du personnel : commandes créées sans Stripe
        $ids = [$this->order(), $this->order(), $this->order()];

        $plain = $this->request('GET', '/api/ordersuser');
        $this->assertSame(200, $plain->getStatusCode());
        $list = json_decode($plain->getContent(), true);
        $this->assertSame(3, count($list), 'sans pagination : tableau complet');
        $this->assertFalse($plain->headers->has('X-Total-Count'), 'sans pagination : aucun en-tête ajouté');
        $this->assertSame(array_reverse($ids), array_column($list, 'id'), 'de la plus récente à la plus ancienne');
        $this->assertSame(['paid', null], [$list[0]['paymentStatus'], $list[0]['shipment']], 'commande du personnel : paiement complété enregistré à la création ; aucune étiquette');
        $this->db->executeStatement('DELETE FROM payments WHERE order_payment_id = ?', [$ids[2]]);
        $this->assertSame('pending', json_decode($this->request('GET', '/api/orders/' . $ids[2])->getContent(), true)['paymentStatus'], 'sans paiement : pending');

        $page1 = $this->request('GET', '/api/ordersuser?page=1&limit=2');
        $this->assertSame(200, $page1->getStatusCode());
        $this->assertSame(['3', '1', '2'], [$page1->headers->get('X-Total-Count'), $page1->headers->get('X-Page'), $page1->headers->get('X-Limit')]);
        $items = json_decode($page1->getContent(), true);
        $this->assertSame(array_slice(array_reverse($ids), 0, 2), array_column($items, 'id'));
        $this->assertSame(array_keys($list[0]), array_keys($items[0]), 'même forme que la liste complète');
        $page2 = json_decode($this->request('GET', '/api/ordersuser?page=2&limit=2')->getContent(), true);
        $this->assertSame([$ids[0]], array_column($page2, 'id'));
        $this->assertSame([], json_decode($this->request('GET', '/api/ordersuser?page=3&limit=2')->getContent(), true), 'page vide : tableau vide');
    }

    public function testPaymentStatusAndShipmentAreExposedOnTheListAndTheDetail(): void
    {
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $paid = $this->order();
        $canceled = $this->order();
        $refunded = $this->order();
        $authorized = $this->order();
        // Les commandes du personnel naissent avec un paiement complété : retiré pour tester chaque état
        $this->db->executeStatement('DELETE FROM payments WHERE order_payment_id IN (?, ?, ?, ?)', [$paid, $canceled, $refunded, $authorized]);
        $this->db->executeStatement("INSERT INTO payments (id, order_payment_id, payment_method_id, statut_payment_id, payment_type_id, amount, payment_date, stripe_payment_id, stripe_status)
            VALUES (nextval('payments_id_seq'), ?, 2, 2, 2, 1000, NOW(), 'pi_test_paid', 'succeeded')", [$paid]);
        $this->db->executeStatement('UPDATE "order" SET status_id = 7 WHERE id = ?', [$canceled]);
        $this->db->executeStatement("INSERT INTO payments (id, order_payment_id, payment_method_id, statut_payment_id, payment_type_id, amount, payment_date)
            VALUES (nextval('payments_id_seq'), ?, 2, 2, 1, 1000, NOW())", [$refunded]);
        $this->db->executeStatement("INSERT INTO payments (id, order_payment_id, payment_method_id, statut_payment_id, payment_type_id, amount, payment_date, stripe_payment_id, stripe_status)
            VALUES (nextval('payments_id_seq'), ?, 2, 1, 2, 1000, NOW(), 'pi_test_auth', 'requires_capture')", [$authorized]);
        // Étiquette d'expédition de la commande payée (transporteur 6 du jeu d'essai)
        $this->db->executeStatement("UPDATE carrier SET name = 'Purolator' WHERE id = 6");
        $this->db->executeStatement("INSERT INTO shipping_order (id, odershipping_id, service, total_price, created_at) VALUES (nextval('shipping_order_id_seq'), ?, 'Ground', 1500, NOW())", [$paid]);
        $shippingOrder = (int) $this->db->fetchOne('SELECT id FROM shipping_order WHERE odershipping_id = ?', [$paid]);
        $this->db->executeStatement("INSERT INTO parcel (id, shipping_order_id, index, weight) VALUES (nextval('parcel_id_seq'), ?, 0, 1.2)", [$shippingOrder]);
        $parcel = (int) $this->db->fetchOne('SELECT id FROM parcel WHERE shipping_order_id = ?', [$shippingOrder]);
        $this->db->executeStatement("INSERT INTO shipping_label (id, parcel_id, label_url, tracking_code, created_at) VALUES (nextval('shipping_label_id_seq'), ?, 'https://labels.test/1.pdf', 'PUR123456', NOW())", [$parcel]);

        $byId = [];
        foreach (json_decode($this->request('GET', '/api/ordersuser')->getContent(), true) as $order) {
            $byId[$order['id']] = $order;
        }
        $this->assertSame(['paid', 'canceled', 'refunded', 'authorized'], [$byId[$paid]['paymentStatus'], $byId[$canceled]['paymentStatus'], $byId[$refunded]['paymentStatus'], $byId[$authorized]['paymentStatus']]);
        $carrierName = $this->db->fetchOne("SELECT COALESCE((SELECT name FROM carrier_translation WHERE carrier_id = 6 AND language = 'fr'), name) FROM carrier WHERE id = 6");
        $this->assertSame(['carrier' => $carrierName, 'trackingNumber' => 'PUR123456', 'trackingUrl' => 'https://www.purolator.com/fr/expedition/suivi?pin=PUR123456'], $byId[$paid]['shipment'], 'nom du transporteur dans la langue demandée, page de suivi d\'après le transporteur');
        $this->assertNull($byId[$canceled]['shipment']);

        $detail = json_decode($this->request('GET', '/api/orders/' . $paid)->getContent(), true);
        $this->assertSame([$byId[$paid]['paymentStatus'], $byId[$paid]['shipment']], [$detail['paymentStatus'], $detail['shipment']], 'même valeurs dans le détail');
    }

    private function order(): int
    {
        $variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $created = $this->request('POST', '/api/order/create', json_encode(['addressId' => $this->address(), 'carrierId' => 6, 'items' => [['productVariantId' => $variant, 'quantity' => 1]]]));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $id = (int) json_decode($created->getContent(), true)['orderId'];
        // Dates distinctes pour un ordre stable
        $this->db->executeStatement('UPDATE "order" SET order_date = NOW() + (? * INTERVAL \'1 second\') WHERE id = ?', [$id, $id]);

        return $id;
    }

    private function address(): int
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $this->session['email']]);
        $address = (new Adress())->setFirstname('Test')->setLastname('Commandes')->setFullname('Test Commandes')->setAddress('1 rue du Test')->setCity('Montréal')
            ->setCodepostal('H1A 1A1')->setCountry('CA')->setPhone('+1 514 555 0000')->setProvince('QC')->setUserAdress($user);
        $em->persist($address);
        $em->flush();

        return (int) $address->getId();
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
        $email = 'commandes-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Test')->setLastname('Commandes')->setRoles($roles)->setIsVerified(true);
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
