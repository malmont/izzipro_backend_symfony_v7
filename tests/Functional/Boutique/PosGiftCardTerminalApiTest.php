<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\CashRegister;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\PosTerminalService\PosTerminalPaymentService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeTerminalGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Complément F9 / C4 (01/11/2026) : vente d'une carte-cadeau payée au terminal Stripe. giftCardAmount à la place de items dans
 * POST /api/pos/terminal/payments (destination gift_card), refus croisés, rapports X (cardTerminal totaux + giftCards, ventes
 * inchangées), écritures F14 équilibrées (cartes à encaisser / cartes-cadeaux en circulation), paiement sans carte émise
 * (coupure) rattaché en rejouant la vente, annulation de la carte avec remboursement du terminal, kit (clientSecret).
 */
class PosGiftCardTerminalApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';

    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
    private ?string $key = null;
    private int $register;
    private int $cap;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeTerminalGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-carte-terminal@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false,
            pos_pay_cash = true, pos_pay_declared_card = true, pos_pay_paypal = true, pos_pay_multi = true, pos_declared_card_blocked_with_terminal = false, pos_offline_enabled = false,
            pos_gift_card_enabled = true, pos_store_credit_enabled = false, pos_coupon_enabled = false, pos_terminal_interac = NULL, pos_terminal_fee_card_bp = 100, pos_terminal_fee_interac_bp = 150, pos_tap_to_pay_allowed = false, pos_bluetooth_reader_allowed = false");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, stripe_reader_label = NULL, stripe_reader_simulated = false, terminal_mode = NULL, receipt_prefix = NULL');
        $this->db->executeStatement('UPDATE stripe_config SET terminal_location_id = NULL');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement("UPDATE product SET price = 2900, special_price = NULL WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500, price = NULL WHERE id = ?', [$this->cap]);
    }

    public function testGiftCardSoldOnTheStripeTerminalIsCapturedReportedExportedAndRecoverable(): void
    {
        $this->configureSimulatedReader();
        $manager = $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->terminal = $this->posTerminal();
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $error = fn (Response $r) => [$r->getStatusCode(), json_decode($r->getContent(), true)['error'] ?? null];
        $items = [['productVariantId' => $this->cap, 'quantity' => 1]];

        // Corps : exactement l'un des deux
        $this->assertSame([422, 'VALIDATION_FAILED'], $error($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => 5000, 'items' => $items, 'giftCardAmount' => 5000])));
        $this->assertSame([422, 'VALIDATION_FAILED'], $error($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => 5000])));
        $this->assertSame([422, 'AMOUNT_MISMATCH'], $error($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => 4000, 'giftCardAmount' => 5000])), 'la part carte doit couvrir la carte');

        // Carte de 50 $ : 20 $ espèces + 30 $ au terminal (destination gift_card)
        $tp = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => 3000, 'giftCardAmount' => 5000, 'otherPayments' => [['type' => 2, 'amount' => 2000]]]), 201);
        $this->assertSame(['gift_card', 'waiting_for_card', 3000], [$tp['purpose'], $tp['status'], $tp['amount']]);
        $this->assertSame('gift_card', FakeTerminalGateway::$intents[$tp['stripePaymentIntentId']]['metadata']['pos_purpose'], 'destination en métadonnée Stripe');
        $presented = $this->json($this->request('POST', '/api/pos/terminal/payments/' . $tp['paymentId'] . '/simulate', ['type' => 'card_present', 'cardNumber' => '4242424242424242']));
        $this->assertSame(['succeeded', false], [$presented['status'], $presented['captured']], 'carte de crédit autorisée, capturée avec la vente de la carte');
        // Refus croisé : ce paiement ne règle pas une vente avec articles
        $this->assertSame([409, 'TERMINAL_PAYMENT_PURPOSE_MISMATCH'], $error($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 1, 'amount' => 30, 'terminalPaymentId' => $tp['paymentId']], ['type' => 2, 'amount' => 3.35]], 'cashRegisterId' => $this->register])));
        $this->assertSame('requires_capture', FakeTerminalGateway::$intents[$tp['stripePaymentIntentId']]['status'], 'rien capturé');
        // Vente de la carte : vérifié puis capturé dans la transaction
        $this->key = self::uuid();
        $sold = $this->json($this->request('POST', '/api/pos/gift-cards', ['amount' => 5000, 'paymentMethods' => [['type' => 2, 'amount' => 20], ['type' => 1, 'amount' => 30, 'terminalPaymentId' => $tp['paymentId']]], 'cashRegisterId' => $this->register]), 201);
        $this->key = null;
        $this->assertSame(['succeeded', 5000], [FakeTerminalGateway::$intents[$tp['stripePaymentIntentId']]['status'], $sold['giftCard']['balance']]);
        $row = $this->db->fetchAssociative('SELECT status, order_id, purpose FROM pos_terminal_payment WHERE id = ?', [$tp['paymentId']]);
        $this->assertSame(['captured', $sold['orderId'], 'gift_card'], [$row['status'], (int) $row['order_id'], $row['purpose']]);
        $this->assertSame($tp['stripePaymentIntentId'], $this->db->fetchOne('SELECT stripe_payment_id FROM payments WHERE order_payment_id = ? AND payment_method_id = 1', [$sold['orderId']]), 'part carte rattachée au PaymentIntent');
        // Refus croisé inverse : un paiement de vente ne règle pas une carte
        $saleTp = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => 3334, 'items' => $items]), 201);
        $this->assertSame('sale', $saleTp['purpose']);
        $this->json($this->request('POST', '/api/pos/terminal/payments/' . $saleTp['paymentId'] . '/simulate', ['type' => 'card_present']));
        $this->assertSame([409, 'TERMINAL_PAYMENT_PURPOSE_MISMATCH'], $error($this->request('POST', '/api/pos/gift-cards', ['amount' => 3334, 'paymentMethods' => [['type' => 1, 'amount' => 33.34, 'terminalPaymentId' => $saleTp['paymentId']]], 'cashRegisterId' => $this->register])));
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 1, 'amount' => 33.34, 'terminalPaymentId' => $saleTp['paymentId']]], 'cashRegisterId' => $this->register]), 201);

        // Rapport X : totaux cardTerminal (rapprochement Stripe) avec le détail giftCards ; ventes et chiffre d'affaires sans la carte
        $x = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $this->assertSame(['terminal' => 6334, 'interac' => 0, 'declared' => 0, 'count' => 2, 'withoutSale' => 0, 'giftCards' => ['terminal' => 3000, 'interac' => 0, 'declared' => 0, 'count' => 1]], $x['cardTerminal']);
        $this->assertSame([1, 3334], [$x['sales']['count'], $x['sales']['total']], 'la carte n\'est ni une vente ni un chiffre d\'affaires');
        $this->assertSame(['count' => 1, 'amount' => 5000], $x['giftCards']['sold']);

        // Paiement réussi sans carte émise (coupure) : visible avec sa destination, rattachable en rejouant la vente avec la même clé
        $orphan = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => 2500, 'giftCardAmount' => 2500]), 201);
        $this->json($this->request('POST', '/api/pos/terminal/payments/' . $orphan['paymentId'] . '/simulate', ['type' => 'interac_present']));
        $this->db->executeStatement("UPDATE pos_terminal_payment SET created_at = created_at - INTERVAL '10 minutes', updated_at = updated_at - INTERVAL '10 minutes' WHERE id = ?", [$orphan['paymentId']]);
        $list = $this->json($this->request('GET', "/api/pos/terminal/payments?orphans=1&cashRegisterId=$this->register"))['payments'];
        $this->assertSame([[$orphan['paymentId'], 'gift_card', 'succeeded', true]], array_map(fn ($p) => [$p['paymentId'], $p['purpose'], $p['status'], $p['captured']], $list), 'Interac débité sans carte émise');
        $this->key = self::uuid();
        $recovered = $this->json($this->request('POST', '/api/pos/gift-cards', ['amount' => 2500, 'paymentMethods' => [['type' => 1, 'amount' => 25, 'terminalPaymentId' => $orphan['paymentId']]], 'cashRegisterId' => $this->register]), 201);
        $replay = $this->request('POST', '/api/pos/gift-cards', ['amount' => 2500, 'paymentMethods' => [['type' => 1, 'amount' => 25, 'terminalPaymentId' => $orphan['paymentId']]], 'cashRegisterId' => $this->register]);
        $this->assertSame(['true', $recovered['giftCard']['id']], [$replay->headers->get('Idempotent-Replayed'), json_decode($replay->getContent(), true)['giftCard']['id']], 'même clé : même carte, pas de seconde');
        $this->key = null;
        $this->assertSame([], $this->json($this->request('GET', "/api/pos/terminal/payments?orphans=1&cashRegisterId=$this->register"))['payments']);
        $this->assertSame([409, 'TERMINAL_PAYMENT_ALREADY_USED'], $error($this->request('POST', '/api/pos/gift-cards', ['amount' => 2500, 'paymentMethods' => [['type' => 1, 'amount' => 25, 'terminalPaymentId' => $orphan['paymentId']]], 'cashRegisterId' => $this->register])));

        // Annulation de la première carte (jamais utilisée) : remboursement Stripe de la part terminal, espèces rendues
        $canceled = $this->json($this->request('POST', '/api/pos/gift-cards/' . $sold['giftCard']['code'] . '/cancel', ['cashRegisterId' => $this->register]));
        $this->assertSame('canceled', $canceled['giftCard']['status']);
        $this->assertSame(3000, FakeTerminalGateway::$intents[$tp['stripePaymentIntentId']]['refunded'], 'part terminal remboursée chez Stripe');

        // Clôture et export F14 : écritures équilibrées, cartes vendues au terminal = débit cartes à encaisser / crédit cartes-cadeaux en circulation
        $x = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $this->json($this->request('POST', '/api/caisse/close', ['cashRegisterId' => $this->register, 'countedCash' => $x['expectedCash'], 'keptFund' => 0]));
        $from = date('Y-m-d', strtotime('-2 days'));
        $to = date('Y-m-d', strtotime('+1 day'));
        $e = $this->json($this->request('GET', "/api/pos/exports/accounting?from=$from&to=$to&cashRegisterId=$this->register"));
        $this->assertTrue($e['totals']['balanced'], 'écritures équilibrées');
        $sold = fn (string $key, string $labelPart) => (int) array_sum(array_map(fn ($l) => $l['debit'] - $l['credit'], array_filter($e['entries'], fn ($l) => $l['accountKey'] === $key && str_contains($l['label'], $labelPart))));
        $this->assertSame(3000, $sold('card_clearing', 'Cartes-cadeaux vendues (card)'), 'part terminal de la carte au débit des cartes à encaisser, comme une carte déclarée');
        $this->assertSame(2500, $sold('interac_clearing', 'Cartes-cadeaux vendues (interac)'));
        $this->assertSame(-7500, $sold('gift_card_liability', 'Cartes-cadeaux émises'), 'au crédit du passif');

        // Kit Stripe Terminal (F13/F16) : même entrée, secret client rendu
        $this->db->executeStatement("UPDATE cash_register SET terminal_mode = 'tap_to_pay' WHERE id = ?", [$this->register]);
        $this->db->executeStatement('UPDATE entreprise SET pos_tap_to_pay_allowed = true');
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $sdk = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => 4000, 'giftCardAmount' => 4000]), 201);
        $this->assertSame(['sdk', 'gift_card', $sdk['stripePaymentIntentId'] . '_secret_test'], [$sdk['channel'], $sdk['purpose'], $sdk['clientSecret']]);
        FakeTerminalGateway::sdkConfirm($sdk['stripePaymentIntentId'], '4242424242424242');
        $this->assertSame('succeeded', $this->json($this->request('GET', '/api/pos/terminal/payments/' . $sdk['paymentId']))['status']);
        $card = $this->json($this->request('POST', '/api/pos/gift-cards', ['amount' => 4000, 'paymentMethods' => [['type' => 1, 'amount' => 40, 'terminalPaymentId' => $sdk['paymentId']]], 'cashRegisterId' => $this->register]), 201);
        $this->assertSame(4000, $card['giftCard']['balance']);
        $this->assertSame('succeeded', FakeTerminalGateway::$intents[$sdk['stripePaymentIntentId']]['status'], 'capturé avec la vente de la carte');
        $this->db->executeStatement("UPDATE cash_register SET terminal_mode = NULL WHERE id = ?", [$this->register]);
        $this->db->executeStatement('UPDATE entreprise SET pos_tap_to_pay_allowed = false');
    }

    private function configureSimulatedReader(): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $register = $provider->getEntityManager()->find(CashRegister::class, $this->register);
        $provider->getEntityManager()->refresh($register);
        static::getContainer()->get(PosTerminalPaymentService::class)->createSimulatedReader($register);
    }

    private function posTerminal(): string
    {
        $saved = $this->token;
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $key = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette cartes terminal', 'sendEmail' => false]), 201)['key'];
        $this->token = $saved;
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/app/activate', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['key' => $key, 'app' => 'pos']));

        return json_decode($this->client->getResponse()->getContent(), true)['terminal']['token'];
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function json(Response $response, int $expected = 200): array
    {
        $this->assertSame($expected, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
            'HTTP_X_POS_TERMINAL' => $this->terminal, 'HTTP_IDEMPOTENCY_KEY' => $this->key,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): User
    {
        $email = 'carte-terminal-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Carte')->setLastname('Terminal')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $saved = $this->key;
        $this->key = null;
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $email, 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
        $this->key = $saved;

        return $user;
    }
}
