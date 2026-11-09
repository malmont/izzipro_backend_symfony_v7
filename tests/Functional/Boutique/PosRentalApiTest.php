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
 * Location au comptoir avec caution (09/11/2026) : catalogue des locations (en ligne, hors pack hors ligne), devis et vente au tarif de
 * location avec réservation confirmée et caution en espèces (tiroir), ticket et rapport X, caution restituée avec retenue motivée (validation
 * d'un responsable au seuil « remboursement »), annulation qui libère le créneau et rend la caution, retour partiel qui libère, matériel
 * rendu sans caution, caution par carte débitée sur le lecteur simulé puis remboursée. Une vente sans location ne change pas.
 */
class PosRentalApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
    private ?string $approval = null;
    private int $register;
    private int $ponton;
    private int $pontonProduct;
    private int $cap;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeTerminalGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-location-comptoir@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = 0, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL,
            pos_pay_cash = true, pos_pay_declared_card = true, pos_pay_paypal = true, pos_pay_multi = true, pos_declared_card_blocked_with_terminal = false, pos_return_max_days = NULL, pos_return_without_receipt = false, pos_offline_enabled = false,
            pos_terminal_interac = NULL, pos_terminal_fee_card_bp = 100, pos_terminal_fee_interac_bp = 150, pos_tap_to_pay_allowed = false");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, stripe_reader_label = NULL, stripe_reader_simulated = false, terminal_mode = NULL, receipt_prefix = NULL');
        $this->db->executeStatement('UPDATE stripe_config SET terminal_location_id = NULL');
        $this->db->executeStatement('DELETE FROM pos_rental_deposit');
        $this->db->executeStatement("DELETE FROM booking WHERE product_id IN (SELECT id FROM product WHERE code = 'DEMO-PONTON')");
        $this->db->executeStatement('UPDATE product SET is_pos = true');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->pontonProduct = (int) $this->db->fetchOne("SELECT id FROM product WHERE code = 'DEMO-PONTON'");
        $this->ponton = (int) $this->db->fetchOne('SELECT id FROM product_variant WHERE product_id = ? ORDER BY id LIMIT 1', [$this->pontonProduct]);
        $this->cap = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement("UPDATE product SET price = 2900, special_price = NULL WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500, price = NULL WHERE id = ?', [$this->cap]);
    }

    public function testCounterRentalWithCashDepositReturnedWithRetentionCancellationAndPartialReturn(): void
    {
        $manager = $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 50000]);
        $cashier = $this->loginAs(['ROLE_USER_POS']);
        $error = fn (Response $r) => [$r->getStatusCode(), json_decode($r->getContent(), true)['error'] ?? null];

        // Catalogue des locations : le Ponton y est avec son bloc rental ; jamais dans le pack hors ligne
        $rentals = $this->json($this->request('GET', '/api/pos/rentals'));
        $ponton = array_values(array_filter($rentals, fn ($r) => $r['code'] === 'DEMO-PONTON'))[0] ?? null;
        $this->assertNotNull($ponton, json_encode(array_column($rentals, 'code')));
        $this->assertSame([150000, 'days', 2], [$ponton['rental']['deposit'], $ponton['rental']['granularity'], $ponton['rental']['minDaysStandard']]);
        $this->assertNotEmpty($ponton['rental']['rates']);
        $this->assertSame($this->ponton, $ponton['variants'][0]['id']);
        $pack = $this->json($this->request('GET', '/api/pos/offline-pack?cashRegisterId=' . $this->register));
        $this->assertNotContains('DEMO-PONTON', array_column($pack['products'], 'code'), 'les locations restent hors du pack hors ligne');

        // Devis de caisse d'une location de 3 jours : tarif jour × 3, caution à part
        $d = (new \DateTimeImmutable('+10 days'))->setTime(0, 0);
        $start = $d->format('Y-m-d') . 'T09:00:00';
        $end = $d->modify('+2 days')->format('Y-m-d') . 'T19:00:00'; // 58 h → 3 jours facturés (tranches de 24 h arrondies au-dessus)
        $booking = ['start' => $start, 'end' => $end];
        $items = [['productVariantId' => $this->ponton, 'quantity' => 1, 'booking' => $booking]];
        $quote = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register]));
        $this->assertSame(['day', 3, 150000], [$quote['lines'][0]['booking']['durationType'], $quote['lines'][0]['booking']['units'], $quote['deposit']], 'tarif de location, pas le prix de vente');
        $this->assertSame($quote['lines'][0]['booking']['rate'] * 3, $quote['lines'][0]['unitPrice']);
        $this->assertGreaterThan(0, $quote['cashTotal']);
        $this->assertSame(200, $this->request('GET', '/api/booking/check/' . $this->pontonProduct . '?' . http_build_query(['start' => $start, 'end' => $end, 'quantity' => 1]))->getStatusCode());

        // Caution refusée sans location, invalide, puis vente avec caution en espèces
        $this->assertSame([422, 'DEPOSIT_WITHOUT_RENTAL'], $error($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 33.34]], 'cashRegisterId' => $this->register, 'deposit' => ['amount' => 1000, 'method' => 'cash']])));
        $this->assertSame([422, 'DEPOSIT_INVALID'], $error($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => $quote['cashTotal'] / 100]], 'cashRegisterId' => $this->register, 'deposit' => ['amount' => 150000, 'method' => 'cheque']])));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM pos_rental_deposit'), 'rien écrit');
        $cashBefore = (int) $this->db->fetchOne('SELECT amount_total FROM caisse WHERE is_open');
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => $quote['cashTotal'] / 100]], 'cashRegisterId' => $this->register, 'deposit' => ['amount' => 150000, 'method' => 'cash']]), 201);
        $orderId = (int) $sale['orderId'];
        $this->assertSame($quote['total'], (int) round($sale['totalAmount'] * 100), 'la vente vaut le devis de location');
        $this->assertSame(1, count($sale['rental']['lines']));
        $this->assertSame(['held', 150000, 'cash'], [$sale['rental']['deposits'][0]['status'], $sale['rental']['deposits'][0]['amount'], $sale['rental']['deposits'][0]['method']]);
        $depositId = (int) $sale['rental']['deposits'][0]['id'];
        $bookingRow = $this->db->fetchAssociative('SELECT b.id, b.status, b.quantity FROM booking b JOIN order_items i ON i.id = b.order_item_id WHERE i.order_associated_id = ?', [$orderId]);
        $this->assertSame('CONFIRMED', $bookingRow['status'], 'réservation confirmée par la vente');
        $this->assertSame($cashBefore + $quote['cashTotal'] + 150000, (int) $this->db->fetchOne('SELECT amount_total FROM caisse WHERE is_open'), 'la caution en espèces entre dans le tiroir');
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM transaction_caisse WHERE transaction_type_id = 11 AND order_caisse_id = ?', [$orderId]), 'mouvement « Caution encaissée »');
        $this->assertSame(409, $this->request('GET', '/api/booking/check/' . $this->pontonProduct . '?' . http_build_query(['start' => $start, 'end' => $end, 'quantity' => 2]))->getStatusCode(), 'stock 2 : une unité prise');
        $this->assertSame([409, 'DEPOSIT_ALREADY_HELD'], $error($this->request('POST', '/api/pos/rentals/deposits', ['orderId' => $orderId, 'amount' => 1000, 'method' => 'cash'])));

        // Rapport X : bloc rentalDeposits, expectedCash tient compte de la caution ; locations en cours ; liste des cautions
        $x = $this->json($this->request('GET', '/api/caisse/report/x?cashRegisterId=' . $this->register));
        $this->assertSame([1, 150000, 150000], [$x['rentalDeposits']['collected']['count'], $x['rentalDeposits']['collected']['total'], $x['rentalDeposits']['collected']['cash']]);
        $this->assertSame([1, 150000], [$x['rentalDeposits']['outstanding']['count'], $x['rentalDeposits']['outstanding']['total']]);
        $this->assertSame($x['openingAmount'] + 50000 + $quote['cashTotal'] + 150000, $x['expectedCash'], 'solde reporté + fond donné à l\'ouverture + vente espèces + caution');
        $this->assertSame(1, $x['sales']['count'], 'la caution n\'est pas une vente');
        $ongoing = $this->json($this->request('GET', '/api/pos/rentals/ongoing'));
        $this->assertSame([$orderId, 'CONFIRMED', $depositId], [$ongoing[0]['orderId'], $ongoing[0]['status'], $ongoing[0]['deposit']['id']]);
        $held = $this->json($this->request('GET', '/api/pos/rentals/deposits?status=held'));
        $this->assertSame([$depositId, $sale['reference']], [$held[0]['id'], $held[0]['reference']]);
        $this->assertSame('held', $this->json($this->request('GET', '/api/pos/rentals/deposits/' . $depositId))['status']);
        $receipt = $this->json($this->request('GET', '/api/pos/receipts?orderId=' . $orderId));
        $this->assertArrayHasKey('rental', $receipt, 'réimpression : bloc rental');

        // Restitution avec retenue : motif requis, validation d'un responsable (seuil remboursement = 0 : toujours) ; le caissier seul est refusé
        $this->assertSame([422, 'RETENTION_REASON_REQUIRED'], $error($this->request('POST', '/api/pos/rentals/deposits/' . $depositId . '/return', ['retainedAmount' => 2000])));
        $need = $this->request('POST', '/api/pos/rentals/deposits/' . $depositId . '/return', ['retainedAmount' => 2000, 'reason' => 'Rayure sur la coque']);
        $this->assertSame([403, 'APPROVAL_REQUIRED'], $error($need));
        $this->assertSame('refund', json_decode($need->getContent(), true)['action']);
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $settled = $this->json($this->request('POST', '/api/pos/rentals/deposits/' . $depositId . '/return', ['retainedAmount' => 2000, 'reason' => 'Rayure sur la coque']));
        $this->assertSame(['retained', 148000, 2000, 'cash', 'Rayure sur la coque'], [$settled['status'], $settled['returnedAmount'], $settled['retainedAmount'], $settled['returnMethod'], $settled['retainedReason']]);
        $this->assertSame(['deposit_return', 'cash_returned'], [$settled['receipt']['kind'], $settled['receipt']['action']]);
        $this->assertSame($cashBefore + $quote['cashTotal'] + 2000, (int) $this->db->fetchOne('SELECT amount_total FROM caisse WHERE is_open'), 'espèces rendues, retenue gardée');
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM transaction_caisse WHERE transaction_type_id = 12 AND order_caisse_id = ?', [$orderId]));
        $this->assertSame(['RETURNED', true], [$this->db->fetchOne('SELECT status FROM booking WHERE id = ?', [$bookingRow['id']]), (bool) $this->db->fetchOne('SELECT is_finalized FROM booking WHERE id = ?', [$bookingRow['id']])], 'matériel rendu');
        $this->assertSame([409, 'DEPOSIT_NOT_HELD'], $error($this->request('POST', '/api/pos/rentals/deposits/' . $depositId . '/return', [])));
        $x = $this->json($this->request('GET', '/api/caisse/report/x?cashRegisterId=' . $this->register));
        $this->assertSame([[1, 148000, 148000], [1, 2000], 0], [[$x['rentalDeposits']['returned']['count'], $x['rentalDeposits']['returned']['total'], $x['rentalDeposits']['returned']['cash']], [$x['rentalDeposits']['retained']['count'], $x['rentalDeposits']['retained']['total']], $x['rentalDeposits']['outstanding']['count']]);
        $this->assertSame($x['openingAmount'] + 50000 + $quote['cashTotal'] + 2000, $x['expectedCash']);
        $this->assertSame([], $this->json($this->request('GET', '/api/pos/rentals/ongoing')), 'plus de location en cours');
        $this->assertGreaterThanOrEqual(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type IN ('RENTAL_DEPOSIT_COLLECTED', 'RENTAL_DEPOSIT_RETURNED', 'RENTAL_BOOKING_CONFIRMED')"), 'journal de caisse');

        // Seconde vente (caution en espèces) puis annulation : créneau libéré, caution rendue en entier dans le même geste
        $start2 = $d->modify('+10 days')->format('Y-m-d') . 'T09:00:00';
        $end2 = $d->modify('+12 days')->format('Y-m-d') . 'T19:00:00';
        $items2 = [['productVariantId' => $this->ponton, 'quantity' => 1, 'booking' => ['start' => $start2, 'end' => $end2]]];
        $q2 = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items2, 'cashRegisterId' => $this->register]));
        $sale2 = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items2, 'paymentMethods' => [['type' => 2, 'amount' => $q2['cashTotal'] / 100]], 'cashRegisterId' => $this->register, 'deposit' => ['amount' => 150000, 'method' => 'cash']]), 201);
        $before = (int) $this->db->fetchOne('SELECT amount_total FROM caisse WHERE is_open');
        $cancel = $this->json($this->request('POST', '/api/order/cancel/' . $sale2['orderId'], ['paymentMethod' => 2, 'cashRegisterId' => $this->register]));
        $this->assertSame(['returned', 150000, 'cash_returned'], [$cancel['rentalDeposit']['status'], $cancel['rentalDeposit']['returnedAmount'], $cancel['rentalDeposit']['action']]);
        $this->assertSame('CANCELLED', $this->db->fetchOne('SELECT b.status FROM booking b JOIN order_items i ON i.id = b.order_item_id WHERE i.order_associated_id = ?', [$sale2['orderId']]), 'créneau libéré');
        $this->assertSame($before - $q2['cashTotal'] - 150000, (int) $this->db->fetchOne('SELECT amount_total FROM caisse WHERE is_open'), 'vente remboursée et caution rendue');
        $this->assertSame(200, $this->request('GET', '/api/booking/check/' . $this->pontonProduct . '?' . http_build_query(['start' => $start2, 'end' => $end2, 'quantity' => 2]))->getStatusCode(), 'les deux unités sont libres');

        // Troisième vente : deux unités, sans caution ; retour partiel d'une unité réduit la réservation ; matériel rendu sans caution
        $items3 = [['productVariantId' => $this->ponton, 'quantity' => 2, 'booking' => ['start' => $start2, 'end' => $end2]]];
        $q3 = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items3, 'cashRegisterId' => $this->register]));
        $sale3 = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items3, 'paymentMethods' => [['type' => 2, 'amount' => $q3['cashTotal'] / 100]], 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([], $sale3['rental']['deposits']);
        $itemId = (int) $this->db->fetchOne('SELECT id FROM order_items WHERE order_associated_id = ?', [$sale3['orderId']]);
        $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale3['orderId'], 'lines' => [['orderItemId' => $itemId, 'quantity' => 1]], 'reasonText' => 'Une unité rendue', 'cashRegisterId' => $this->register, 'refund' => ['method' => 'cash']]), 201);
        $b3 = $this->db->fetchAssociative('SELECT b.id, b.status, b.quantity FROM booking b JOIN order_items i ON i.id = b.order_item_id WHERE i.order_associated_id = ?', [$sale3['orderId']]);
        $this->assertSame(['CONFIRMED', 1], [$b3['status'], (int) $b3['quantity']], 'une unité rendue : réservation réduite');
        $returned = $this->json($this->request('POST', '/api/pos/rentals/bookings/' . $b3['id'] . '/return', ['cashRegisterId' => $this->register]));
        $this->assertSame(['RETURNED', true], [$returned['status'], $returned['finalized']]);
        $this->assertSame([409, 'BOOKING_ALREADY_RETURNED'], $error($this->request('POST', '/api/pos/rentals/bookings/' . $b3['id'] . '/return', [])));

        // Vente ordinaire : aucune trace de location sur le ticket
        $plain = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 33.35]], 'cashRegisterId' => $this->register]), 201);
        $this->assertArrayNotHasKey('rental', $plain);
    }

    public function testCardDepositIsDebitedOnTheReaderAndRefundedAtReturn(): void
    {
        $this->configureSimulatedReader();
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->terminal = $this->posTerminal();
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $drawer = (int) $this->db->fetchOne('SELECT amount_total FROM caisse WHERE is_open'); // solde reporté + fond
        $error = fn (Response $r) => [$r->getStatusCode(), json_decode($r->getContent(), true)['error'] ?? null];
        $d = (new \DateTimeImmutable('+20 days'))->setTime(0, 0);
        $items = [['productVariantId' => $this->ponton, 'quantity' => 1, 'booking' => ['start' => $d->format('Y-m-d') . 'T09:00:00', 'end' => $d->modify('+2 days')->format('Y-m-d') . 'T19:00:00']]];
        $quote = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register]));

        // Paiement de la caution sur le lecteur : depositAmount = amount, destination rental_deposit
        $this->assertSame([422, 'VALIDATION_FAILED'], $error($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => 150000, 'depositAmount' => 150000, 'items' => $items])));
        $this->assertSame([422, 'VALIDATION_FAILED'], $error($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => 100000, 'depositAmount' => 150000])));
        $tp = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => 150000, 'depositAmount' => 150000]), 201);
        $this->assertSame(['rental_deposit', 150000], [$tp['purpose'], $tp['amount']]);
        $this->json($this->request('POST', '/api/pos/terminal/payments/' . $tp['paymentId'] . '/simulate', ['type' => 'card_present', 'cardNumber' => '4242424242424242']));
        // Ce paiement ne règle pas la vente elle-même
        $this->assertSame([409, 'TERMINAL_PAYMENT_PURPOSE_MISMATCH'], $error($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 1, 'amount' => $quote['total'] / 100, 'terminalPaymentId' => $tp['paymentId']]], 'cashRegisterId' => $this->register])));
        $this->assertSame([422, 'DEPOSIT_INVALID'], $error($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => $quote['cashTotal'] / 100]], 'cashRegisterId' => $this->register, 'deposit' => ['amount' => 150000, 'method' => 'card']])), 'carte sans terminalPaymentId');

        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => $quote['cashTotal'] / 100]], 'cashRegisterId' => $this->register, 'deposit' => ['amount' => 150000, 'method' => 'card', 'terminalPaymentId' => $tp['paymentId']]]), 201);
        $deposit = $sale['rental']['deposits'][0];
        $this->assertSame(['held', 'card', $tp['paymentId']], [$deposit['status'], $deposit['method'], $deposit['terminalPaymentId']]);
        $this->assertSame('succeeded', FakeTerminalGateway::$intents[$tp['stripePaymentIntentId']]['status'], 'caution capturée');
        $this->assertSame($drawer + $quote['cashTotal'], (int) $this->db->fetchOne('SELECT amount_total FROM caisse WHERE is_open'), 'rien dans le tiroir pour une caution par carte');
        $x = $this->json($this->request('GET', '/api/caisse/report/x?cashRegisterId=' . $this->register));
        $this->assertSame([150000, 0], [$x['rentalDeposits']['collected']['total'], $x['rentalDeposits']['collected']['cash']]);

        // Restitution entière : remboursement Stripe de la caution, tiroir inchangé
        $settled = $this->json($this->request('POST', '/api/pos/rentals/deposits/' . $deposit['id'] . '/return', []));
        $this->assertSame(['returned', 150000, 'card', 'refunded_on_terminal'], [$settled['status'], $settled['returnedAmount'], $settled['returnMethod'], $settled['receipt']['action']]);
        $this->assertNotNull($settled['terminalRefundId']);
        $this->assertSame($tp['paymentId'], $settled['receipt']['terminal']['paymentId']);
        $this->assertSame($drawer + $quote['cashTotal'], (int) $this->db->fetchOne('SELECT amount_total FROM caisse WHERE is_open'));
        $this->assertSame(150000, (int) $this->db->fetchOne('SELECT refunded_total FROM pos_terminal_payment WHERE id = ?', [$tp['paymentId']]), 'remboursé chez Stripe');
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
        $key = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette location', 'sendEmail' => false]), 201)['key'];
        $this->token = $saved;
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/app/activate', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['key' => $key, 'app' => 'pos']));

        return json_decode($this->client->getResponse()->getContent(), true)['terminal']['token'];
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
            'HTTP_X_POS_TERMINAL' => $this->terminal, 'HTTP_X_POS_APPROVAL' => $this->approval,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function user(array $roles): User
    {
        $email = 'location-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Location')->setLastname('Comptoir')->setRoles($roles)->setIsVerified(true);
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
