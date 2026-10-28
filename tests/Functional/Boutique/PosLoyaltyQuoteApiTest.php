<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\CashRegister;
use App\Entity\User;
use App\Services\PosTerminalService\PosTerminalPaymentService;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeTerminalGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Rachat de points (F8) connu avant l'encaissement : POST /api/pos/quote, /api/pos/exchanges/quote et la part carte de
 * POST /api/pos/terminal/payments acceptent customerId et loyalty {redeemPoints} ; remise « Récompense fidélité » et mêmes refus que
 * la vente ; aucune écriture pendant un devis ; sans ces clés, réponses identiques. Échange avec rachat : crédit calculé après récompense.
 */
class PosLoyaltyQuoteApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
    private ?string $approval = null;
    private int $terminalId = 0;
    private int $register;
    private int $cap; // 29,00 → 33,34 TTC, 33,35 en espèces

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeTerminalGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-fidelite@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_header_required = false,
            pos_pay_cash = true, pos_pay_declared_card = true, pos_pay_paypal = true, pos_pay_multi = true, pos_declared_card_blocked_with_terminal = false, pos_return_max_days = NULL, pos_return_without_receipt = false, pos_return_cash_for_card_sale = true, pos_approval_return_mode = 'refund',
            pos_discount_enabled = true, pos_discount_ticket_enabled = true, pos_discount_reason_required = true, pos_discount_cashier_max_amount = 2000, pos_discount_cashier_max_percent_bp = 1000, pos_custom_item_enabled = false, pos_price_override_enabled = false, pos_offline_enabled = false,
            pos_gift_card_enabled = true, pos_store_credit_enabled = false, pos_receipt_email_enabled = true,
            pos_customers_enabled = true, pos_customer_link_enabled = true, pos_loyalty_enabled = true, pos_loyalty_points_per_dollar = 1, pos_loyalty_value_per100 = 100, pos_loyalty_min_redeem = 500, pos_loyalty_redeem_step = 100, pos_loyalty_expiry_months = NULL, pos_consent_text_version = '2026-10', pos_customer_search_per_minute = 30");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement("UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = NULL");
        $this->db->executeStatement('UPDATE product SET is_pos = true');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = $this->variant('DEMO-CASQUETTE', 2900);
    }

    public function testAnExchangeWithRedeemedPointsAppliesTheCreditAfterTheReward(): void
    {
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 20000]);
        $customer = $this->customer(600);
        $items = [['productVariantId' => $this->cap, 'quantity' => 1]];
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 33.35]], 'cashRegisterId' => $this->register]), 201);
        $line = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale['orderId']))['lines'][0]['orderItemId'];
        // Vente d'échange : une casquette (33,34) avec 500 points (5,00) → 24,00 + taxes ; le retour (33,34) couvre la vente
        $rewarded = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'ticketDiscount' => ['type' => 'amount', 'value' => 500, 'reasonText' => 'fidélité'], 'cashRegisterId' => $this->register]));
        $ex = $this->request('POST', '/api/pos/exchanges', ['return' => ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $line, 'quantity' => 1]], 'reasonCode' => 'autre', 'refund' => ['method' => 'cash']],
            'sale' => ['items' => $items, 'paymentMethods' => [], 'customerId' => $customer, 'loyalty' => ['redeemPoints' => 500]], 'cashRegisterId' => $this->register]);
        $this->assertSame(201, $ex->getStatusCode(), $ex->getContent());
        $body = json_decode($ex->getContent(), true);
        $this->assertSame((int) $rewarded['total'], (int) round((float) $this->db->fetchOne('SELECT total_amount FROM "order" WHERE id = ?', [$body['sale']['orderId']])), 'vente d\'échange remisée de 5,00');
        $this->assertSame((int) $rewarded['total'], (int) $body['return']['creditApplied'], 'crédit = total de la vente APRÈS récompense');
        $this->assertSame(3334 - (int) $rewarded['total'], (int) $body['return']['refund']['amount'], 'le client récupère la différence');
    }

    public function testTheCounterQuoteKnowsTheLoyaltyRewardWithTheSaleRefusalsAndWritesNothing(): void
    {
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 20000]);
        $this->loginAs(['ROLE_USER_POS']);
        $customer = $this->customer(600);
        $items = [['productVariantId' => $this->cap, 'quantity' => 1]];
        $quote = fn (array $extra) => $this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register] + $extra);
        $error = fn (Response $r) => [$r->getStatusCode(), json_decode($r->getContent(), true)['error'] ?? null];
        $writes = fn () => [(int) $this->db->fetchOne('SELECT loyalty_points FROM pos_customer WHERE id = ?', [$customer]), (int) $this->db->fetchOne('SELECT COUNT(*) FROM pos_loyalty_transaction WHERE customer_id = ?', [$customer]),
            (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type LIKE 'LOYALTY%'")];
        $before = $writes();

        // Sans les clés : réponse identique (aucune clé loyalty) ; client seul : réponse identique aussi
        $plain = $this->json($quote([]));
        $this->assertArrayNotHasKey('loyalty', $plain);
        $this->assertSame($plain, $this->json($quote(['customerId' => $customer])), 'client sans rachat : devis inchangé');
        $this->assertSame($plain, $this->json($quote(['customerId' => $customer, 'loyalty' => ['redeemPoints' => 0]])));

        // 500 points = 5,00 : remise de ticket « Récompense fidélité », exactement la remise de la vente
        $rewarded = $this->json($quote(['customerId' => $customer, 'loyalty' => ['redeemPoints' => 500]]));
        $asTicket = $this->json($quote(['ticketDiscount' => ['type' => 'amount', 'value' => 500, 'reasonText' => 'Récompense fidélité (500 points)']]));
        $this->assertSame([$asTicket['total'], $asTicket['cashTotal'], $asTicket['discounts']], [$rewarded['total'], $rewarded['cashTotal'], $rewarded['discounts']]);
        $this->assertSame(['customerId' => $customer, 'redeemPoints' => 500, 'amount' => 500, 'balance' => 600, 'balanceAfter' => 100], $rewarded['loyalty']);
        $this->assertLessThan($plain['total'], $rewarded['total']);

        // Mêmes refus que la vente
        $this->assertSame([422, 'LOYALTY_CUSTOMER_REQUIRED'], $error($quote(['loyalty' => ['redeemPoints' => 500]])));
        $this->assertSame([409, 'LOYALTY_TICKET_DISCOUNT_CONFLICT'], $error($quote(['customerId' => $customer, 'loyalty' => ['redeemPoints' => 500], 'ticketDiscount' => ['type' => 'amount', 'value' => 100, 'reasonCode' => 'autre']])));
        $this->assertSame([422, 'LOYALTY_POINTS_INVALID'], $error($quote(['customerId' => $customer, 'loyalty' => ['redeemPoints' => 50]])));
        $this->assertSame([409, 'LOYALTY_INSUFFICIENT'], $error($quote(['customerId' => $customer, 'loyalty' => ['redeemPoints' => 10000]])));
        $this->assertSame([404, 'CUSTOMER_NOT_FOUND'], $error($quote(['customerId' => 999999, 'loyalty' => ['redeemPoints' => 500]])));
        $noConsent = $this->json($this->request('POST', '/api/pos/customers', ['firstName' => 'Sans', 'lastName' => 'Consentement', 'phone' => '438555' . random_int(1000, 9999)]), 201)['id'];
        $this->db->executeStatement('UPDATE pos_customer SET loyalty_points = 600 WHERE id = ?', [$noConsent]);
        $this->assertSame([409, 'LOYALTY_CONSENT_REQUIRED'], $error($quote(['customerId' => $noConsent, 'loyalty' => ['redeemPoints' => 500]])));
        $this->db->executeStatement('UPDATE entreprise SET pos_loyalty_enabled = false');
        $this->assertSame([409, 'LOYALTY_DISABLED'], $error($quote(['customerId' => $customer, 'loyalty' => ['redeemPoints' => 500]])));
        $this->db->executeStatement('UPDATE entreprise SET pos_loyalty_enabled = true');
        // Points expirés par inactivité : comptent pour 0 dans le devis, sans écrire l'expiration
        $this->db->executeStatement("UPDATE entreprise SET pos_loyalty_expiry_months = 12");
        $this->db->executeStatement("UPDATE pos_customer SET loyalty_last_activity_at = NOW() - INTERVAL '2 years' WHERE id = ?", [$customer]);
        $expired = $quote(['customerId' => $customer, 'loyalty' => ['redeemPoints' => 500]]);
        $this->assertSame([409, 'LOYALTY_INSUFFICIENT', 0], [$expired->getStatusCode(), json_decode($expired->getContent(), true)['error'], json_decode($expired->getContent(), true)['balance']]);
        $this->db->executeStatement("UPDATE entreprise SET pos_loyalty_expiry_months = NULL");
        $this->db->executeStatement('UPDATE pos_customer SET loyalty_last_activity_at = NOW() WHERE id = ?', [$customer]);
        $this->assertSame($before, $writes(), 'aucun devis n\'écrit : solde, mouvements de points et journal inchangés');

        // La vente avec les mêmes points coûte exactement le devis
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'cashRegisterId' => $this->register, 'customerId' => $customer, 'loyalty' => ['redeemPoints' => 500],
            'paymentMethods' => [['type' => 2, 'amount' => $rewarded['cashTotal'] / 100]]]), 201);
        $this->assertSame((int) $rewarded['total'], (int) round((float) $this->db->fetchOne('SELECT total_amount FROM "order" WHERE id = ?', [$sale['orderId']])));
        $this->assertSame(100 + $sale['customer']['loyalty']['earned'], $sale['customer']['loyalty']['balance'], 'points débités par la vente seulement');
    }

    public function testTheExchangeQuoteAccountsForThePointsTheReturnTakesBack(): void
    {
        // Relecture du 28/10/2026 : la vente d'origine (même client) a fait gagner 29 points ; le retour les reprend AVANT la vente d'échange
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 20000]);
        $customer = $this->customer(0);
        $items = [['productVariantId' => $this->cap, 'quantity' => 1]];
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 33.35]], 'cashRegisterId' => $this->register, 'customerId' => $customer]), 201);
        $this->assertSame(29, $sale['customer']['loyalty']['earned']);
        $line = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale['orderId']))['lines'][0]['orderItemId'];
        $return = ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $line, 'quantity' => 1]], 'reasonCode' => 'autre', 'refund' => ['method' => 'cash']];
        $error = fn (Response $r) => [$r->getStatusCode(), json_decode($r->getContent(), true)['error'] ?? null, json_decode($r->getContent(), true)['balance'] ?? null];

        // Solde 520 : 500 points semblaient disponibles, mais le retour en reprend 29 → le devis refuse comme l'échange refuserait
        $this->db->executeStatement('UPDATE pos_customer SET loyalty_points = 520 WHERE id = ?', [$customer]);
        $quote = ['return' => $return, 'sale' => ['items' => $items, 'loyalty' => ['redeemPoints' => 500], 'cashRegisterId' => $this->register]];
        $this->assertSame([409, 'LOYALTY_INSUFFICIENT', 491], $error($this->request('POST', '/api/pos/exchanges/quote', $quote)), 'solde contrôlé après la reprise de 29 points');
        $this->assertSame([409, 'LOYALTY_INSUFFICIENT', 491], $error($this->request('POST', '/api/pos/exchanges', $quote + ['sale' => $quote['sale'] + ['paymentMethods' => []]])), 'l\'échange refuse de la même façon, avant toute écriture');
        $this->assertSame(520, (int) $this->db->fetchOne('SELECT loyalty_points FROM pos_customer WHERE id = ?', [$customer]), 'rien n\'a été écrit');

        // Solde 600 : devis et échange d'accord ; balanceAfter exact
        $this->db->executeStatement('UPDATE pos_customer SET loyalty_points = 600 WHERE id = ?', [$customer]);
        $q = $this->json($this->request('POST', '/api/pos/exchanges/quote', $quote));
        $this->assertSame(['customerId' => $customer, 'redeemPoints' => 500, 'amount' => 500, 'balance' => 571, 'balanceAfter' => 71, 'returnTakesBack' => 29], $q['sale']['loyalty']);
        $ex = $this->json($this->request('POST', '/api/pos/exchanges', ['return' => $return, 'sale' => ['items' => $items, 'paymentMethods' => [], 'loyalty' => ['redeemPoints' => 500]], 'cashRegisterId' => $this->register]), 201);
        $this->assertSame(71 + $ex['sale']['customer']['loyalty']['earned'], $ex['sale']['customer']['loyalty']['balance'], 'solde final = balanceAfter du devis + points gagnés par la vente d\'échange');
    }

    public function testTheExchangeQuoteKnowsTheRewardAndTheCustomerOfTheOriginalSale(): void
    {
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 20000]);
        $customer = $this->customer(600);
        $items = [['productVariantId' => $this->cap, 'quantity' => 1]];
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 33.35]], 'cashRegisterId' => $this->register, 'customerId' => $customer]), 201);
        $this->db->executeStatement('UPDATE pos_customer SET loyalty_points = 600 WHERE id = ?', [$customer]);
        $line = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale['orderId']))['lines'][0]['orderItemId'];
        $return = ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $line, 'quantity' => 1]], 'reasonCode' => 'autre'];
        $rewarded = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'ticketDiscount' => ['type' => 'amount', 'value' => 500, 'reasonText' => 'Récompense fidélité (500 points)'], 'cashRegisterId' => $this->register]));

        $plain = $this->json($this->request('POST', '/api/pos/exchanges/quote', ['return' => $return, 'sale' => ['items' => $items, 'cashRegisterId' => $this->register]]));
        $this->assertArrayNotHasKey('loyalty', $plain['sale'], 'sans les clés : réponse identique');
        $this->assertSame([3334, 3334, 0, 0], [$plain['sale']['total'], $plain['creditApplied'], $plain['amountDue'], $plain['refundDue']]);
        // Client repris de la vente d'origine (sans sale.customerId) ; crédit après récompense ; le client récupère la différence
        $q = $this->json($this->request('POST', '/api/pos/exchanges/quote', ['return' => $return, 'sale' => ['items' => $items, 'loyalty' => ['redeemPoints' => 500], 'cashRegisterId' => $this->register]]));
        $this->assertSame([(int) $rewarded['total'], (int) $rewarded['total'], 0, 3334 - (int) $rewarded['total']], [$q['sale']['total'], $q['creditApplied'], $q['amountDue'], $q['refundDue']]);
        // La vente d'origine a fait gagner 29 points au même client : le retour les reprend avant la vente d'échange (28/10/2026)
        $this->assertSame(['customerId' => $customer, 'redeemPoints' => 500, 'amount' => 500, 'balance' => 571, 'balanceAfter' => 71, 'returnTakesBack' => 29], $q['sale']['loyalty']);
        $this->assertSame([409, 'LOYALTY_INSUFFICIENT'], [($r = $this->request('POST', '/api/pos/exchanges/quote', ['return' => $return, 'sale' => ['items' => $items, 'loyalty' => ['redeemPoints' => 10000]]]))->getStatusCode(), json_decode($r->getContent(), true)['error']]);
        $this->assertSame(600, (int) $this->db->fetchOne('SELECT loyalty_points FROM pos_customer WHERE id = ?', [$customer]), 'aucune écriture');
    }

    public function testTheTerminalCardShareIsComputedAfterTheReward(): void
    {
        $this->db->executeStatement("UPDATE entreprise SET pos_terminal_interac = NULL, pos_terminal_fee_card_bp = 100, pos_terminal_fee_interac_bp = 150, pos_tap_to_pay_allowed = false, pos_bluetooth_reader_allowed = false, pos_coupon_enabled = false");
        $this->db->executeStatement('UPDATE cash_register SET stripe_reader_label = NULL, stripe_reader_simulated = false, terminal_mode = NULL');
        $this->db->executeStatement('UPDATE stripe_config SET terminal_location_id = NULL');
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $register = $provider->getEntityManager()->find(CashRegister::class, $this->register);
        $provider->getEntityManager()->refresh($register);
        static::getContainer()->get(PosTerminalPaymentService::class)->createSimulatedReader($register);
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->terminal = $this->posTerminal();
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 20000]);
        $customer = $this->customer(600);
        $items = [['productVariantId' => $this->cap, 'quantity' => 1]];
        $error = fn (Response $r) => [$r->getStatusCode(), json_decode($r->getContent(), true)['error'] ?? null];
        $rewarded = (int) $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'customerId' => $customer, 'loyalty' => ['redeemPoints' => 500], 'cashRegisterId' => $this->register]))['total'];

        // Sans les clés : la part carte attendue reste le total plein ; avec : le total après récompense
        $mismatch = $this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $rewarded, 'items' => $items]);
        $this->assertSame([422, 'AMOUNT_MISMATCH', 3334], [$mismatch->getStatusCode(), json_decode($mismatch->getContent(), true)['error'], json_decode($mismatch->getContent(), true)['expectedCard']]);
        $this->assertSame([422, 'LOYALTY_CUSTOMER_REQUIRED'], $error($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $rewarded, 'items' => $items, 'loyalty' => ['redeemPoints' => 500]])));
        $this->assertSame([409, 'LOYALTY_INSUFFICIENT'], $error($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $rewarded, 'items' => $items, 'customerId' => $customer, 'loyalty' => ['redeemPoints' => 10000]])));
        $this->assertSame([409, 'TERMINAL_PAYMENT_PURPOSE_MISMATCH'], $error($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => 5000, 'giftCardAmount' => 5000, 'customerId' => $customer, 'loyalty' => ['redeemPoints' => 500]])));
        $tp = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $rewarded, 'items' => $items, 'customerId' => $customer, 'loyalty' => ['redeemPoints' => 500]]), 201);
        $this->assertSame(600, (int) $this->db->fetchOne('SELECT loyalty_points FROM pos_customer WHERE id = ?', [$customer]), 'aucun point débité au terminal');
        $this->json($this->request('POST', '/api/pos/terminal/payments/' . $tp['paymentId'] . '/simulate', ['type' => 'card_present']));
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'cashRegisterId' => $this->register, 'customerId' => $customer, 'loyalty' => ['redeemPoints' => 500],
            'paymentMethods' => [['type' => 1, 'amount' => $rewarded / 100, 'terminalPaymentId' => $tp['paymentId']]]]), 201);
        $this->assertSame([500, 100 + $sale['customer']['loyalty']['earned']], [$sale['customer']['loyalty']['redeemed'], $sale['customer']['loyalty']['balance']], 'la vente débite les points');
    }

    /** Client de caisse consentant avec $points points */
    private function customer(int $points): int
    {
        $c = $this->json($this->request('POST', '/api/pos/customers', ['firstName' => 'Fidèle', 'lastName' => 'Client', 'phone' => '514555' . random_int(1000, 9999), 'consents' => ['loyalty' => true]]), 201);
        $this->db->executeStatement('UPDATE pos_customer SET loyalty_points = ?, loyalty_last_activity_at = NOW() WHERE id = ?', [$points, $c['id']]);

        return (int) $c['id'];
    }

    // ---------------------------------------------------------------- aides

    private function error(Response $r): array
    {
        return [$r->getStatusCode(), json_decode($r->getContent(), true)['error'] ?? null];
    }

    private function variant(string $code, int $price): int
    {
        $id = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = ? ORDER BY v.id LIMIT 1", [$code]);
        $this->db->executeStatement('UPDATE product SET price = ?, special_price = NULL WHERE code = ?', [$price, $code]);
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500, price = NULL WHERE id = ?', [$id]);

        return $id;
    }

    private function posTerminal(): string
    {
        $saved = $this->token;
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $key = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette clients', 'sendEmail' => false]), 201)['key'];
        $this->token = $saved;
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/app/activate', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['key' => $key, 'app' => 'pos']));
        $terminal = json_decode($this->client->getResponse()->getContent(), true)['terminal'];
        $this->terminalId = (int) $terminal['id'];

        return $terminal['token'];
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

    private function request(string $method, string $path, ?array $body = null, array $extraHeaders = []): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
            'HTTP_X_POS_TERMINAL' => $this->terminal, 'HTTP_X_POS_APPROVAL' => $this->approval,
        ]) + $extraHeaders, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function user(array $roles): User
    {
        $email = 'client-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Client')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }

    private function loginAs(array $roles): User
    {
        $user = $this->user($roles);
        $a = $this->approval;
        $this->approval = null;
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $user->getEmail(), 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
        $this->approval = $a;

        return $user;
    }
}
