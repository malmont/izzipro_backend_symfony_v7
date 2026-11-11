<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Adress;
use App\Entity\Order;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\PosReturnService\PosReturnService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Retours web et changement d'adresse, lot 7 (07/11/2026) : retour d'une unité sur deux (remboursement Stripe au prix payé avec sa part de
 * taxes, remise en stock, courriel, journal), refus au-delà du retournable, second retour puis plus rien à retourner, retours visibles par le
 * client ; nouvelle adresse de livraison avant expédition (carnet du client intact), refusée après expédition ; anonyme repoussé.
 */
class OrderReturnsTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private array $session = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-retours@example.invalid', false, false);
        FakeCheckoutPaymentGateway::reset();
    }

    public function testReturnsRefundAtPaidPriceRestockAndStopWhenEverythingIsBack(): void
    {
        [$orderId, $pi, $total, $variantId, $stockBeforeOrder] = $this->paidOrder(2);
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $order = $em->getRepository(Order::class)->find($orderId);
        $item = $order->getOrderItems()->first();
        $lineNet = (int) round((float) $item->getTotalPrice());
        $taxes = array_map(fn ($t) => (int) round((float) $t->getAmount()), $order->getOrderTaxes()->toArray());
        $expectedUnit = PosReturnService::taxForQuantity($lineNet, 2, 0, 1) + array_sum(array_map(fn ($t) => PosReturnService::taxForQuantity($t, 2, 0, 1), $taxes));
        $this->assertSame($stockBeforeOrder - 2, (int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$variantId]), 'deux unités sorties du stock à la commande');

        $this->loginAdmin();
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/retour");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('2', $crawler->filter('[data-return-line] input[name^="quantity"]')->attr('max'));
        $this->assertStringContainsString(number_format($expectedUnit / 100, 2, ',', ' '), $this->page(), 'montant par unité rendue affiché');
        $token = $crawler->filter('form[data-return-form] input[name="_token"]')->attr('value');

        // Une unité rendue : remboursement exact, stock +1, écriture, courriel, journal
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/retour", ['_token' => $token, 'quantity' => [$item->getId() => '1'], 'reason' => 'Trop petit', 'restock' => '1', 'notify' => '1']);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertCount(1, FakeCheckoutPaymentGateway::$refunds);
        $this->assertSame([$pi, $expectedUnit], [FakeCheckoutPaymentGateway::$refunds[0]['intent'], FakeCheckoutPaymentGateway::$refunds[0]['amount']]);
        $this->assertEmailCount(1);
        $mail = (string) $this->getMailerMessage()->getHtmlBody();
        $this->assertStringContainsString('Retour enregistré', $mail);
        $this->assertStringContainsString('Trop petit', $mail);
        $this->assertStringContainsString('1 × ', $mail);
        $row = $this->db->fetchAssociative('SELECT amount, restock, reason, lines::text AS lines FROM order_return WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$orderId]);
        $this->assertSame([$expectedUnit, true, 'Trop petit', 1], [(int) $row['amount'], (bool) $row['restock'], $row['reason'], json_decode($row['lines'], true)[0]['quantity']]);
        $this->assertSame($stockBeforeOrder - 1, (int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$variantId]), 'une unité remise en stock');
        $this->assertSame('return', $this->db->fetchOne("SELECT action FROM content_audit_log WHERE resource = 'order' AND resource_id = ? ORDER BY id DESC LIMIT 1", [(string) $orderId]));
        $this->client->followRedirect();
        $this->assertStringContainsString('Retour enregistré sur la commande', $this->page());
        $this->assertStringContainsString('data-order-returns', $this->page(), 'la fiche montre le retour');

        // Au-delà du retournable : refusé ; le reste : accepté ; puis plus rien à retourner
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/retour");
        $this->assertStringContainsString('data-return-history', $this->page());
        $this->assertSame('1', $crawler->filter('[data-return-line] input[name^="quantity"]')->attr('max'));
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/retour", ['_token' => $token, 'quantity' => [$item->getId() => '2'], 'reason' => '', 'restock' => '0', 'notify' => '0']);
        $this->client->followRedirect();
        $this->assertStringContainsString('Quantité supérieure', $this->page());
        $this->assertCount(1, FakeCheckoutPaymentGateway::$refunds);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/retour", ['_token' => $token, 'quantity' => [$item->getId() => '1'], 'reason' => 'Défectueux', 'restock' => '0', 'notify' => '0']);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertCount(2, FakeCheckoutPaymentGateway::$refunds);
        $this->assertSame($lineNet + array_sum($taxes), array_sum(array_column(FakeCheckoutPaymentGateway::$refunds, 'amount')), 'les deux retours rendent exactement la ligne et ses taxes, jamais la livraison');
        $this->assertSame($stockBeforeOrder - 1, (int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$variantId]), 'défectueux : pas remis en stock');
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/retour");
        $this->assertStringContainsString('data-return-blocked', $this->page());
        $this->assertStringContainsString('déjà été retournés', $this->page());

        // Le client voit ses retours
        $api = $this->request('GET', "/api/orders/$orderId");
        $this->assertSame(200, $api->getStatusCode(), $api->getContent());
        $body = json_decode((string) $api->getContent(), true);
        $this->assertCount(2, $body['returns']);
        $this->assertSame([1, $expectedUnit], [$body['returns'][0]['lines'][0]['quantity'], $body['returns'][0]['amountCents']]);
        $this->assertSame('refunded', $body['paymentStatus']);
    }

    /** Lot VAR (11/11/2026) : commande web passée pendant une promotion de variante (interrupteur allumé) ; le retour rend le prix payé, figé */
    public function testWebReturnOfALineSoldUnderAVariantPromotionRefundsThePaidPrice(): void
    {
        $variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement("UPDATE product SET price = 2900, special_price = NULL WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement('UPDATE product_variant SET price = NULL WHERE id = ?', [$variant]);
        $this->db->executeStatement('UPDATE entreprise SET variant_promotions_enabled = true');
        $promotion = (int) $this->db->fetchOne("INSERT INTO variant_promotion (product_variant_id, percent, price, base_price, start_date, end_date, starts_at, ends_at, created_at)
            VALUES (?, 20, 2299, 2900, CURRENT_DATE - 1, CURRENT_DATE + 10, NOW() - INTERVAL '1 day', NOW() + INTERVAL '10 days', NOW()) RETURNING id", [$variant]);
        try {
            [$orderId, $pi] = $this->paidOrder(2);
            $item = $this->db->fetchAssociative('SELECT id, unit_price, total_price, variant_promotion_id FROM order_items WHERE order_associated_id = ?', [$orderId]);
            $this->assertSame([2299.0, 4598.0, $promotion], [(float) $item['unit_price'], (float) $item['total_price'], (int) $item['variant_promotion_id']], 'ligne figée au prix promotionnel');
            $taxes = array_map('intval', $this->db->fetchFirstColumn('SELECT ROUND(amount) FROM order_tax WHERE order_tax_id = ?', [$orderId]));
            $expected = PosReturnService::taxForQuantity(4598, 2, 0, 1) + array_sum(array_map(fn ($t) => PosReturnService::taxForQuantity($t, 2, 0, 1), $taxes));
            // la promotion se termine avant le retour : le retour rend quand même le prix payé
            $this->db->executeStatement("UPDATE variant_promotion SET ends_at = NOW() - INTERVAL '1 minute' WHERE id = ?", [$promotion]);
            $this->loginAdmin();
            $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/retour");
            $token = $crawler->filter('form[data-return-form] input[name="_token"]')->attr('value');
            $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/retour", ['_token' => $token, 'quantity' => [$item['id'] => '1'], 'reason' => 'Trop petit', 'restock' => '1', 'notify' => '0']);
            $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
            $this->assertSame([$pi, $expected], [FakeCheckoutPaymentGateway::$refunds[0]['intent'], FakeCheckoutPaymentGateway::$refunds[0]['amount']]);
            $this->assertGreaterThan(2299, $expected);
            $this->assertLessThan(2900, $expected, 'jamais le prix catalogue');
        } finally {
            $this->db->executeStatement('DELETE FROM variant_promotion');
            $this->db->executeStatement('UPDATE entreprise SET variant_promotions_enabled = false');
        }
    }

    public function testAddressChangesBeforeShippingOnlyAndLeavesTheCustomerBookUntouched(): void
    {
        [$orderId] = $this->paidOrder(1);
        $oldAddressId = (int) $this->db->fetchOne('SELECT shipping_adress_id FROM "order" WHERE id = ?', [$orderId]);
        $this->loginAdmin();
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/adresse");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('Montréal', $crawler->filter('form[data-address-form] input[name="city"]')->attr('value'));
        $token = $crawler->filter('form[data-address-form] input[name="_token"]')->attr('value');
        $values = ['_token' => $token, 'firstname' => 'Test', 'lastname' => 'Retours', 'company' => '', 'phone' => '', 'address' => '12 rue Neuve', 'complement' => 'App. 3', 'city' => 'Québec', 'province' => 'QC', 'codepostal' => 'G1A 1A1', 'country' => ''];
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/adresse", $values);
        $this->assertStringContainsString('country : obligatoire', $this->page(), 'pays obligatoire, formulaire conservé');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/adresse", array_merge($values, ['country' => 'ca']));
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $newAddressId = (int) $this->db->fetchOne('SELECT shipping_adress_id FROM "order" WHERE id = ?', [$orderId]);
        $this->assertNotSame($oldAddressId, $newAddressId, 'nouvelle ligne d\'adresse');
        $this->assertSame(['Québec', 'CA', 'App. 3', '+1 514 555 0000'], array_values($this->db->fetchAssociative('SELECT city, country, complement, phone FROM adress WHERE id = ?', [$newAddressId])), 'téléphone repris de l\'ancienne adresse');
        $this->assertSame('Montréal', $this->db->fetchOne('SELECT city FROM adress WHERE id = ?', [$oldAddressId]), 'carnet du client intact');
        $this->assertSame('address', $this->db->fetchOne("SELECT action FROM content_audit_log WHERE resource = 'order' AND resource_id = ? ORDER BY id DESC LIMIT 1", [(string) $orderId]));
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/commandes/$orderId");
        $this->assertStringContainsString('Québec', $this->page(), 'la fiche montre la nouvelle adresse');
        $api = $this->request('GET', "/api/orders/$orderId");
        $this->assertSame('Québec', json_decode((string) $api->getContent(), true)['shippingAdress']['city']);

        // Expédiée : plus de changement (la lecture API du client a remplacé les cookies : on se reconnecte en administrateur)
        $this->db->executeStatement('UPDATE "order" SET status_id = 5, shipped_at = NOW() WHERE id = ?', [$orderId]);
        $this->loginAdmin();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/adresse");
        $this->assertStringContainsString('data-address-blocked', $this->page());
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/orders/$orderId/adresse", array_merge($values, ['country' => 'CA', 'city' => 'Laval']));
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertSame('Québec', $this->db->fetchOne('SELECT a.city FROM "order" o JOIN adress a ON a.id = o.shipping_adress_id WHERE o.id = ?', [$orderId]));
    }

    public function testAnonymousIsRejected(): void
    {
        $this->client->getCookieJar()->clear();
        foreach (['/admin/orders/1/retour', '/admin/orders/1/adresse'] as $path) {
            $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403], $path);
            $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403], $path);
        }
    }

    private function page(): string
    {
        return html_entity_decode((string) $this->client->getResponse()->getContent());
    }

    /** @return array{0: int, 1: string, 2: int, 3: int, 4: int} commande, intention, total, variante, stock avant commande */
    private function paidOrder(int $quantity): array
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = GREATEST(stock_quantity, 10) WHERE id = ?', [$variant]);
        $stock = (int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$variant]);
        $order = ['addressId' => $this->address(), 'carrierId' => 6, 'items' => [['productVariantId' => $variant, 'quantity' => $quantity]]];
        $intent = $this->request('POST', '/api/stripe/create-intent', json_encode(['items' => $order['items'], 'carrierId' => 6, 'order' => $order]));
        $this->assertSame(200, $intent->getStatusCode(), $intent->getContent());
        $pi = json_decode((string) $intent->getContent(), true)['paymentIntentId'];
        FakeCheckoutPaymentGateway::confirm($pi);
        $created = $this->request('POST', '/api/order/create', json_encode($order + ['paymentIntentId' => $pi]));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $orderId = (int) json_decode((string) $created->getContent(), true)['orderId'];

        return [$orderId, $pi, (int) round((float) $this->db->fetchOne('SELECT total_amount FROM "order" WHERE id = ?', [$orderId])), $variant, $stock];
    }

    private function address(): int
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $this->session['email']]);
        $address = (new Adress())->setFirstname('Test')->setLastname('Retours')->setFullname('Test Retours')->setAddress('1 rue du Test')->setCity('Montréal')
            ->setCodepostal('H1A 1A1')->setCountry('CA')->setPhone('+1 514 555 0000')->setProvince('QC')->setUserAdress($user);
        $em->persist($address);
        $em->flush();

        return (int) $address->getId();
    }

    private function request(string $method, string $path, ?string $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        foreach ($this->session as $name => $value) {
            if ($name !== 'email') {
                $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
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
        $email = 'retours-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Client')->setLastname('Retours')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json'], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->session = ['email' => $email];
        foreach ($this->client->getCookieJar()->all() as $cookie) {
            $this->session[$cookie->getName()] = $cookie->getValue();
        }
    }

    private function loginAdmin(): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $email = 'retours-admin-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Retours')->setRoles(['ROLE_USER', 'ROLE_USER_INTERNET', 'ROLE_ADMIN'])->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        $this->client->loginUser($user, 'main');
        \App\Tests\Functional\Support\OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
