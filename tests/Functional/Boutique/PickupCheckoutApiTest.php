<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Retrait en magasin sans adresse (B20 bis, 08/11/2026) : avec un transporteur `pickup`, l'invité commande avec ses seules coordonnées
 * (prénom, nom, courriel, téléphone) et le client connecté sans `addressId` ; l'intent est chiffré taxes de la boutique comprises ;
 * la commande n'a pas d'adresse de livraison, porte `carrier.isPickup` ; sans transporteur de retrait, l'adresse reste exigée.
 */
class PickupCheckoutApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private array $users = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeCheckoutPaymentGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC'");
        static::getContainer()->get(\App\Services\BoutiqueDemoService\BoutiqueDemoSeeder::class)->seed('client-retrait@example.invalid', false, false); // produits de démonstration
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
    }

    public function testAGuestAndACustomerCanOrderAPickupWithoutAnyAddress(): void
    {
        $variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $pickup = (int) $this->db->fetchOne("SELECT id FROM carrier WHERE kind = 'pickup' ORDER BY id LIMIT 1");
        $fixed = (int) $this->db->fetchOne("SELECT id FROM carrier WHERE kind = 'fixed' ORDER BY id LIMIT 1");
        $items = [['productVariantId' => $variant, 'quantity' => 1]];

        // Invité : intent sans adresse, taxes de la boutique comprises
        $intent = $this->request('POST', '/api/stripe/create-intent', json_encode(['items' => $items, 'carrierId' => $pickup]));
        $this->assertSame(200, $intent->getStatusCode(), $intent->getContent());
        $quote = json_decode((string) $intent->getContent(), true);
        $this->assertSame(['store', 'calculated'], [$quote['quote']['taxBasis'], $quote['quote']['taxStatus']]);
        $taxes = (int) array_sum(array_column($quote['quote']['taxes'], 'amount'));
        $this->assertGreaterThan(0, $taxes);
        $this->assertSame($quote['quote']['total'], $quote['calculatedAmount'], 'montant de l\'intent = total taxes comprises');
        FakeCheckoutPaymentGateway::confirm($quote['paymentIntentId']);

        $email = 'retrait-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $this->users[] = $email;
        $noName = $this->request('POST', '/api/order/create-guest', json_encode(['guestInfo' => ['email' => $email], 'items' => $items, 'carrierId' => $pickup, 'paymentIntentId' => $quote['paymentIntentId']]));
        $this->assertSame(400, $noName->getStatusCode());
        $this->assertSame('GUEST_NAME_REQUIRED', json_decode((string) $noName->getContent(), true)['code'], 'retrait sans adresse : prénom et nom obligatoires');

        $created = $this->request('POST', '/api/order/create-guest', json_encode(['guestInfo' => ['email' => $email, 'firstName' => 'Paul', 'lastName' => 'Retrait', 'phone' => '5145550199'], 'items' => $items, 'carrierId' => $pickup, 'paymentIntentId' => $quote['paymentIntentId']]));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $order = json_decode((string) $created->getContent(), true);
        $row = $this->db->fetchAssociative('SELECT shipping_adress_id, carrier_id, total_tax, shipping_cost FROM "order" WHERE id = ?', [$order['orderId']]);
        $this->assertSame([null, $pickup, $taxes, 0], [$row['shipping_adress_id'], (int) $row['carrier_id'], (int) round((float) $row['total_tax']), (int) round((float) $row['shipping_cost'])], 'commande sans adresse, taxes de la boutique, aucuns frais');
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM adress WHERE user_adress_id = (SELECT id FROM "user" WHERE email = ?)', [$email]), 'aucune adresse créée pour l\'invité');
        $detail = json_decode((string) $this->request('GET', '/api/orders/' . $order['orderId'] . '?token=' . $order['guestToken'])->getContent(), true);
        $this->assertSame([null, 'pickup', true], [$detail['shippingAdress'], $detail['carrier']['kind'], $detail['carrier']['isPickup']]);

        // Sans transporteur de retrait, l'adresse reste exigée
        $intent2 = json_decode((string) $this->request('POST', '/api/stripe/create-intent', json_encode(['items' => $items, 'carrierId' => $fixed, 'shippingAddress' => ['country' => 'CA', 'province' => 'QC']]))->getContent(), true);
        FakeCheckoutPaymentGateway::confirm($intent2['paymentIntentId']);
        $refused = $this->request('POST', '/api/order/create-guest', json_encode(['guestInfo' => ['email' => $email, 'firstName' => 'Paul', 'lastName' => 'Retrait'], 'items' => $items, 'carrierId' => $fixed, 'paymentIntentId' => $intent2['paymentIntentId']]));
        $this->assertSame(['GUEST_FIELDS_REQUIRED', 400], [json_decode((string) $refused->getContent(), true)['code'], $refused->getStatusCode()]);

        // Client connecté : sans addressId avec un retrait, commande créée ; avec livraison, refusée
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $customerEmail = 'client-retrait-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $this->users[] = $customerEmail;
        $customer = (new User())->setEmail($customerEmail)->setUsername($customerEmail)->setFirstname('Anne')->setLastname('Comptoir')->setRoles(['ROLE_USER', 'ROLE_USER_INTERNET'])->setIsVerified(true)->setPassword('x');
        $em->persist($customer);
        $em->flush();
        $bearer = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($customer);
        $intent3 = json_decode((string) $this->request('POST', '/api/stripe/create-intent', json_encode(['items' => $items, 'carrierId' => $pickup]), $bearer)->getContent(), true);
        $this->assertSame($quote['calculatedAmount'], $intent3['calculatedAmount']);
        FakeCheckoutPaymentGateway::confirm($intent3['paymentIntentId']);
        $created = $this->request('POST', '/api/order/create', json_encode(['items' => $items, 'carrierId' => $pickup, 'paymentIntentId' => $intent3['paymentIntentId']]), $bearer);
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $id = (int) json_decode((string) $created->getContent(), true)['orderId'];
        $detail = json_decode((string) $this->request('GET', '/api/orders/' . $id, null, $bearer)->getContent(), true);
        $this->assertSame([null, true, 'paid'], [$detail['shippingAdress'], $detail['carrier']['isPickup'], $detail['paymentStatus']]);

        $intent4 = json_decode((string) $this->request('POST', '/api/stripe/create-intent', json_encode(['items' => $items, 'carrierId' => $fixed, 'shippingAddress' => ['country' => 'CA', 'province' => 'QC']]), $bearer)->getContent(), true);
        FakeCheckoutPaymentGateway::confirm($intent4['paymentIntentId']);
        $refused = $this->request('POST', '/api/order/create', json_encode(['items' => $items, 'carrierId' => $fixed, 'paymentIntentId' => $intent4['paymentIntentId']]), $bearer);
        $this->assertSame(['FIELDS_REQUIRED', 400], [json_decode((string) $refused->getContent(), true)['code'], $refused->getStatusCode()]);
    }

    private function request(string $method, string $path, ?string $body = null, ?string $bearer = null): Response
    {
        $this->client->getCookieJar()->clear();
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test'];
        if ($bearer !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server, $body);

        return $this->client->getResponse();
    }
}
