<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Carrier;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use App\Tests\Fake\FakeEasyPostGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot CHECKOUT-LIVRAISON (21/11/2026, `docs/boutique.md`, « Livraison ») : le serveur ne croit jamais un prix de livraison EasyPost venu
 * du navigateur. Chaque tarif de `shipping/summary` porte un jeton (rateToken) gardé par le serveur avec ce qu'il couvre ; le devis
 * web exige ce jeton et prend son montant ; create-intent écrit le prix coté dans le panier gardé (la commande et le webhook s'en
 * servent, même après l'expiration du jeton). EasyPost muet : 503, jamais 0. La caisse garde le prix saisi.
 */
class CheckoutLivraisonTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private string $tag;
    private int $carrier = 0;
    private int $variant = 0;
    private int $product = 0;
    private int $ordersBefore = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tag = bin2hex(random_bytes(3));
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-paiement@example.invalid', false, false);
        $em = $provider->getEntityManager();
        $this->db = $em->getConnection();
        $this->db->executeStatement('DELETE FROM boutique_setting');
        FakeCheckoutPaymentGateway::reset();
        FakeEasyPostGateway::reset();
        $this->ordersBefore = (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM "order"');
        $carrier = (new Carrier())->setName('EasyPost ' . $this->tag)->setDescription('essai')->setPrice(0)->setCreatedAt(new \DateTime())
            ->setCarrierAccountId('ca_plateforme_postes')->setKind(Carrier::KIND_EASYPOST);
        $em->persist($carrier);
        $em->flush();
        $this->carrier = (int) $carrier->getId();
        $row = $this->db->fetchAssociative("SELECT v.id AS v, p.id AS p FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        [$this->variant, $this->product] = [(int) $row['v'], (int) $row['p']];
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = GREATEST(stock_quantity, 20) WHERE id = ?', [$this->variant]);
        $em->clear();
    }

    protected function tearDown(): void
    {
        foreach (array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM "order" WHERE id > ?', [$this->ordersBefore])) as $order) {
            $this->db->executeStatement('UPDATE "order" SET carrier_id = NULL WHERE id = ?', [$order]);
        }
        $this->db->executeStatement('UPDATE "order" SET carrier_id = NULL WHERE carrier_id = ?', [$this->carrier]);
        $this->db->executeStatement('DELETE FROM carrier WHERE id = ?', [$this->carrier]);
        foreach (array_keys(FakeCheckoutPaymentGateway::$intents) as $intent) {
            $this->db->executeStatement('DELETE FROM checkout_session WHERE payment_intent_id = ? AND order_id IS NULL', [$intent]);
        }
        parent::tearDown();
    }

    public function testTheWebQuoteTrustsOnlyTheServerKeptRate(): void
    {
        $token = $this->rateToken('ExpeditedParcel');
        $quote = $this->send('POST', '/api/cart/quote', $this->cart() + ['shippingRateToken' => $token, 'shippingPrice' => 0]);
        $this->assertSame(200, $quote->getStatusCode(), (string) $quote->getContent());
        $this->assertSame(1250, json_decode((string) $quote->getContent(), true)['shipping'], 'le tarif du jeton, pas le prix envoyé (0)');
        // sans jeton, même avec un prix : refusé, jamais 0 ni le prix envoyé
        $this->assertSame([422, 'SHIPPING_RATE_REQUIRED'], $this->code($this->send('POST', '/api/cart/quote', $this->cart() + ['shippingPrice' => 0])));
        $this->assertSame([422, 'SHIPPING_RATE_EXPIRED'], $this->code($this->send('POST', '/api/cart/quote', $this->cart() + ['shippingRateToken' => str_repeat('a', 32)])));
        // le jeton d'un autre panier, d'une autre destination ou d'un autre transporteur
        $this->assertSame([422, 'SHIPPING_RATE_MISMATCH'], $this->code($this->send('POST', '/api/cart/quote', $this->cart(2) + ['shippingRateToken' => $token])), 'autre quantité');
        $this->assertSame([422, 'SHIPPING_RATE_MISMATCH'], $this->code($this->send('POST', '/api/cart/quote', ['shippingAddress' => ['country' => 'CA', 'province' => 'ON', 'postalCode' => 'K1A 0B1']] + $this->cart() + ['shippingRateToken' => $token])), 'autre province');
        $this->assertSame([422, 'SHIPPING_RATE_MISMATCH'], $this->code($this->send('POST', '/api/cart/quote', ['shippingAddress' => ['country' => 'CA', 'province' => 'QC', 'postalCode' => 'H2X 1Y4']] + $this->cart() + ['shippingRateToken' => $token])), 'autre code postal, même province');
        $usps = $this->rateToken('Priority');
        $this->assertSame([422, 'SHIPPING_RATE_MISMATCH'], $this->code($this->send('POST', '/api/cart/quote', $this->cart() + ['shippingRateToken' => $usps])), 'autre compte EasyPost');
    }

    public function testTheTokenOfASiteIsUnknownOnAnotherSite(): void
    {
        $token = $this->rateToken('ExpeditedParcel');
        $store = static::getContainer()->get(\App\Services\ShippingService\ShippingRateStore::class);
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE);
        try {
            $this->assertNull($store->get($token), 'un autre site ne connaît pas ce jeton');
        } finally {
            $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        }
        $this->assertNotNull($store->get($token), 'son site, lui, le connaît');
    }

    public function testCreateIntentKeepsTheQuotedPriceAndTheOrderUsesItEvenAfterTheTokenExpired(): void
    {
        $token = $this->rateToken('Xpresspost');
        $intent = $this->send('POST', '/api/stripe/create-intent', $this->cart() + ['shippingRateToken' => $token, 'shippingPrice' => 0, 'service' => 'ExpeditedParcel']);
        $this->assertSame(200, $intent->getStatusCode(), (string) $intent->getContent());
        $pi = (string) json_decode((string) $intent->getContent(), true)['paymentIntentId'];
        $this->assertSame(1990, json_decode((string) $this->db->fetchOne('SELECT cart FROM checkout_session WHERE payment_intent_id = ?', [$pi]), true)['shippingPrice'], 'le prix coté par le serveur est gardé');
        FakeCheckoutPaymentGateway::confirm($pi);
        static::getContainer()->get(\App\Services\ShippingService\ShippingRateStore::class)->forget($token); // le jeton a expiré après le paiement
        $kept = json_decode((string) $this->db->fetchOne('SELECT cart FROM checkout_session WHERE payment_intent_id = ?', [$pi]), true);
        $this->assertSame(['Xpresspost', 'ca_plateforme_postes'], [$kept['service'], $kept['carrierAccountId']], 'service et compte PAYÉS : ceux du jeton, jamais ceux du client');
        $created = $this->send('POST', '/api/order/create-guest', $this->guest() + ['paymentIntentId' => $pi, 'clientSecret' => $pi . '_secret_test', 'shippingPrice' => 0]);
        $this->assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $order = (int) json_decode((string) $created->getContent(), true)['orderId'];
        $this->assertSame(1990, (int) round((float) $this->db->fetchOne('SELECT shipping_cost FROM "order" WHERE id = ?', [$order])), 'la commande garde le tarif payé');
    }

    /** Relecture : une destination incomplète n'a pas de jeton ; une adresse refusée par EasyPost donne 422, pas « réessayez » ; la devise compte */
    public function testIncompleteDestinationRefusedAddressAndCurrency(): void
    {
        $body = $this->summaryBody();
        $body['to']['postal_code'] = ''; // code postal vide (la clé est exigée par l'adresse EasyPost)
        $summary = $this->send('POST', '/api/shipping/summary', $body);
        $this->assertSame(200, $summary->getStatusCode(), (string) $summary->getContent());
        foreach (json_decode((string) $summary->getContent(), true) as $option) {
            $this->assertNull($option['rateToken'], 'sans code postal : aucun jeton, donc aucun paiement à ce tarif');
        }
        FakeEasyPostGateway::$ratesRefused = true;
        $this->assertSame([422, 'SHIPPING_ADDRESS_INVALID'], $this->code($this->send('POST', '/api/shipping/summary', $this->summaryBody())));
        FakeEasyPostGateway::$ratesRefused = false;
        // un tarif dans une autre devise que celle du site (USPS en USD sur un site en CAD) : refusé
        $usps = $this->rateToken('Priority');
        $this->db->executeStatement('UPDATE carrier SET carrier_account_id = ? WHERE id = ?', ['ca_plateforme_usps', $this->carrier]);
        $this->flush();
        $this->assertSame([422, 'SHIPPING_RATE_MISMATCH'], $this->code($this->send('POST', '/api/cart/quote', $this->cart() + ['shippingRateToken' => $usps])), 'autre devise');
    }

    /** Le webhook crée la commande au prix coté et gardé, sans jeton ni prix du client */
    public function testTheWebhookUsesTheKeptQuotedPrice(): void
    {
        $token = $this->rateToken('ExpeditedParcel');
        $intent = $this->send('POST', '/api/stripe/create-intent', $this->cart() + ['shippingRateToken' => $token, 'order' => $this->guest() + ['shippingPrice' => 0]]);
        $this->assertSame(200, $intent->getStatusCode(), (string) $intent->getContent());
        $pi = (string) json_decode((string) $intent->getContent(), true)['paymentIntentId'];
        FakeCheckoutPaymentGateway::confirm($pi);
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/stripe/webhook', [], [], ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['id' => 'evt_' . bin2hex(random_bytes(4)), 'type' => 'payment_intent.amount_capturable_updated', 'data' => ['object' => ['id' => $pi, 'object' => 'payment_intent', 'metadata' => ['tenant_code' => MV_TEST_TENANT_CODE]]]]));
        $this->assertSame('processed', json_decode((string) $this->client->getResponse()->getContent(), true)['status'] ?? null, (string) $this->client->getResponse()->getContent());
        $order = (int) $this->db->fetchOne('SELECT order_id FROM checkout_session WHERE payment_intent_id = ?', [$pi]);
        $this->assertSame(1250, (int) round((float) $this->db->fetchOne('SELECT shipping_cost FROM "order" WHERE id = ?', [$order])));
    }

    public function testEasyPostSilentGives503AndNeverAZeroPrice(): void
    {
        FakeEasyPostGateway::$ratesDown = true;
        $summary = $this->send('POST', '/api/shipping/summary', $this->summaryBody());
        $this->assertSame([503, 'SHIPPING_RATES_UNAVAILABLE'], $this->code($summary));
        $this->assertSame([422, 'SHIPPING_RATE_REQUIRED'], $this->code($this->send('POST', '/api/cart/quote', $this->cart() + ['shippingPrice' => 0])), 'sans tarif : pas de commande à 0');
    }

    public function testTheCashRegisterStillTypesItsShippingPrice(): void
    {
        $email = "livraison-{$this->tag}@example.invalid";
        $user = (new \App\Entity\User())->setEmail($email)->setUsername($email)->setFirstname('Caisse')->setLastname('Essai')->setRoles(['ROLE_USER_POS'])->setPassword('inutilisable')->setIsVerified(true);
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $em->persist($user);
        $em->flush();
        $token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        try {
            $quote = $this->send('POST', '/api/cart/quote', $this->cart() + ['shippingPrice' => 777, 'cashRegisterId' => 1], $token);
            $this->assertSame(200, $quote->getStatusCode(), (string) $quote->getContent());
            $this->assertSame(777, json_decode((string) $quote->getContent(), true)['shipping'], 'le caissier saisit le prix, comme avant');
        } finally {
            $this->db->executeStatement('DELETE FROM "user" WHERE id = ?', [$user->getId()]);
        }
    }

    private function rateToken(string $service): string
    {
        $summary = $this->send('POST', '/api/shipping/summary', $this->summaryBody());
        $this->assertSame(200, $summary->getStatusCode(), (string) $summary->getContent());
        foreach (json_decode((string) $summary->getContent(), true) as $option) {
            if ($option['service'] === $service) {
                $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $option['rateToken']);

                return $option['rateToken'];
            }
        }
        $this->fail("pas de tarif $service : " . $summary->getContent());
    }

    private function summaryBody(): array
    {
        return ['cartItems' => [['productId' => $this->product, 'quantity' => 1]], 'to' => ['name' => 'Invité Livraison', 'country' => 'CA', 'province' => 'QC', 'postal_code' => 'G1R 4P5', 'city' => 'Québec', 'street1' => '1 rue du Test'],
            'carriers' => ['ca_plateforme_postes', 'ca_plateforme_usps']];
    }

    private function cart(int $quantity = 1): array
    {
        return ['items' => [['productVariantId' => $this->variant, 'quantity' => $quantity]], 'carrierId' => $this->carrier, 'shippingAddress' => ['country' => 'CA', 'province' => 'QC', 'postalCode' => 'G1R 4P5']];
    }

    private function guest(): array
    {
        return ['guestInfo' => ['email' => "livraison-{$this->tag}-invite@example.invalid", 'firstName' => 'Invité', 'lastName' => 'Livraison', 'phone' => '5145550100'],
            'items' => [['productVariantId' => $this->variant, 'quantity' => 1]], 'carrierId' => $this->carrier,
            'shippingAddress' => ['firstname' => 'Invité', 'lastname' => 'Livraison', 'address' => '1 rue du Test', 'city' => 'Québec', 'province' => 'QC', 'postalCode' => 'G1R 4P5', 'country' => 'CA']];
    }

    private function flush(): void
    {
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateSite(MV_TEST_TENANT_CODE, MV_TEST_TENANT_DB);
    }

    /** @return array{0: int, 1: ?string} statut et code (de premier niveau ou de la première erreur) */
    private function code(Response $response): array
    {
        $body = json_decode((string) $response->getContent(), true) ?? [];

        return [$response->getStatusCode(), $body['errors'][0]['code'] ?? $body['code'] ?? null];
    }

    private function send(string $method, string $path, array $body, ?string $token = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter(['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.91.' . random_int(1, 254) . '.' . random_int(1, 254), 'HTTP_AUTHORIZATION' => $token !== null ? 'Bearer ' . $token : null]), (string) json_encode($body));

        return $this->client->getResponse();
    }
}
