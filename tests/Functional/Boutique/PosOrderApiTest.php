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
 * Vente en caisse (POS) : POST /api/order/create-multi-payment, route distincte du tunnel web.
 * 15/10/2026 : plus de 500 ($paymentIntent non défini), adresse et transporteur facultatifs (taxes sur l'adresse du
 * site), somme des paiements égale au total ; le parcours web (/api/order/create) garde ses contrôles.
 * Données : la boutique de démonstration (BoutiqueDemoSeeder) dans la base de test, taxes TPS + TVQ de la table.
 */
class PosOrderApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-caisse@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        // Lieu de la vente : adresse de la fiche entreprise (Québec), pour les taxes des ventes sans adresse
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register))'); // un seul poste actif
    }

    public function testCounterSaleWithoutAddressOrCarrierIsTaxedAtTheShopAndCashedIn(): void
    {
        $caisse = $this->openCaisse();
        $opening = (float) $this->db->fetchOne('SELECT amount_total FROM caisse WHERE id = ?', [$caisse]); // montant reporté de la session précédente
        $this->loginAs(['ROLE_USER_POS']);
        $variant = $this->variant('DEMO-CASQUETTE');
        $price = $this->unitPrice($variant);
        $taxes = $this->taxesOn($price);
        $total = $price + $taxes;
        $cash = 20.00;
        $card = round($total / 100 - $cash, 2);

        $response = $this->request('POST', '/api/order/create-multi-payment', json_encode([
            'items' => [['productVariantId' => $variant, 'quantity' => 1]],
            'paymentMethods' => [['type' => 2, 'amount' => $cash], ['type' => 1, 'amount' => $card]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $orderId = $body['orderId'];

        // Bloc « ticket de caisse » (16/10/2026) : champs historiques conservés, montants en dollars
        $this->assertSame([true, 'Commande créée.'], [$body['success'], $body['message']]);
        $this->assertStringStartsWith('REF#', $body['reference']);
        $this->assertNotEmpty($body['createdAt']);
        $this->assertEqualsWithDelta([$price / 100, $taxes / 100, $total / 100], [$body['subTotal'], $body['totalTax'], $body['totalAmount']], 0.001);
        $this->assertEqualsWithDelta($taxes / 100, array_sum(array_column($body['taxes'], 'amount')), 0.001, 'détail des taxes');
        $this->assertNotEmpty($body['taxes'][0]['name']);
        $this->assertSame([2, 1], array_column($body['payments'], 'type'));
        $this->assertEqualsWithDelta([$cash, $card], array_column($body['payments'], 'amount'), 0.001);
        $this->assertSame('Espèces', $body['payments'][0]['label']);
        $this->assertSame($this->db->fetchOne('SELECT cash_register_id FROM caisse WHERE id = ?', [$caisse]), $body['cashRegister']['id']);
        $this->assertNotEmpty($body['cashRegister']['name']);
        $this->assertStringContainsString('Montréal', $body['store']['address'], 'adresse du magasin (fiche entreprise)');
        $this->assertArrayHasKey('taxNumber', $body['store']);
        $this->assertNotEmpty($body['store']['name']);

        $order = $this->db->fetchAssociative('SELECT shipping_adress_id, carrier_id, shipping_cost, sub_total, total_tax, total_amount, order_source_id, order_type_id FROM "order" WHERE id = ?', [$orderId]);
        $this->assertNull($order['shipping_adress_id'], 'vente au comptoir : sans adresse');
        $this->assertNull($order['carrier_id'], 'sans transporteur');
        $this->assertSame(0.0, (float) $order['shipping_cost'], 'sans frais de port');
        $this->assertSame(2, (int) $order['order_source_id'], 'source caisse par défaut');
        $this->assertSame(1, (int) $order['order_type_id'], 'vente par défaut');
        $this->assertSame($price, (int) round($order['sub_total']));
        $this->assertSame($taxes, (int) round($order['total_tax']), 'taxes du lieu de vente (TPS + TVQ)');
        $this->assertSame($total, (int) round($order['total_amount']));
        $this->assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM payments WHERE order_payment_id = ?', [$orderId]), 'un paiement par moyen');
        $this->assertEqualsWithDelta($cash * 100, $this->db->fetchOne('SELECT amount_total FROM caisse WHERE id = ?', [$caisse]) - $opening, 0.01, 'seules les espèces entrent en caisse');
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM transaction_caisse WHERE order_caisse_id = ? AND caisse_id = ? AND transaction_type_id = 1', [$orderId, $caisse]));
    }

    public function testACounterQuoteIsTaxedAtTheStoreLikeTheSale(): void
    {
        $variant = $this->variant('DEMO-CASQUETTE');
        $price = $this->unitPrice($variant);
        $items = [['productVariantId' => $variant, 'quantity' => 1]];

        // Caisse : cashRegisterId sans adresse → adresse du magasin (fiche entreprise, Québec), même total que la vente
        $counter = json_decode($this->request('POST', '/api/cart/quote', json_encode(['items' => $items, 'cashRegisterId' => 1]))->getContent(), true);
        $this->assertSame('CA', $counter['shippingAddress']['country'] ?? null, json_encode($counter));
        $this->assertSame('QC', $counter['shippingAddress']['province']);
        $this->assertSame($price + $this->taxesOn($price), $counter['total'], 'taxes du lieu de vente');
        $this->assertSame(0, $counter['shipping']);

        // Web et application e-commerce : sans cashRegisterId ni adresse, rien ne change (aucune taxe, address_required)
        $web = json_decode($this->request('POST', '/api/cart/quote', json_encode(['items' => $items]))->getContent(), true);
        $this->assertSame('address_required', $web['taxStatus']);
        $this->assertSame($price, $web['total']);
        $this->assertNull($web['shippingAddress']);

        // Adresse fournie : elle l'emporte, même en caisse
        $ontario = json_decode($this->request('POST', '/api/cart/quote', json_encode(['items' => $items, 'cashRegisterId' => 1, 'shippingAddress' => ['country' => 'CA', 'province' => 'ON']]))->getContent(), true);
        $this->assertSame('ON', $ontario['shippingAddress']['province']);
    }

    public function testSaleWithAddressAndCarrierKeepsTodaysBehaviour(): void
    {
        $this->openCaisse();
        $this->loginAs(['ROLE_USER_POS']);
        $variant = $this->variant('DEMO-CASQUETTE');
        $address = $this->address();
        $carrier = 1; // livraison standard de la démo
        $shipping = (int) round($this->db->fetchOne('SELECT price FROM carrier WHERE id = ?', [$carrier]));
        $quote = json_decode($this->request('POST', '/api/cart/quote', json_encode([
            'items' => [['productVariantId' => $variant, 'quantity' => 1]], 'carrierId' => $carrier,
            'shippingAddress' => ['country' => 'CA', 'province' => 'QC', 'city' => 'Montréal', 'postalCode' => 'H1A 1A1'],
        ]))->getContent(), true);
        $this->assertSame($shipping, $quote['shipping']);

        $response = $this->request('POST', '/api/order/create-multi-payment', json_encode([
            'orderSource' => 2, 'typeOrder' => 1, 'addressId' => $address, 'carrierId' => $carrier,
            'items' => [['productVariantId' => $variant, 'quantity' => 1]],
            'paymentMethods' => [['type' => 2, 'amount' => $quote['total'] / 100]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $order = $this->db->fetchAssociative('SELECT shipping_adress_id, carrier_id, shipping_cost, total_amount FROM "order" WHERE id = ?', [json_decode($response->getContent(), true)['orderId']]);
        $this->assertSame($address, (int) $order['shipping_adress_id']);
        $this->assertSame($carrier, (int) $order['carrier_id']);
        $this->assertSame($shipping, (int) round($order['shipping_cost']), 'frais de port du transporteur');
        $this->assertSame($quote['total'], (int) round($order['total_amount']), 'même total que le devis');
    }

    public function testPaymentsMustCoverTheServerTotalAndBodyIsValidated(): void
    {
        $this->openCaisse();
        $this->loginAs(['ROLE_USER_POS']);
        $variant = $this->variant('DEMO-CASQUETTE');
        $items = [['productVariantId' => $variant, 'quantity' => 1]];

        $short = $this->request('POST', '/api/order/create-multi-payment', json_encode(['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 1.00]]]));
        $this->assertSame(400, $short->getStatusCode());
        $this->assertStringContainsString('ne correspond pas au total', json_decode($short->getContent(), true)['error']);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order" WHERE total_amount > 0 AND status_id <> 7 AND user_id_id = (SELECT id FROM "user" WHERE email = ?)', [$this->email]), 'aucune commande créée');

        $this->assertSame(400, $this->request('POST', '/api/order/create-multi-payment', '{')->getStatusCode());
        $noItems = $this->request('POST', '/api/order/create-multi-payment', json_encode(['items' => [], 'paymentMethods' => [['type' => 2, 'amount' => 1]]]));
        $this->assertSame(['ITEMS_REQUIRED'], [json_decode($noItems->getContent(), true)['error']]);
        $noPayment = $this->request('POST', '/api/order/create-multi-payment', json_encode(['items' => $items]));
        $this->assertSame('PAYMENT_METHODS_REQUIRED', json_decode($noPayment->getContent(), true)['error']);
        $badAddress = $this->request('POST', '/api/order/create-multi-payment', json_encode(['items' => $items, 'addressId' => 999999, 'paymentMethods' => [['type' => 2, 'amount' => 1]]]));
        $this->assertSame(404, $badAddress->getStatusCode());
        $this->assertSame('ADDRESS_NOT_FOUND', json_decode($badAddress->getContent(), true)['error']);
        $badCarrier = $this->request('POST', '/api/order/create-multi-payment', json_encode(['items' => $items, 'carrierId' => 999999, 'paymentMethods' => [['type' => 2, 'amount' => 1]]]));
        $this->assertSame('CARRIER_NOT_FOUND', json_decode($badCarrier->getContent(), true)['error']);
    }

    public function testOnlineCustomersAreStillRefusedOnThePosRoute(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET'], 'mobile');
        $response = $this->request('POST', '/api/order/create-multi-payment', json_encode(['items' => [['productVariantId' => $this->variant('DEMO-CASQUETTE'), 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 1]]]));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testWebOrderStillRequiresAnAddress(): void
    {
        // Parcours web (/api/order/create) inchangé : le personnel doit fournir addressId, le client un paiement en ligne
        $this->loginAs(['ROLE_USER_POS']);
        $items = [['productVariantId' => $this->variant('DEMO-CASQUETTE'), 'quantity' => 1]];
        $staff = $this->request('POST', '/api/order/create', json_encode(['items' => $items, 'orderSource' => 2, 'typeOrder' => 1, 'paymentMethod' => 2]));
        $this->assertSame(400, $staff->getStatusCode(), $staff->getContent());
        $this->assertStringContainsString('addressId', json_decode($staff->getContent(), true)['error']);

        $this->loginAs(['ROLE_USER_INTERNET'], 'mobile');
        $customer = $this->request('POST', '/api/order/create', json_encode(['items' => $items]));
        $this->assertSame(402, $customer->getStatusCode(), $customer->getContent());
    }

    private string $email = '';

    /** Ouvre une caisse (ROLE_ADMIN) et rend son identifiant */
    private function openCaisse(): int
    {
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $response = $this->request('POST', '/api/caisse/open', '{}');
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        return (int) json_decode($response->getContent(), true)['caisse_id'];
    }

    private function variant(string $code): int
    {
        return (int) $this->db->fetchOne('SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = ? ORDER BY v.id LIMIT 1', [$code]);
    }

    private function unitPrice(int $variant): int
    {
        return (int) round($this->db->fetchOne('SELECT COALESCE(v.price, p.price) FROM product_variant v JOIN product p ON p.id = v.product_id WHERE v.id = ?', [$variant]));
    }

    /** Taxes de la table du site applicables au Québec (TPS fédérale + TVQ), en cents */
    private function taxesOn(int $amount): int
    {
        $total = 0;
        foreach ($this->db->fetchAllAssociative("SELECT rate FROM tax WHERE (country IS NULL OR country = '' OR upper(country) = 'CA') AND (province IS NULL OR province = '' OR province ILIKE 'toutes' OR province ILIKE '%qu_bec%' OR province ILIKE '%QC%')") as $tax) {
            $total += (int) round($amount * (float) $tax['rate']);
        }

        return $total;
    }

    private function address(): int
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'client-caisse@example.invalid']);
        $address = (new Adress())->setFirstname('Client')->setLastname('Comptoir')->setFullname('Client Comptoir')->setAddress('1 rue du Test')->setCity('Montréal')
            ->setCodepostal('H1A 1A1')->setCountry('CA')->setPhone('+1 514 555 0000')->setProvince('QC')->setUserAdress($user);
        $em->persist($address);
        $em->flush();

        return (int) $address->getId();
    }

    private function request(string $method, string $path, ?string $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
        ]), $body);

        return $this->client->getResponse();
    }

    /** Connexion native (jetons dans le corps, sans cookie) : platform « pos » pour le personnel, « mobile » pour un client */
    private function loginAs(array $roles, string $platform = 'pos'): void
    {
        $this->email = 'caisse-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($this->email)->setUsername($this->email)->setFirstname('Caisse')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        $this->token = null;
        $login = $this->request('POST', '/api/login', json_encode(['username' => $this->email, 'password' => self::PASSWORD, 'platform' => $platform]));
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
        $this->assertNotEmpty($this->token);
    }
}
