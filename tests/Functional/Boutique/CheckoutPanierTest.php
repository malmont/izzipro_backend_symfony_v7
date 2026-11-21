<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot CHECKOUT-PANIER (21/11/2026, `docs/boutique.md`, « Paiement d'un panier ») : une commande web se crée TOUJOURS depuis le
 * panier coté et payé (gardé avec l'intention). Avant le lot, seul le MONTANT liait la commande au paiement : un client payait un
 * article et commandait un autre article au même total. Le corps n'est plus qu'un contrôle :
 * - create-intent : un corps de commande (`order`) différent du panier coté est refusé (409 CART_MISMATCH), AVANT tout paiement ;
 * - navigateur (create-guest, create) : un corps différent du panier payé est refusé (409 CART_MISMATCH), l'autorisation reste ;
 * - webhook : jamais de refus (l'argent est pris) ; la commande du panier payé, et un incident si le corps gardé différait.
 */
class CheckoutPanierTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private string $tag;
    /** @var array<string, int> article => variante (même prix pour tous : seul le panier les distingue) */
    private array $variants = [];
    /** @var list<int> */
    private array $products = [];
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
        $this->db->executeStatement('DELETE FROM boutique_setting'); // réglages par défaut : commande d'invité permise (comme CheckoutApiTest)
        FakeCheckoutPaymentGateway::reset();
        $this->ordersBefore = (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM "order"');
        foreach (['paid' => [true, false], 'other' => [true, false], 'offline' => [false, false], 'counter' => [false, true]] as $key => [$web, $pos]) {
            $product = (new Product())->setName("Panier $key {$this->tag}")->setSlug("panier-$key-{$this->tag}")->setPrice(1990)->setIsWeb($web)->setIsPos($pos)->setSaleEnabled(true);
            $product->addVariant($variant = (new ProductVariant())->setStockQuantity(20)->setPrice(1990));
            if ($key === 'paid') {
                $product->addVariant($second = (new ProductVariant())->setStockQuantity(20)->setPrice(1990)); // une autre variante, même prix
            }
            $em->persist($product);
            $em->flush();
            $this->products[] = (int) $product->getId();
            $this->variants[$key] = (int) $variant->getId();
            if ($key === 'paid') {
                $this->variants['paid-variant'] = (int) $second->getId();
            }
        }
        $em->clear();
    }

    protected function tearDown(): void
    {
        $this->purge('order', array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM "order" WHERE id > ?', [$this->ordersBefore])));
        // les sessions de CET essai seulement : les intentions simulées qu'il a créées
        foreach (array_keys(FakeCheckoutPaymentGateway::$intents) as $intent) {
            $this->db->executeStatement('DELETE FROM checkout_session WHERE payment_intent_id = ? AND order_id IS NULL', [$intent]);
        }
        $this->purge('product', $this->products);
        $this->purge('user', array_map('intval', $this->db->fetchFirstColumn("SELECT id FROM \"user\" WHERE email LIKE ?", ['panier-' . $this->tag . '%'])));
        $this->master()->prepare("DELETE FROM platform_incident_event WHERE incident_id IN (SELECT id FROM platform_incident WHERE route = 'checkout/panier' AND tenant_code = ?)")->execute([MV_TEST_TENANT_CODE]);
        $this->master()->prepare("DELETE FROM platform_incident WHERE route = 'checkout/panier' AND tenant_code = ?")->execute([MV_TEST_TENANT_CODE]);
        parent::tearDown();
    }

    public function testTheHonestCartPassesAndEveryOtherBodyIsRefusedWithoutCreatingAnOrder(): void
    {
        $pi = $this->paidIntent('paid');
        $paid = $this->guestBody('paid', 'a');
        $swaps = [
            'autre article au même total' => $this->guestBody('other', 'a'),
            'autre variante du même article' => $this->guestBody('paid-variant', 'a'),
            'article hors ligne' => $this->guestBody('offline', 'a'),
            'article de caisse seule' => $this->guestBody('counter', 'a'),
            'quantité changée' => ['items' => [['productVariantId' => $this->variants['paid'], 'quantity' => 2]]] + $paid,
            'article ajouté' => ['items' => [...$paid['items'], ['productVariantId' => $this->variants['other'], 'quantity' => 1]]] + $paid,
            'autre transporteur' => ['carrierId' => 7] + $paid,
            'autre province' => ['shippingAddress' => ['province' => 'ON', 'postalCode' => 'K1A 0B1'] + $paid['shippingAddress']] + $paid,
        ];
        foreach ($swaps as $label => $body) {
            $refused = $this->send('POST', '/api/order/create-guest', $body + ['paymentIntentId' => $pi]);
            $this->assertSame([409, 'CART_MISMATCH'], [$refused->getStatusCode(), json_decode((string) $refused->getContent(), true)['code'] ?? null], "$label : " . $refused->getContent());
            $this->assertSame(0, $this->orders(), "$label : aucune commande");
        }
        $this->assertSame('requires_capture', FakeCheckoutPaymentGateway::$intents[$pi]['status'], 'l\'autorisation reste entière après les refus');
        // le panier honnête passe, dans un autre ordre et avec des champs en plus (productId) : la commande du panier payé
        $honest = ['items' => [['productId' => 1, 'quantity' => 1, 'productVariantId' => $this->variants['paid']]]] + $paid;
        $created = $this->send('POST', '/api/order/create-guest', $honest + ['paymentIntentId' => $pi]);
        $this->assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $order = (int) json_decode((string) $created->getContent(), true)['orderId'];
        $this->assertSame([$this->variants['paid'] => 1], $this->lines($order), 'les lignes du panier payé');
        // l'intention est consommée : un autre corps ne crée pas de seconde commande
        $again = $this->send('POST', '/api/order/create-guest', $this->guestBody('other', 'a') + ['paymentIntentId' => $pi]);
        $this->assertSame(200, $again->getStatusCode(), (string) $again->getContent());
        $this->assertSame([$order, true], [json_decode((string) $again->getContent(), true)['orderId'], json_decode((string) $again->getContent(), true)['alreadyCreated']]);
        $this->assertSame(1, $this->orders(), 'une seule commande pour ce paiement');
    }

    public function testCreateIntentRefusesAnOrderBodyThatIsNotTheQuotedCart(): void
    {
        $sessions = fn (): int => (int) $this->db->fetchOne('SELECT COUNT(*) FROM checkout_session');
        $before = $sessions();
        $paid = $this->guestBody('paid', 'b');
        foreach (['autre article' => $this->guestBody('other', 'b'), 'quantité' => ['items' => [['productVariantId' => $this->variants['paid'], 'quantity' => 3]]] + $paid,
            'transporteur' => ['carrierId' => 7] + $paid] as $label => $order) {
            $refused = $this->send('POST', '/api/stripe/create-intent', ['items' => $paid['items'], 'carrierId' => 6, 'shippingAddress' => ['province' => 'QC'], 'order' => $order]);
            $this->assertSame([409, 'CART_MISMATCH'], [$refused->getStatusCode(), json_decode((string) $refused->getContent(), true)['code'] ?? null], "$label : " . $refused->getContent());
        }
        $this->assertSame($before, $sessions(), 'aucune intention ouverte');
        $this->assertSame([], FakeCheckoutPaymentGateway::$intents, 'aucun paiement demandé à Stripe');
        // le corps honnête (les mêmes lignes, plus productId) : l'intention s'ouvre, et le corps est gardé sans ses clés de panier
        $ok = $this->send('POST', '/api/stripe/create-intent', ['items' => $paid['items'], 'carrierId' => 6, 'shippingAddress' => ['province' => 'QC'],
            'order' => ['items' => [['productId' => 1] + $paid['items'][0]]] + $paid]);
        $this->assertSame(200, $ok->getStatusCode(), (string) $ok->getContent());
        $kept = json_decode((string) $this->db->fetchOne('SELECT order_data FROM checkout_session WHERE payment_intent_id = ?', [json_decode((string) $ok->getContent(), true)['paymentIntentId']]), true);
        $this->assertArrayNotHasKey('items', $kept, 'le corps gardé ne porte plus d\'articles : la commande les prendra du panier');
        $this->assertSame($paid['guestInfo']['email'], $kept['guestInfo']['email']);
    }

    public function testTheWebhookNeverRefusesAndCreatesThePaidCartWithAnIncidentWhenTheKeptBodyDiffered(): void
    {
        $paid = $this->guestBody('paid', 'c');
        $pi = $this->paidIntent('paid', $paid);
        // un corps gardé qui diffère du panier (session d'avant le lot, ou écart malgré tout) : un autre article
        $this->db->executeStatement('UPDATE checkout_session SET order_data = ? WHERE payment_intent_id = ?', [json_encode($this->guestBody('offline', 'c')), $pi]);
        $webhook = $this->webhook($pi);
        $this->assertSame('processed', $webhook['status'], json_encode($webhook));
        $order = (int) $this->db->fetchOne('SELECT order_id FROM checkout_session WHERE payment_intent_id = ?', [$pi]);
        $this->assertSame([$this->variants['paid'] => 1], $this->lines($order), 'la commande du panier payé, pas celle du corps');
        $this->assertSame('succeeded', FakeCheckoutPaymentGateway::$intents[$pi]['status'], 'paiement capturé : jamais de paiement sans commande');
        $incident = $this->master()->prepare("SELECT level, message, context FROM platform_incident WHERE route = 'checkout/panier' AND tenant_code = ?");
        $incident->execute([MV_TEST_TENANT_CODE]);
        $row = $incident->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'un incident est noté');
        $this->assertSame('warning', $row['level']);
        $this->assertStringNotContainsString('@', (string) $row['message'] . (string) $row['context'], 'aucune donnée du client dans l\'incident');
    }

    public function testABrowserRefusalNeverLeavesAnOrphanPaymentTheWebhookCreatesThePaidOrder(): void
    {
        $paid = $this->guestBody('paid', 'd');
        $pi = $this->paidIntent('paid', $paid);
        $refused = $this->send('POST', '/api/order/create-guest', $this->guestBody('other', 'd') + ['paymentIntentId' => $pi]);
        $this->assertSame(409, $refused->getStatusCode(), (string) $refused->getContent());
        $this->assertSame(0, $this->orders());
        // le filet : le webhook crée la commande du panier payé, sans incident (le corps gardé est celui du panier)
        $this->assertSame('processed', $this->webhook($pi)['status']);
        $order = (int) $this->db->fetchOne('SELECT order_id FROM checkout_session WHERE payment_intent_id = ?', [$pi]);
        $this->assertSame([$this->variants['paid'] => 1], $this->lines($order));
        $this->assertSame('succeeded', FakeCheckoutPaymentGateway::$intents[$pi]['status']);
        $count = $this->master()->prepare("SELECT COUNT(*) FROM platform_incident WHERE route = 'checkout/panier' AND tenant_code = ?");
        $count->execute([MV_TEST_TENANT_CODE]);
        $this->assertSame(0, (int) $count->fetchColumn(), 'pas d\'incident : le corps gardé était le bon');
    }

    /** Relecture : un client connecté (route /api/order/create avec la fiche d'adresse de son compte) est tenu de même */
    public function testTheCustomerRouteIsHeldToThePaidCartToo(): void
    {
        $user = (new \App\Entity\User())->setEmail("panier-{$this->tag}-client@example.invalid")->setUsername("panier-{$this->tag}-client@example.invalid")
            ->setFirstname('Client')->setLastname('Panier')->setRoles(['ROLE_USER_INTERNET'])->setPassword('inutilisable')->setIsVerified(true);
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $em->persist($user);
        $address = (new \App\Entity\Adress())->setFirstname('Client')->setLastname('Panier')->setFullname('Client Panier')->setAddress('1 rue du Test')->setCity('Montréal')
            ->setCodepostal('H1A 1A1')->setCountry('CA')->setPhone('+1 514 555 0000')->setProvince('QC')->setUserAdress($user);
        $em->persist($address);
        $em->flush();
        $token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $items = [['productVariantId' => $this->variants['paid'], 'quantity' => 1]];
        $intent = $this->send('POST', '/api/stripe/create-intent', ['items' => $items, 'carrierId' => 6, 'order' => ['addressId' => $address->getId(), 'carrierId' => 6, 'items' => $items]], $token);
        $this->assertSame(200, $intent->getStatusCode(), (string) $intent->getContent());
        $pi = (string) json_decode((string) $intent->getContent(), true)['paymentIntentId'];
        FakeCheckoutPaymentGateway::confirm($pi);
        $swap = $this->send('POST', '/api/order/create', ['addressId' => $address->getId(), 'carrierId' => 6, 'items' => [['productVariantId' => $this->variants['offline'], 'quantity' => 1]], 'paymentIntentId' => $pi], $token);
        $this->assertSame([409, 'CART_MISMATCH'], [$swap->getStatusCode(), json_decode((string) $swap->getContent(), true)['code'] ?? null], (string) $swap->getContent());
        $created = $this->send('POST', '/api/order/create', ['addressId' => $address->getId(), 'carrierId' => 6, 'items' => $items, 'paymentIntentId' => $pi], $token);
        $this->assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $this->assertSame([$this->variants['paid'] => 1], $this->lines((int) json_decode((string) $created->getContent(), true)['orderId']));
    }

    /** Relecture : des frais de livraison mis dans le corps (même absents du panier) n'entrent jamais dans la commande */
    public function testShippingKeysOfTheBodyNeverReachTheOrder(): void
    {
        $paid = $this->guestBody('paid', 'e');
        $intent = $this->send('POST', '/api/stripe/create-intent', ['items' => $paid['items'], 'carrierId' => 6, 'shippingAddress' => ['province' => 'QC']]);
        $quote = json_decode((string) $intent->getContent(), true);
        FakeCheckoutPaymentGateway::confirm($quote['paymentIntentId']);
        $created = $this->send('POST', '/api/order/create-guest', ['priceShipping' => 0, 'shippingPrice' => 0, 'service' => 'x'] + $paid + ['paymentIntentId' => $quote['paymentIntentId']]);
        $this->assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $order = (int) json_decode((string) $created->getContent(), true)['orderId'];
        $this->assertSame((int) $quote['quote']['shipping'], (int) round((float) $this->db->fetchOne('SELECT shipping_cost FROM "order" WHERE id = ?', [$order])), 'les frais du devis payé, pas ceux du corps');
        $this->assertSame([$this->variants['paid'] => 1], $this->lines($order));
    }

    /**
     * Relecture et vérification des pertes : la même heure de réservation écrite autrement (sans fuseau = heure du magasin, « Z »,
     * décalage) n'est pas un écart, et la commande se crée (permis donné) ; une autre heure en est un.
     */
    public function testTheSameBookingInstantWrittenOtherwiseIsNotAMismatch(): void
    {
        $kayak = $this->kayak();
        $quoted = [['productVariantId' => $kayak, 'quantity' => 1, 'booking' => ['start' => '2030-01-02T10:00:00', 'end' => '2030-01-02T12:00:00', 'durationType' => 'hour']]];
        $pi = $this->paidItems($quoted);
        $body = $this->rentalBody('f');
        $body['items'] = [['productVariantId' => $kayak, 'quantity' => 1, 'booking' => ['start' => '2030-01-02T15:00:00.000Z', 'end' => '2030-01-02T12:00:00-05:00', 'durationType' => 'hour']]];
        $moved = $body;
        $moved['items'][0]['booking']['start'] = '2030-01-02T11:00:00-05:00';
        $refused = $this->send('POST', '/api/order/create-guest', $moved + ['paymentIntentId' => $pi]);
        $this->assertSame('CART_MISMATCH', json_decode((string) $refused->getContent(), true)['code'] ?? null, 'une autre heure : un écart');
        $created = $this->send('POST', '/api/order/create-guest', $body + ['paymentIntentId' => $pi]);
        $this->assertSame(201, $created->getStatusCode(), 'même instant (heure du magasin, « Z », décalage) : la commande se crée : ' . $created->getContent());
        $this->assertSame([$kayak => 1], $this->lines((int) json_decode((string) $created->getContent(), true)['orderId']));
    }

    /** Vérification des pertes : une réservation donnée au niveau du panier (`rental`) est gardée avec lui et arrive à la commande */
    public function testACartLevelRentalIsKeptAndReachesTheOrder(): void
    {
        $kayak = $this->kayak();
        $rental = ['start' => '2030-01-03T10:00:00', 'end' => '2030-01-03T12:00:00', 'durationType' => 'hour'];
        $intent = $this->send('POST', '/api/stripe/create-intent', ['items' => [['productVariantId' => $kayak, 'quantity' => 1]], 'carrierId' => 6, 'rental' => $rental, 'shippingAddress' => ['province' => 'QC']]);
        $this->assertSame(200, $intent->getStatusCode(), (string) $intent->getContent());
        $pi = (string) json_decode((string) $intent->getContent(), true)['paymentIntentId'];
        FakeCheckoutPaymentGateway::confirm($pi);
        $this->assertSame($rental, json_decode((string) $this->db->fetchOne('SELECT cart FROM checkout_session WHERE payment_intent_id = ?', [$pi]), true)['rental'] ?? null, 'gardée avec le panier');
        $body = ['items' => [['productVariantId' => $kayak, 'quantity' => 1]], 'rental' => $rental] + $this->rentalBody('g');
        $created = $this->send('POST', '/api/order/create-guest', $body + ['paymentIntentId' => $pi]);
        $this->assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $order = (int) json_decode((string) $created->getContent(), true)['orderId'];
        $this->assertGreaterThan(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM booking b JOIN order_items i ON i.id = b.order_item_id WHERE i.order_associated_id = ?', [$order]), 'la location est réservée');
    }

    /** Vérification des pertes : une adresse SAISIE partielle (sans province ni code postal) ne passe pas une adresse cotée */
    public function testAPartialTypedAddressIsAMismatch(): void
    {
        $paid = $this->guestBody('paid', 'h');
        $pi = $this->paidIntent('paid');
        $partial = ['shippingAddress' => ['firstname' => 'Invité', 'lastname' => 'Panier', 'address' => '1 rue du Test', 'city' => 'Québec']] + $paid;
        $refused = $this->send('POST', '/api/order/create-guest', $partial + ['paymentIntentId' => $pi]);
        $this->assertSame([409, 'CART_MISMATCH'], [$refused->getStatusCode(), json_decode((string) $refused->getContent(), true)['code'] ?? null], (string) $refused->getContent());
        $this->assertSame(0, $this->orders());
    }

    /**
     * Vérification des pertes : une reprise de l'intention avec une AUTRE adresse, sans renvoyer `order`, abandonne le corps gardé :
     * le webhook ne crée jamais la commande à l'ancienne adresse (il l'ignore, l'autorisation expirera sans débit si le navigateur
     * ne revient pas).
     */
    public function testAResumedIntentWithAnotherAddressDropsTheStaleKeptBody(): void
    {
        $paid = $this->guestBody('paid', 'i');
        $first = $this->send('POST', '/api/stripe/create-intent', ['items' => $paid['items'], 'carrierId' => 6, 'shippingAddress' => ['province' => 'QC'], 'order' => $paid]);
        $pi = (string) json_decode((string) $first->getContent(), true)['paymentIntentId'];
        $this->assertNotNull($this->db->fetchOne('SELECT order_data FROM checkout_session WHERE payment_intent_id = ?', [$pi]));
        $again = $this->send('POST', '/api/stripe/create-intent', ['items' => $paid['items'], 'carrierId' => 6, 'shippingAddress' => ['province' => 'ON', 'postalCode' => 'K1A 0B1'], 'paymentIntentId' => $pi]);
        $this->assertSame(200, $again->getStatusCode(), (string) $again->getContent());
        $this->assertNull($this->db->fetchOne('SELECT order_data FROM checkout_session WHERE payment_intent_id = ?', [$pi]), 'le corps à l\'ancienne adresse est abandonné');
        FakeCheckoutPaymentGateway::confirm($pi);
        $this->assertSame('ignored', $this->webhook($pi)['status'], 'aucune commande à l\'ancienne adresse');
        $this->assertSame(0, $this->orders());
    }

    private function kayak(): int
    {
        $kayak = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-KAYAK' ORDER BY v.id LIMIT 1");
        $this->assertGreaterThan(0, $kayak, 'le kayak du jeu de démonstration');

        return $kayak;
    }

    /** Une intention cotée pour ces lignes, confirmée */
    private function paidItems(array $items): string
    {
        $intent = $this->send('POST', '/api/stripe/create-intent', ['items' => $items, 'carrierId' => 6, 'shippingAddress' => ['province' => 'QC']]); // taxes de la commande à venir
        $this->assertSame(200, $intent->getStatusCode(), (string) $intent->getContent());
        $pi = (string) json_decode((string) $intent->getContent(), true)['paymentIntentId'];
        FakeCheckoutPaymentGateway::confirm($pi);

        return $pi;
    }

    /** Le corps d'un invité qui loue (permis donné) */
    private function rentalBody(string $who): array
    {
        $body = $this->guestBody('paid', $who);
        $body['guestInfo'] += ['licenseNumber' => 'P1234-56789-01234', 'licenseExpirationDate' => '2031-12-31'];

        return $body;
    }

    /** Une intention créée pour l'article $key (et son corps de commande, si donné), confirmée par le client */
    private function paidIntent(string $key, ?array $order = null): string
    {
        $intent = $this->send('POST', '/api/stripe/create-intent', array_filter(['items' => [['productVariantId' => $this->variants[$key], 'quantity' => 1]], 'carrierId' => 6,
            'shippingAddress' => ['province' => 'QC'], 'order' => $order]));
        $this->assertSame(200, $intent->getStatusCode(), (string) $intent->getContent());
        $pi = (string) json_decode((string) $intent->getContent(), true)['paymentIntentId'];
        FakeCheckoutPaymentGateway::confirm($pi);

        return $pi;
    }

    /** @return array<string, mixed> le corps d'une commande d'invité (comme CheckoutApiTest) */
    private function guestBody(string $key, string $who): array
    {
        return [
            'guestInfo' => ['email' => "panier-{$this->tag}-$who@example.invalid", 'firstName' => 'Invité', 'lastName' => 'Panier', 'phone' => '5145550100'],
            'items' => [['productVariantId' => $this->variants[$key], 'quantity' => 1]], 'carrierId' => 6,
            'shippingAddress' => ['firstname' => 'Invité', 'lastname' => 'Panier', 'address' => '1 rue du Test', 'city' => 'Québec', 'province' => 'QC', 'postalCode' => 'G1R 4P5'],
        ];
    }

    /** @return array<int, int> variante => quantité des lignes de la commande */
    private function lines(int $order): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT product_variant_id AS v, quantity AS q FROM order_items WHERE order_associated_id = ?', [$order]);

        return array_combine(array_map(static fn (array $r): int => (int) $r['v'], $rows), array_map(static fn (array $r): int => (int) $r['q'], $rows));
    }

    private function orders(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order" WHERE id > ?', [$this->ordersBefore]);
    }

    private function webhook(string $pi): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/stripe/webhook', [], [], ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['id' => 'evt_' . bin2hex(random_bytes(4)), 'type' => 'payment_intent.amount_capturable_updated', 'data' => ['object' => ['id' => $pi, 'object' => 'payment_intent', 'metadata' => ['tenant_code' => MV_TEST_TENANT_CODE]]]]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function send(string $method, string $path, array $body, ?string $token = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter(['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.89.' . random_int(1, 254) . '.' . random_int(1, 254), 'HTTP_AUTHORIZATION' => $token !== null ? 'Bearer ' . $token : null]), (string) json_encode($body));

        return $this->client->getResponse();
    }

    private function master(): \PDO
    {
        return static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
    }

    /**
     * Efface des lignes et, d'abord, tout ce qui les cite par une clé étrangère (lu dans le schéma de la base d'essai), de proche
     * en proche (comme FichesHorsLigneTest) : l'essai ne laisse rien derrière lui dans la base partagée.
     *
     * @param list<int> $ids
     */
    private function purge(string $table, array $ids, int $depth = 0): void
    {
        if ($ids === [] || $depth > 6) {
            return;
        }
        $refs = $this->db->fetchAllAssociative("SELECT kcu.table_name AS t, kcu.column_name AS c FROM information_schema.referential_constraints rc
            JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = rc.constraint_name AND kcu.constraint_schema = rc.constraint_schema
            JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = rc.unique_constraint_name AND ccu.constraint_schema = rc.unique_constraint_schema
            WHERE ccu.table_name = ? AND ccu.column_name = 'id' AND kcu.table_schema = current_schema()", [$table]);
        $list = implode(',', array_map('intval', $ids));
        foreach ($refs as $ref) {
            if ($ref['t'] === $table) {
                continue;
            }
            $hasId = (bool) $this->db->fetchOne("SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = 'id'", [$ref['t']]);
            if ($hasId) {
                $this->purge($ref['t'], array_map('intval', $this->db->fetchFirstColumn(sprintf('SELECT id FROM "%s" WHERE "%s" IN (%s)', $ref['t'], $ref['c'], $list))), $depth + 1);
            } else {
                $this->db->executeStatement(sprintf('DELETE FROM "%s" WHERE "%s" IN (%s)', $ref['t'], $ref['c'], $list));
            }
        }
        $this->db->executeStatement(sprintf('DELETE FROM "%s" WHERE id IN (%s)', $table, $list));
    }
}
