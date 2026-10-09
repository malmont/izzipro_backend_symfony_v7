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
 * Devis du panier (POST /api/cart/quote) et son usage par la création de commande : montants en cents calculés par le
 * serveur (variante, promotion, forfait de location × durée, livraison du transporteur, taxes), règles de réservation.
 * Données : la boutique de démonstration (BoutiqueDemoSeeder) dans la base de test.
 */
class CartQuoteApiTest extends WebTestCase
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
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-devis@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
    }

    public function testSaleLinesUseVariantPriceActivePromotionCarrierAndTaxes(): void
    {
        $xl = $this->variant('DEMO-TSHIRT', 'taille-xl');   // prix de variante 3900, produit 3500
        $sweat = $this->variant('DEMO-SWEAT');             // promotion en cours 4900 (régulier 6900)
        $coffee = $this->variant('DEMO-CAFE');             // promotion expirée : 3800

        $quote = $this->quote(['items' => [
            ['productVariantId' => $xl, 'quantity' => 2], ['productVariantId' => $sweat, 'quantity' => 1], ['productVariantId' => $coffee, 'quantity' => 1],
        ], 'carrierId' => 1, 'shippingPrice' => 99999, 'shippingAddress' => ['country' => 'CA', 'province' => 'Québec', 'city' => 'Montréal', 'postalCode' => 'H2X 1Y4']]);

        $this->assertSame(200, $quote['status'], json_encode($quote['body']));
        $body = $quote['body'];
        $this->assertSame([3900, 7800, 'sale'], [$body['lines'][0]['unitPrice'], $body['lines'][0]['total'], $body['lines'][0]['kind']]);
        $this->assertSame(4900, $body['lines'][1]['unitPrice']);
        $this->assertSame(3800, $body['lines'][2]['unitPrice']);
        $this->assertSame(16500, $body['subtotal']);
        $carrier = $this->db->fetchAssociative('SELECT name, price FROM carrier WHERE id = 1');
        $shipping = (int) round((float) $carrier['price']);
        $this->assertSame($shipping, $body['shipping'], 'prix fixe du transporteur : le montant du navigateur est ignoré');
        $taxable = 16500 + $shipping;
        $this->assertSame([['label' => 'TPS', 'rate' => 0.05, 'amount' => (int) round($taxable * 0.05)], ['label' => 'TVQ', 'rate' => 0.09975, 'amount' => (int) round($taxable * 0.09975)]], array_map(fn ($t) => array_intersect_key($t, ['label' => 1, 'rate' => 1, 'amount' => 1]), $body['taxes']));
        $this->assertSame($taxable + (int) round($taxable * 0.14975), $body['total']);
        $this->assertSame([0, 'CAD', ['id' => 1, 'name' => $carrier['name'], 'isFree' => false]], [$body['deposit'], $body['currency'], $body['carrier']]);
        $this->assertSame(['calculated', 'table'], [$body['taxStatus'], $body['taxProvider']]);
        $this->assertSame(['country' => 'CA', 'province' => 'QC', 'city' => 'Montréal', 'postalCode' => 'H2X 1Y4'], $body['shippingAddress'], 'adresse normalisée (nom de province → code)');

        $free = $this->quote(['items' => [['productVariantId' => $xl, 'quantity' => 1]], 'carrierId' => 6])['body'];
        $this->assertSame([0, true], [$free['shipping'], $free['carrier']['isFree']]);
        $none = $this->quote(['items' => [['productVariantId' => $xl, 'quantity' => 1]]])['body'];
        $this->assertSame([0, null], [$none['shipping'], $none['carrier']]);
    }

    public function testRentalLinesArePricedFromThePackAndCheckedAgainstTheConfiguration(): void
    {
        $kayak = $this->variant('DEMO-KAYAK');   // heure 2500, demi-journée 6000 ; min 1 h, max 8 h ; 09:00-18:00
        $day = (new \DateTimeImmutable('+5 days'))->format('Y-m-d');

        $hours = $this->quote(['items' => [['productVariantId' => $kayak, 'quantity' => 2, 'booking' => ['start' => "{$day}T10:00:00", 'end' => "{$day}T12:30:00"]]]]);
        $this->assertSame(200, $hours['status'], json_encode($hours['body']));
        $line = $hours['body']['lines'][0];
        $this->assertSame(['rental', 7500, 15000, 3, 2500, 'hour'], [$line['kind'], $line['unitPrice'], $line['total'], $line['booking']['units'], $line['booking']['rate'], $line['booking']['durationType']], '2 h 30 → 3 heures');
        $this->assertSame('Tarif nautique', $line['booking']['rateName']);

        $halfDay = $this->quote(['items' => [['productVariantId' => $kayak, 'quantity' => 1, 'booking' => ['start' => "{$day}T09:00:00", 'end' => "{$day}T13:00:00", 'durationType' => 'halfDay']]]])['body'];
        $this->assertSame(6000, $halfDay['lines'][0]['unitPrice']);

        $tooLong = $this->quote(['items' => [['productVariantId' => $kayak, 'quantity' => 1, 'booking' => ['start' => "{$day}T09:00:00", 'end' => "{$day}T18:00:00"]]]]);
        $this->assertSame(422, $tooLong['status']);
        $this->assertContains(['path' => 'items[0].booking.end', 'message' => 'durée maximale : 8 heure(s)'], $tooLong['body']['errors']);

        $closed = $this->quote(['items' => [['productVariantId' => $kayak, 'quantity' => 1, 'booking' => ['start' => "{$day}T19:00:00", 'end' => "{$day}T20:00:00"]]]]);
        $this->assertSame(422, $closed['status']);
        $this->assertStringContainsString('heures d\'ouverture', $closed['body']['errors'][0]['message']);

        $tooMany = $this->quote(['items' => [['productVariantId' => $kayak, 'quantity' => 7, 'booking' => ['start' => "{$day}T10:00:00", 'end' => "{$day}T11:00:00"]]]]);
        $this->assertSame(422, $tooMany['status']);
        $this->assertSame(6, $tooMany['body']['errors'][0]['remaining']);

        $noDates = $this->quote(['items' => [['productVariantId' => $kayak, 'quantity' => 1]]]);
        $this->assertSame(422, $noDates['status']);
        $this->assertStringContainsString('se loue seulement', $noDates['body']['errors'][0]['message']);
    }

    public function testDailyRentalWithDepositPassengersAllowedDatesAndSaleOrRentalProduct(): void
    {
        $pontoon = $this->variant('DEMO-PONTON');  // jour 9500 ; 2 jours min ; caution 150000 ; 2500 par passager
        $start = new \DateTimeImmutable('+10 days 09:00');

        $one = $this->quote(['items' => [['productVariantId' => $pontoon, 'quantity' => 1, 'booking' => ['start' => $start->format(DATE_ATOM), 'end' => $start->modify('+1 day')->format(DATE_ATOM)]]]]);
        $this->assertSame(422, $one['status']);
        $this->assertStringContainsString('2 jour(s) minimum', json_encode($one['body']['errors'], JSON_UNESCAPED_UNICODE));

        $three = $this->quote(['items' => [['productVariantId' => $pontoon, 'quantity' => 1, 'booking' => ['start' => $start->format(DATE_ATOM), 'end' => $start->modify('+3 days')->format(DATE_ATOM), 'passengers' => 2]]], 'shippingAddress' => ['country' => 'CA', 'province' => 'QC']])['body'];
        $this->assertSame(9500 * 3 + 5000, $three['lines'][0]['unitPrice']);
        $this->assertSame(150000, $three['deposit'], 'caution rapportée à part');
        $this->assertSame((int) round(($three['subtotal']) * 1.14975), $three['total'], 'la caution n\'entre pas dans le total');

        $week = $this->quote(['items' => [['productVariantId' => $pontoon, 'quantity' => 1, 'booking' => ['start' => $start->format(DATE_ATOM), 'end' => $start->modify('+9 days')->format(DATE_ATOM), 'durationType' => 'week']]]])['body'];
        $this->assertSame([2, 45000], [$week['lines'][0]['booking']['units'], $week['lines'][0]['booking']['rate']], '9 jours → 2 semaines');

        $show = $this->variant('DEMO-SPECTACLE');
        $refused = $this->quote(['items' => [['productVariantId' => $show, 'quantity' => 1, 'booking' => ['start' => $start->format(DATE_ATOM), 'end' => $start->modify('+1 day')->format(DATE_ATOM)]]]]);
        $this->assertSame(422, $refused['status']);
        $this->assertContains(['path' => 'items[0].booking.start', 'message' => 'date non proposée (voir rental.allowedDates)'], $refused['body']['errors']);

        // Vendu ET loué : sans dates c'est un achat, avec dates une location
        $paddle = $this->variant('DEMO-PADDLE');
        $bought = $this->quote(['items' => [['productVariantId' => $paddle, 'quantity' => 1]]])['body'];
        $this->assertSame(['sale', 69900], [$bought['lines'][0]['kind'], $bought['lines'][0]['unitPrice']]);
        $rented = $this->quote(['items' => [['productVariantId' => $paddle, 'quantity' => 1, 'booking' => ['start' => $start->format(DATE_ATOM), 'end' => $start->modify('+2 days')->format(DATE_ATOM)]]]])['body'];
        $this->assertSame(['rental', 9500 * 2, 20000], [$rented['lines'][0]['kind'], $rented['lines'][0]['unitPrice'], $rented['deposit']]);
    }

    public function testTaxesFollowTheShippingAddressRegion(): void
    {
        $cap = $this->variant('DEMO-CASQUETTE');
        $items = [['productVariantId' => $cap, 'quantity' => 1]];
        $price = (int) round((float) $this->db->fetchOne("SELECT price FROM product WHERE code = 'DEMO-CASQUETTE'"));

        $none = $this->quote(['items' => $items])['body'];
        $this->assertSame([[], 'address_required', $price], [$none['taxes'], $none['taxStatus'], $none['total']], 'sans adresse : aucune taxe, total hors taxes');

        $quebec = $this->quote(['items' => $items, 'shippingAddress' => ['country' => 'CA', 'province' => 'QC']])['body'];
        $this->assertSame(['TPS', 'TVQ'], array_column($quebec['taxes'], 'label'));
        $this->assertSame($price + (int) round($price * 0.05) + (int) round($price * 0.09975), $quebec['total']);

        $ontario = $this->quote(['items' => $items, 'shippingAddress' => ['country' => 'CA', 'state' => 'Ontario']])['body'];
        $this->assertSame([['TVH', 0.13]], array_map(fn ($t) => [$t['label'], $t['rate']], $ontario['taxes']), 'TVH seule : pas de TPS en plus');

        $atlantic = $this->quote(['items' => $items, 'shippingAddress' => ['country' => 'CA', 'province' => 'NB']])['body'];
        $this->assertSame([0.15], array_column($atlantic['taxes'], 'rate'));

        $france = $this->quote(['items' => $items, 'shippingAddress' => ['country' => 'FR', 'city' => 'Lyon', 'postalCode' => '69001']])['body'];
        $this->assertSame([[], 'no_tax', $price], [$france['taxes'], $france['taxStatus'], $france['total']], 'hors Canada : la table ne connaît aucune taxe');

        // Fournisseur Stripe réglé mais compte Stripe absent en test : repli sur la table, signalé
        $this->db->executeStatement("INSERT INTO boutique_setting (configuration) VALUES ('{\"commerce\": {\"taxProvider\": \"stripe\"}}')");
        try {
            $fallback = $this->quote(['items' => $items, 'shippingAddress' => ['country' => 'CA', 'province' => 'QC']])['body'];
        } finally {
            $this->db->executeStatement('DELETE FROM boutique_setting');
        }
        $this->assertSame(['fallback_table', 'table'], [$fallback['taxStatus'], $fallback['taxProvider']]);
        $this->assertSame($quebec['total'], $fallback['total']);
    }

    public function testBadRequestsAndUnknownItems(): void
    {
        $this->assertSame(400, $this->quote(['items' => []])['status']);
        $this->assertSame(404, $this->quote(['items' => [['productVariantId' => 999999, 'quantity' => 1]]])['status']);
        $this->assertSame(404, $this->quote(['items' => [['productVariantId' => $this->variant('DEMO-CASQUETTE'), 'quantity' => 1]], 'carrierId' => 999])['status']);
        $out = $this->quote(['items' => [['productVariantId' => $this->variant('DEMO-VESTE'), 'quantity' => 1]]]);
        $this->assertSame(422, $out['status']);
        $this->assertStringContainsString('stock insuffisant', $out['body']['errors'][0]['message']);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/cart/quote', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json'], '{');
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testOrderCreationUsesTheServerQuote(): void
    {
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']); // membre du personnel : commande sans paiement Stripe
        $xl = $this->variant('DEMO-TSHIRT', 'taille-xl');
        $stockBefore = (int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$xl]);
        $addressId = $this->address();
        $shipping = (float) round((float) $this->db->fetchOne('SELECT price FROM carrier WHERE id = 1'));

        $response = $this->request('POST', '/api/order/create', json_encode([
            'addressId' => $addressId, 'carrierId' => 1, 'priceShipping' => 1, 'paymentMethod' => 1, // transporteur à prix fixe (sans compte EasyPost)
            'items' => [['productVariantId' => $xl, 'quantity' => 2]],
        ]));

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $orderId = json_decode($response->getContent())->orderId;
        $order = $this->db->fetchAssociative('SELECT sub_total, shipping_cost, total_tax, total_amount FROM "order" WHERE id = ?', [$orderId]);
        $this->assertSame([$shipping, 2 * 3900 + $shipping], [(float) $order['shipping_cost'], (float) $order['sub_total']], 'livraison : prix fixe du transporteur 1, pas le montant envoyé');
        $this->assertEqualsWithDelta((2 * 3900 + $shipping) * 1.14975, (float) $order['total_amount'], 1.0);
        $this->assertSame(3900.0, (float) $this->db->fetchOne('SELECT unit_price FROM order_items WHERE order_associated_id = ?', [$orderId]), 'prix de la variante');
        $this->assertSame($stockBefore - 2, (int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$xl]));

        $tooMany = $this->request('POST', '/api/order/create', json_encode(['addressId' => $addressId, 'items' => [['productVariantId' => $xl, 'quantity' => 500]]]));
        $this->assertSame(400, $tooMany->getStatusCode());
        $this->assertStringContainsString('stock insuffisant', $tooMany->getContent());
    }

    public function testGuestCheckoutCanBeDisabledByTheShopSettings(): void
    {
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("INSERT INTO boutique_setting (configuration) VALUES ('{\"commerce\": {\"guestCheckout\": false}}')");
        try {
            $response = $this->request('POST', '/api/order/create-guest', json_encode(['guestInfo' => ['email' => 'x@example.invalid'], 'items' => [], 'shippingAddress' => [], 'paymentIntentId' => 'pi_x']));
            $this->assertSame(403, $response->getStatusCode(), $response->getContent());
        } finally {
            $this->db->executeStatement('DELETE FROM boutique_setting');
        }
        $response = $this->request('POST', '/api/order/create-guest', json_encode(['guestInfo' => ['email' => 'x@example.invalid']]));
        $this->assertSame(400, $response->getStatusCode(), 'sans réglage : permis (champs manquants)');
    }

    /** @return array{status: int, body: array} */
    private function quote(array $body): array
    {
        $response = $this->request('POST', '/api/cart/quote', json_encode($body));

        return ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true) ?? []];
    }

    private function variant(string $code, ?string $optionCode = null): int
    {
        if ($optionCode === null) {
            return (int) $this->db->fetchOne('SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = ? ORDER BY v.id LIMIT 1', [$code]);
        }

        return (int) $this->db->fetchOne('SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id JOIN product_variant_product_option_value a ON a.product_variant_id = v.id
            JOIN product_option_value o ON o.id = a.product_option_value_id WHERE p.code = ? AND o.code = ? ORDER BY v.id LIMIT 1', [$code, $optionCode]);
    }

    private function address(): int
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $this->session['email']]);
        $address = (new Adress())->setFirstname('Test')->setLastname('Devis')->setFullname('Test Devis')->setAddress('1 rue du Test')->setCity('Montréal')
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
        $email = 'devis-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Devis')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
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
