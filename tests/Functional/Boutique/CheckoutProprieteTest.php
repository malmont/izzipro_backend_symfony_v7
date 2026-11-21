<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Adress;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot CHECKOUT-PROPRIETE (21/11/2026, `docs/boutique.md`, « Paiement d'un panier ») : un paiement appartient à celui qui l'a
 * ouvert. Un tiers qui connaît le pi_… d'un autre ne crée pas sa commande (route client : la session du client connecté ; route
 * invité : une session d'invité, courriel et adresse gardés à create-intent), ne reprend pas son intention (ni clientSecret, ni
 * corps gardé), et la caisse ne compte pas le paiement d'un panier web. Le webhook reste le filet.
 */
class CheckoutProprieteTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private string $tag;
    private int $variant = 0;
    private int $product = 0;
    private int $ordersBefore = 0;
    /** @var array<string, array{0: User, 1: string, 2: int}> compte, jeton, fiche d'adresse */
    private array $customers = [];

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
        $this->ordersBefore = (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM "order"');
        $product = (new Product())->setName("Propriété {$this->tag}")->setSlug("propriete-{$this->tag}")->setPrice(1990)->setIsWeb(true)->setIsPos(true)->setSaleEnabled(true);
        $product->addVariant($variant = (new ProductVariant())->setStockQuantity(50)->setPrice(1990));
        $em->persist($product);
        $em->flush();
        [$this->product, $this->variant] = [(int) $product->getId(), (int) $variant->getId()];
        foreach (['a', 'b'] as $who) {
            $email = "propriete-{$this->tag}-$who@example.invalid";
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Client')->setLastname(strtoupper($who))->setRoles(['ROLE_USER_INTERNET'])->setPassword('inutilisable')->setIsVerified(true);
            $em->persist($user);
            $address = (new Adress())->setFirstname('Client')->setLastname(strtoupper($who))->setFullname('Client ' . $who)->setAddress('1 rue du Test')->setCity('Montréal')
                ->setCodepostal('H1A 1A1')->setCountry('CA')->setPhone('+1 514 555 0000')->setProvince('QC')->setUserAdress($user);
            $em->persist($address);
            $em->flush();
            $this->customers[$who] = [$user, static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user), (int) $address->getId()];
        }
        $em->clear();
    }

    protected function tearDown(): void
    {
        $this->purge('order', array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM "order" WHERE id > ?', [$this->ordersBefore])));
        foreach (array_keys(FakeCheckoutPaymentGateway::$intents) as $intent) {
            $this->db->executeStatement('DELETE FROM checkout_session WHERE payment_intent_id = ? AND order_id IS NULL', [$intent]);
        }
        $this->purge('product', [$this->product]);
        $this->purge('user', array_map('intval', $this->db->fetchFirstColumn("SELECT id FROM \"user\" WHERE email LIKE ?", ['propriete-' . $this->tag . '%'])));
        parent::tearDown();
    }

    public function testACustomerCannotOrderWithThePaymentOfAnotherCustomer(): void
    {
        [, $tokenA, $addressA] = $this->customers['a'];
        [, $tokenB, $addressB] = $this->customers['b'];
        $pi = $this->customerIntent($tokenA, $addressA);
        FakeCheckoutPaymentGateway::confirm($pi); // payé par A
        $refused = $this->send('POST', '/api/order/create', $this->customerBody($addressB) + ['paymentIntentId' => $pi, 'clientSecret' => $pi . '_secret_test'], $tokenB);
        $this->assertSame([409, 'PAYMENT_ALREADY_USED'], $this->status($refused), 'B avec le pi_… de A');
        $this->assertStringNotContainsString('propriete-', (string) $refused->getContent(), 'sans dire à qui il est');
        $this->assertSame(0, $this->orders());
        $created = $this->send('POST', '/api/order/create', $this->customerBody($addressA) + ['paymentIntentId' => $pi, 'clientSecret' => $pi . '_secret_test'], $tokenA);
        $this->assertSame(201, $created->getStatusCode(), 'A, lui, commande : ' . $created->getContent());
    }

    public function testAGuestCannotUseThePaymentOfACustomerNorOfAnotherGuest(): void
    {
        [, $tokenA, $addressA] = $this->customers['a'];
        $customerPi = $this->customerIntent($tokenA, $addressA);
        FakeCheckoutPaymentGateway::confirm($customerPi);
        $this->assertSame([409, 'PAYMENT_ALREADY_USED'], $this->status($this->send('POST', '/api/order/create-guest', $this->guestBody('pirate') + ['paymentIntentId' => $customerPi, 'clientSecret' => $customerPi . '_secret_test'])), 'invité avec le pi_… d\'un client');
        // un invité avec le pi_… d'un AUTRE invité et un autre courriel : refusé ; le courriel et l'adresse sont ceux gardés à create-intent
        $guestPi = $this->guestIntent('victime');
        $this->assertSame([409, 'CART_MISMATCH'], $this->status($this->send('POST', '/api/order/create-guest', $this->guestBody('pirate') + ['paymentIntentId' => $guestPi, 'clientSecret' => $guestPi . '_secret_test'])), 'autre invité, autre courriel');
        $this->assertSame(0, $this->orders());
        $honest = $this->send('POST', '/api/order/create-guest', $this->guestBody('victime') + ['paymentIntentId' => $guestPi, 'clientSecret' => $guestPi . '_secret_test']);
        $this->assertSame(201, $honest->getStatusCode(), (string) $honest->getContent());
        $order = (int) json_decode((string) $honest->getContent(), true)['orderId'];
        $this->assertSame("propriete-{$this->tag}-victime@example.invalid", $this->db->fetchOne('SELECT u.email FROM "order" o JOIN "user" u ON u.id = o.user_id_id WHERE o.id = ?', [$order]));
    }

    public function testAThirdPartyNeverResumesAnotherIntentNorGetsItsSecret(): void
    {
        [, $tokenA, $addressA] = $this->customers['a'];
        [, $tokenB, $addressB] = $this->customers['b'];
        $pi = $this->customerIntent($tokenA, $addressA);
        $secret = FakeCheckoutPaymentGateway::$intents[$pi]['clientSecret'] ?? null;
        $resumed = json_decode((string) $this->send('POST', '/api/stripe/create-intent', ['items' => $this->items(), 'carrierId' => 6, 'paymentIntentId' => $pi, 'clientSecret' => $pi . '_secret_test'], $tokenB)->getContent(), true);
        $this->assertNotSame($pi, $resumed['paymentIntentId'] ?? null, 'client B : une nouvelle intention');
        $this->assertFalse($resumed['reused'] ?? true);
        if ($secret !== null) {
            $this->assertNotSame($secret, $resumed['clientSecret'], 'jamais le clientSecret de A');
        }
        $this->assertNull($this->db->fetchOne('SELECT order_data FROM checkout_session WHERE payment_intent_id = ?', [$resumed['paymentIntentId']]), 'ni le corps gardé de A');
        // un invité qui reprend l'intention d'un autre invité SANS sa preuve (le clientSecret), même avec son courriel : une nouvelle intention
        $guestPi = $this->guestIntent('victime', false);
        $other = json_decode((string) $this->send('POST', '/api/stripe/create-intent', ['items' => $this->items(), 'carrierId' => 6, 'shippingAddress' => ['province' => 'QC'],
            'order' => $this->guestBody('pirate'), 'paymentIntentId' => $guestPi])->getContent(), true); // un tiers : sans le secret
        $this->assertNotSame($guestPi, $other['paymentIntentId']);
        // … ni avec une FAUSSE preuve
        $forged = json_decode((string) $this->send('POST', '/api/stripe/create-intent', ['items' => $this->items(), 'carrierId' => 6, 'shippingAddress' => ['province' => 'QC'],
            'order' => $this->guestBody('victime'), 'paymentIntentId' => $guestPi, 'clientSecret' => $guestPi . '_secret_faux'])->getContent(), true);
        $this->assertNotSame($guestPi, $forged['paymentIntentId'], 'fausse preuve : une nouvelle intention');
        $this->assertSame("propriete-{$this->tag}-victime@example.invalid", json_decode((string) $this->db->fetchOne('SELECT order_data FROM checkout_session WHERE payment_intent_id = ?', [$guestPi]), true)['guestInfo']['email'], 'la session de la victime est intacte');
        // le même invité (même courriel) reprend bien la sienne ; le client A aussi
        $same = json_decode((string) $this->send('POST', '/api/stripe/create-intent', ['items' => $this->items(), 'carrierId' => 6, 'shippingAddress' => ['province' => 'QC'],
            'order' => $this->guestBody('victime'), 'paymentIntentId' => $guestPi, 'clientSecret' => $guestPi . '_secret_test'])->getContent(), true);
        $this->assertSame([$guestPi, true], [$same['paymentIntentId'], $same['reused']]);
        $own = json_decode((string) $this->send('POST', '/api/stripe/create-intent', ['items' => $this->items(), 'carrierId' => 6, 'paymentIntentId' => $pi, 'clientSecret' => $pi . '_secret_test'], $tokenA)->getContent(), true);
        $this->assertSame([$pi, true], [$own['paymentIntentId'], $own['reused']]);
    }

    public function testAConfirmedIntentIsNeverResumed(): void
    {
        [, $tokenA, $addressA] = $this->customers['a'];
        $pi = $this->customerIntent($tokenA, $addressA);
        FakeCheckoutPaymentGateway::confirm($pi);
        $again = json_decode((string) $this->send('POST', '/api/stripe/create-intent', ['items' => [['productVariantId' => $this->variant, 'quantity' => 2]], 'carrierId' => 6, 'paymentIntentId' => $pi, 'clientSecret' => $pi . '_secret_test'], $tokenA)->getContent(), true);
        $this->assertNotSame($pi, $again['paymentIntentId'], 'intention déjà confirmée : une nouvelle');
        $this->assertSame([['productVariantId' => $this->variant, 'quantity' => 1]], json_decode((string) $this->db->fetchOne('SELECT cart FROM checkout_session WHERE payment_intent_id = ?', [$pi]), true)['items'], 'le panier payé est intact');
    }

    public function testTheWebhookStaysTheNetAfterAnOwnershipRefusal(): void
    {
        [, $tokenA, $addressA] = $this->customers['a'];
        [, $tokenB, $addressB] = $this->customers['b'];
        $pi = $this->customerIntent($tokenA, $addressA, true);
        FakeCheckoutPaymentGateway::confirm($pi);
        $this->assertSame(409, $this->send('POST', '/api/order/create', $this->customerBody($addressB) + ['paymentIntentId' => $pi, 'clientSecret' => $pi . '_secret_test'], $tokenB)->getStatusCode());
        $this->assertSame('processed', $this->webhook($pi)['status'], 'le webhook crée la commande de A');
        $order = (int) $this->db->fetchOne('SELECT order_id FROM checkout_session WHERE payment_intent_id = ?', [$pi]);
        $this->assertSame((int) $this->customers['a'][0]->getId(), (int) $this->db->fetchOne('SELECT user_id_id FROM "order" WHERE id = ?', [$order]), 'la commande est celle de A');
        $this->assertSame('succeeded', FakeCheckoutPaymentGateway::$intents[$pi]['status']);
    }

    public function testTheCashRegisterRefusesThePaymentOfAWebCart(): void
    {
        [, $tokenA, $addressA] = $this->customers['a'];
        $pi = $this->customerIntent($tokenA, $addressA, true);
        FakeCheckoutPaymentGateway::confirm($pi);
        $admin = $this->staff();
        foreach (['payment' => ['payment' => ['stripePaymentId' => $pi], 'paymentMethods' => [['type' => 2, 'amount' => 19.90]]],
            'part carte' => ['paymentMethods' => [['type' => 1, 'amount' => 19.90, 'stripePaymentId' => $pi]]]] as $label => $body) {
            $refused = $this->send('POST', '/api/order/create-multi-payment', ['items' => $this->items(), 'cashRegisterId' => 1] + $body, $admin);
            $this->assertSame([409, 'PAYMENT_ALREADY_USED'], $this->status($refused), "caisse, $label : " . $refused->getContent());
        }
        $this->assertSame(0, $this->orders());
        $this->assertSame('processed', $this->webhook($pi)['status'] ?? null, 'le client web garde sa commande');
    }

    /** Point 1 et 2 des relectures : sans la preuve (clientSecret), ni commande, ni reprise ; une fausse preuve donne la même réponse */
    public function testWithoutTheClientSecretNothingIsPossibleAndTheAnswerSaysNothing(): void
    {
        $guestPi = $this->guestIntent('victime');
        $answers = [];
        foreach (['sans preuve' => [], 'fausse preuve' => ['clientSecret' => $guestPi . '_secret_autre'], 'preuve d\'une autre intention' => ['clientSecret' => 'pi_autre_secret_test']] as $label => $proof) {
            $refused = $this->send('POST', '/api/order/create-guest', $this->guestBody('victime') + ['paymentIntentId' => $guestPi] + $proof);
            $this->assertSame([403, 'PAYMENT_PROOF_REQUIRED'], $this->status($refused), $label);
            $answers[] = (string) $refused->getContent();
        }
        $this->assertCount(1, array_unique($answers), 'absente ou fausse : la même réponse');
        // une intention NON payée et une intention inconnue répondent de même : la preuve passe avant tout
        $open = $this->guestIntent('autre', false);
        $this->assertSame((string) $this->send('POST', '/api/order/create-guest', $this->guestBody('autre') + ['paymentIntentId' => $open])->getContent(), $answers[0]);
        $this->assertSame((string) $this->send('POST', '/api/order/create-guest', $this->guestBody('autre') + ['paymentIntentId' => 'pi_inconnu_' . $this->tag])->getContent(), $answers[0]);
        $this->assertSame(0, $this->orders());
        // invité SANS corps gardé (create-intent sans `order`) : un tiers sans le secret ne fixe pas le destinataire
        $bare = $this->send('POST', '/api/stripe/create-intent', ['items' => $this->items(), 'carrierId' => 6, 'shippingAddress' => ['province' => 'QC']]);
        $barePi = (string) json_decode((string) $bare->getContent(), true)['paymentIntentId'];
        FakeCheckoutPaymentGateway::confirm($barePi);
        $this->assertSame([403, 'PAYMENT_PROOF_REQUIRED'], $this->status($this->send('POST', '/api/order/create-guest', $this->guestBody('pirate') + ['paymentIntentId' => $barePi])));
        $this->assertSame(201, $this->send('POST', '/api/order/create-guest', $this->guestBody('victime') + ['paymentIntentId' => $barePi, 'clientSecret' => $barePi . '_secret_test'])->getStatusCode(), 'le navigateur qui a ouvert le paiement commande');
    }

    /** Point 3 : jeton expiré à create-intent (session d'invité), puis order/create connecté avec la preuve : la commande est au compte */
    public function testACustomerWhoseTokenExpiredAtCreateIntentStillGetsTheOrder(): void
    {
        [$userA, $tokenA, $addressA] = $this->customers['a'];
        $intent = $this->send('POST', '/api/stripe/create-intent', ['items' => $this->items(), 'carrierId' => 6, 'shippingAddress' => ['province' => 'QC']]); // sans jeton : invité
        $pi = (string) json_decode((string) $intent->getContent(), true)['paymentIntentId'];
        FakeCheckoutPaymentGateway::confirm($pi);
        $created = $this->send('POST', '/api/order/create', $this->customerBody($addressA) + ['paymentIntentId' => $pi, 'clientSecret' => $pi . '_secret_test'], $tokenA);
        $this->assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $this->assertSame((int) $userA->getId(), (int) $this->db->fetchOne('SELECT user_id_id FROM "order" WHERE id = ?', [(int) json_decode((string) $created->getContent(), true)['orderId']]));
    }

    /** Point 4 : l'invité qui change son panier sans renvoyer `order` reprend SON intention avec sa preuve (le corps gardé reste) */
    public function testAGuestResumesItsIntentWithTheProofWithoutResendingTheOrder(): void
    {
        $pi = $this->guestIntent('victime', false);
        $again = json_decode((string) $this->send('POST', '/api/stripe/create-intent', ['items' => [['productVariantId' => $this->variant, 'quantity' => 2]], 'carrierId' => 6,
            'shippingAddress' => ['province' => 'QC'], 'paymentIntentId' => $pi, 'clientSecret' => $pi . '_secret_test'])->getContent(), true);
        $this->assertSame([$pi, true], [$again['paymentIntentId'], $again['reused']]);
        $this->assertSame("propriete-{$this->tag}-victime@example.invalid", json_decode((string) $this->db->fetchOne('SELECT order_data FROM checkout_session WHERE payment_intent_id = ?', [$pi]), true)['guestInfo']['email'], 'le corps gardé reste : le webhook garde son filet');
    }

    /** Demande du chef : Stripe muet pendant la relecture de la preuve → 503, jamais d'acceptation par défaut */
    public function testStripeSilentDuringTheProofGives503(): void
    {
        $pi = $this->guestIntent('victime');
        FakeCheckoutPaymentGateway::$unavailable = true;
        try {
            $answer = $this->send('POST', '/api/order/create-guest', $this->guestBody('victime') + ['paymentIntentId' => $pi, 'clientSecret' => $pi . '_secret_test']);
        } finally {
            FakeCheckoutPaymentGateway::$unavailable = false;
        }
        $this->assertSame([503, 'PAYMENT_PROVIDER_UNAVAILABLE'], $this->status($answer));
        $this->assertSame(0, $this->orders());
        $this->assertSame('processed', $this->webhook($pi)['status'] ?? null, 'le webhook reste le filet');
    }

    /** Demande du chef : le clientSecret ne s'écrit nulle part (journal compris) après une commande */
    public function testTheClientSecretIsNeverWrittenToTheLog(): void
    {
        $log = static::getContainer()->getParameter('kernel.logs_dir') . '/' . static::getContainer()->getParameter('kernel.environment') . '.log';
        $before = is_file($log) ? filesize($log) : 0;
        $pi = $this->guestIntent('victime');
        $this->send('POST', '/api/order/create-guest', $this->guestBody('victime') + ['paymentIntentId' => $pi, 'clientSecret' => $pi . '_secret_autre']); // refusée
        $this->assertSame(201, $this->send('POST', '/api/order/create-guest', $this->guestBody('victime') + ['paymentIntentId' => $pi, 'clientSecret' => $pi . '_secret_test'])->getStatusCode());
        clearstatcache();
        $written = is_file($log) ? (string) file_get_contents($log, false, null, $before) : '';
        $this->assertStringNotContainsString('_secret_', $written, 'aucun secret de paiement dans le journal');
        $session = (string) $this->db->fetchOne('SELECT cart::text || COALESCE(order_data::text, \'\') || COALESCE(last_error, \'\') FROM checkout_session WHERE payment_intent_id = ?', [$pi]);
        $this->assertStringNotContainsString('_secret_', $session, 'ni dans la session gardée');
    }

    /** Relecture : Stripe dit « inconnue » (404 → 403) ou il est en panne (connexion, quota, autre erreur → 503) : le tri est juste */
    public function testStripeErrorsAreSortedBetweenUnknownIntentAndOutage(): void
    {
        $unknown = \Stripe\Exception\InvalidRequestException::factory('No such payment_intent', 404, null, null, null, 'resource_missing');
        $this->assertTrue(\App\Services\CheckoutService\StripeCheckoutPaymentGateway::isUnknownIntent($unknown));
        foreach ([\Stripe\Exception\ApiConnectionException::factory('connexion perdue'), \Stripe\Exception\RateLimitException::factory('trop de requêtes', 429),
            \Stripe\Exception\InvalidRequestException::factory('paramètre refusé', 400, null, null, null, 'parameter_invalid'),
            \Stripe\Exception\AuthenticationException::factory('clé refusée', 401)] as $outage) {
            $this->assertFalse(\App\Services\CheckoutService\StripeCheckoutPaymentGateway::isUnknownIntent($outage), $outage::class . ' : une panne, 503');
        }
    }

    /** Relecture : un identifiant mal formé ou une preuve d'une autre forme donnent le 403, sans appeler Stripe, jamais un 500 */
    public function testAMalformedIntentIdOrProofIsA403(): void
    {
        foreach ([' ', 'pi_a b', 'abc'] as $id) {
            $this->assertSame([403, 'PAYMENT_PROOF_REQUIRED'], $this->status($this->send('POST', '/api/order/create-guest', $this->guestBody('victime') + ['paymentIntentId' => $id, 'clientSecret' => $id . '_secret_x'])), json_encode($id));
        }
        FakeCheckoutPaymentGateway::$unavailable = true; // une preuve d'une autre forme ne va même pas chez Stripe : pas de 503
        try {
            $pi = 'pi_' . $this->tag;
            $this->assertSame([403, 'PAYMENT_PROOF_REQUIRED'], $this->status($this->send('POST', '/api/order/create-guest', $this->guestBody('victime') + ['paymentIntentId' => $pi, 'clientSecret' => 'pi_autre_secret_x'])));
        } finally {
            FakeCheckoutPaymentGateway::$unavailable = false;
        }
    }

    /** Vérification des pertes : un invité connecté entre-temps retrouve la commande que le webhook a déjà créée (sans guestToken) */
    public function testACustomerFindsTheGuestOrderTheWebhookAlreadyCreated(): void
    {
        [, $tokenA, $addressA] = $this->customers['a'];
        $pi = $this->guestIntent('victime');
        $this->assertSame('processed', $this->webhook($pi)['status']);
        $again = $this->send('POST', '/api/order/create', $this->customerBody($addressA) + ['paymentIntentId' => $pi, 'clientSecret' => $pi . '_secret_test'], $tokenA);
        $this->assertSame(200, $again->getStatusCode(), (string) $again->getContent());
        $body = json_decode((string) $again->getContent(), true);
        $this->assertTrue($body['alreadyCreated']);
        $this->assertArrayNotHasKey('guestToken', $body, 'mode client : jamais le jeton de l\'invité');
        $this->assertSame(1, $this->orders());
    }

    private function customerIntent(string $token, int $address, bool $keepOrder = false): string
    {
        // taxes de la commande à venir : la province de la fiche du client (comme le tunnel)
        $intent = $this->send('POST', '/api/stripe/create-intent', array_filter(['items' => $this->items(), 'carrierId' => 6, 'shippingAddress' => ['province' => 'QC'],
            'order' => $keepOrder ? $this->customerBody($address) : null]), $token);
        $this->assertSame(200, $intent->getStatusCode(), (string) $intent->getContent());

        return (string) json_decode((string) $intent->getContent(), true)['paymentIntentId'];
    }

    private function guestIntent(string $who, bool $confirm = true): string
    {
        $intent = $this->send('POST', '/api/stripe/create-intent', ['items' => $this->items(), 'carrierId' => 6, 'shippingAddress' => ['province' => 'QC'], 'order' => $this->guestBody($who)]);
        $this->assertSame(200, $intent->getStatusCode(), (string) $intent->getContent());
        $pi = (string) json_decode((string) $intent->getContent(), true)['paymentIntentId'];
        if ($confirm) {
            FakeCheckoutPaymentGateway::confirm($pi);
        }

        return $pi;
    }

    private function items(): array
    {
        return [['productVariantId' => $this->variant, 'quantity' => 1]];
    }

    private function customerBody(int $address): array
    {
        return ['addressId' => $address, 'carrierId' => 6, 'items' => $this->items()];
    }

    private function guestBody(string $who): array
    {
        return [
            'guestInfo' => ['email' => "propriete-{$this->tag}-$who@example.invalid", 'firstName' => 'Invité', 'lastName' => ucfirst($who), 'phone' => '5145550100'],
            'items' => $this->items(), 'carrierId' => 6,
            'shippingAddress' => ['firstname' => 'Invité', 'lastname' => ucfirst($who), 'address' => '1 rue du Test', 'city' => 'Québec', 'province' => 'QC', 'postalCode' => 'G1R 4P5'],
        ];
    }

    /** @return array{0: int, 1: ?string} */
    private function status(Response $response): array
    {
        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)['code'] ?? null];
    }

    private function orders(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order" WHERE id > ?', [$this->ordersBefore]);
    }

    private function staff(): string
    {
        $email = "propriete-{$this->tag}-caisse@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Caisse')->setLastname('Essai')->setRoles(['ROLE_ADMIN'])->setPassword('inutilisable')->setIsVerified(true);
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $em->persist($user);
        $em->flush();

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    private function webhook(string $pi): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/stripe/webhook', [], [], ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['id' => 'evt_' . bin2hex(random_bytes(4)), 'type' => 'payment_intent.amount_capturable_updated', 'data' => ['object' => ['id' => $pi, 'object' => 'payment_intent', 'metadata' => ['tenant_code' => MV_TEST_TENANT_CODE]]]]));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function send(string $method, string $path, array $body, ?string $token = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter(['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.90.' . random_int(1, 254) . '.' . random_int(1, 254), 'HTTP_AUTHORIZATION' => $token !== null ? 'Bearer ' . $token : null]), (string) json_encode($body));

        return $this->client->getResponse();
    }

    /** @param list<int> $ids (comme CheckoutPanierTest : efface de proche en proche par les clés étrangères) */
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
