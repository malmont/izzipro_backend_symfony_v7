<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\CashRegister;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\PosTerminalService\PosTerminalPaymentService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeTerminalGateway;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lots F13 / F16 (31/10/2026) : kit Stripe Terminal dans l'application (Tap to Pay, lecteur Bluetooth). Modes du poste et
 * autorisations du site, jeton de connexion (poste du terminal, limite), intention créée par le serveur et confirmée par le kit
 * (simulé : FakeTerminalGateway::sdkConfirm), refus « PIN hors ligne » exposé tel quel, vente et capture inchangées (C4), Interac
 * remboursé sur le lecteur puis confirmé, expiration et reprise par la réconciliation, annulation et délai ; le canal piloté par
 * le serveur ne rend jamais de secret client.
 */
class PosTerminalSdkApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private const INTERAC = '4506445006931933';
    private const OFFLINE_PIN = '4000002500003155';

    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $key = null;
    private int $register;
    private int $variant;
    private int $total;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeTerminalGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-terminal-sdk@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_interac = NULL, pos_terminal_fee_card_bp = 100, pos_terminal_fee_interac_bp = 150, pos_terminal_card_wait_seconds = 120, pos_tap_to_pay_allowed = false, pos_bluetooth_reader_allowed = false, pos_terminal_refund_pending_minutes = 15");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, stripe_reader_label = NULL, stripe_reader_simulated = false, terminal_mode = NULL');
        $this->db->executeStatement('UPDATE stripe_config SET terminal_location_id = NULL');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement("UPDATE product SET price = 2900 WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500, price = NULL WHERE id = ?', [$this->variant]);
        $this->total = 3334; // 29,00 + TPS 5 % + TVQ 9,975 %
    }

    public function testModesAuthorizationsConnectionTokenLimitAndRegisterBinding(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $terminal = $this->posTerminal();
        $error = fn (Response $r) => [$r->getStatusCode(), json_decode($r->getContent(), true)['error'] ?? null];

        // Poste sans réglage : mode déduit « manuel », pas de kit
        $status = $this->json($this->request('GET', "/api/pos/terminal?cashRegisterId=$this->register", null, $terminal['token']));
        $this->assertSame(['manual', false], [$status['mode'], $status['available']]);
        $this->assertSame([409, 'TERMINAL_MODE_INVALID'], $error($this->request('POST', '/api/pos/terminal/connection-token', ['cashRegisterId' => $this->register], $terminal['token'])));
        $this->assertSame('manual', $this->json($this->request('GET', '/api/cash-registers'))[0]['terminalMode']);

        // Mode Tap to Pay sur le poste, site non autorisé (défaut)
        $this->db->executeStatement("UPDATE cash_register SET terminal_mode = 'tap_to_pay' WHERE id = ?", [$this->register]);
        $status = $this->json($this->request('GET', "/api/pos/terminal?cashRegisterId=$this->register", null, $terminal['token']));
        $this->assertSame(['tap_to_pay', false, 'TERMINAL_MODE_NOT_ALLOWED', []], [$status['mode'], $status['available'], $status['error'], $status['paymentMethods']]);
        $this->assertSame([409, 'TERMINAL_MODE_NOT_ALLOWED'], $error($this->request('POST', '/api/pos/terminal/connection-token', ['cashRegisterId' => $this->register], $terminal['token'])));
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register], $terminal['token']);
        $this->assertSame([409, 'TERMINAL_MODE_NOT_ALLOWED'], $error($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()], $terminal['token'])));
        $settings = $this->json($this->request('GET', '/api/pos/settings', null, $terminal['token']));
        $this->assertSame([false, false, 15], [$settings['terminal']['tapToPay'], $settings['terminal']['bluetoothReader'], $settings['terminal']['refundPendingMinutes']]);

        // Site autorisé
        $this->db->executeStatement('UPDATE entreprise SET pos_tap_to_pay_allowed = true');
        $this->assertTrue($this->json($this->request('GET', '/api/pos/settings', null, $terminal['token']))['terminal']['tapToPay']);
        $status = $this->json($this->request('GET', "/api/pos/terminal?cashRegisterId=$this->register", null, $terminal['token']));
        $this->assertSame(['tap_to_pay', true, null, ['card_present', 'interac_present'], null, false], [$status['mode'], $status['available'], $status['error'], $status['paymentMethods'], $status['reader'], $status['simulationAllowed']]);
        $this->assertSame('tap_to_pay', $this->json($this->request('GET', '/api/cash-registers'))[0]['terminalMode']);

        // Jeton de connexion : X-Pos-Terminal obligatoire ; secret jamais journalisé ; emplacement du site créé
        $this->assertSame([403, 'TERMINAL_REQUIRED'], $error($this->request('POST', '/api/pos/terminal/connection-token', ['cashRegisterId' => $this->register])));
        $token = $this->json($this->request('POST', '/api/pos/terminal/connection-token', ['cashRegisterId' => $this->register], $terminal['token']));
        $this->assertStringStartsWith('pst_test_', $token['secret']);
        $this->assertSame(['tml', 'tap_to_pay', $this->register], [substr($token['locationId'], 0, 3), $token['mode'], $token['cashRegisterId']]);
        $this->assertSame($token['locationId'], $this->db->fetchOne('SELECT terminal_location_id FROM stripe_config LIMIT 1'), 'emplacement du site réutilisé');
        $this->assertSame([$token['locationId']], FakeTerminalGateway::$connectionTokens, 'jeton créé pour l\'emplacement du site');
        $journal = (string) $this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'CONNECTION_TOKEN_ISSUED' ORDER BY id DESC LIMIT 1");
        $this->assertStringContainsString('"locationId":"' . $token['locationId'] . '"', $journal);
        $this->assertStringNotContainsString($token['secret'], $journal, 'le secret n\'est jamais journalisé');
        $this->assertSame(['tap_to_pay', true], [$this->json($this->request('POST', '/api/pos/terminal/connection-token', ['cashRegisterId' => $this->register], $terminal['token']))['mode'], true], 'sans cache : un nouveau secret à chaque demande');

        // Limite par terminal (4 en test, 60 en production)
        $this->json($this->request('POST', '/api/pos/terminal/connection-token', ['cashRegisterId' => $this->register], $terminal['token']));
        $this->json($this->request('POST', '/api/pos/terminal/connection-token', ['cashRegisterId' => $this->register], $terminal['token']));
        $limited = $this->request('POST', '/api/pos/terminal/connection-token', ['cashRegisterId' => $this->register], $terminal['token']);
        $this->assertSame([429, 'CONNECTION_TOKEN_RATE_LIMITED'], $error($limited));
        $this->assertGreaterThan(0, json_decode($limited->getContent(), true)['retryAfterSeconds']);
        $other = $this->posTerminal();
        $this->assertSame(200, $this->request('POST', '/api/pos/terminal/connection-token', ['cashRegisterId' => $this->register], $other['token'])->getStatusCode(), 'limite propre à chaque terminal');

        // Rattachement du terminal à un poste (A1-S2) : jeton et paiement sur un autre poste refusés au caissier
        $second = (int) $this->db->fetchOne("INSERT INTO cash_register (id, name, is_active, created_at, terminal_mode) VALUES (nextval('cash_register_id_seq'), 'Poste kit 2', true, NOW(), 'tap_to_pay') RETURNING id");
        $params = $this->db->getParams();
        unset($params['url']);
        $master = DriverManager::getConnection(['dbname' => MV_TEST_MASTER_DB] + $params);
        $master->executeStatement('UPDATE tenant_app_keys SET cash_register_id = ? WHERE id = ?', [$second, $other['id']]);
        try {
            $this->assertSame([403, 'CASH_REGISTER_FORBIDDEN'], $error($this->request('POST', '/api/pos/terminal/connection-token', ['cashRegisterId' => $this->register], $other['token'])), 'jeton pour un autre poste que celui du terminal');
            $this->assertSame([403, 'CASH_REGISTER_FORBIDDEN'], $error($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()], $other['token'])));
            $this->assertSame($second, $this->json($this->request('POST', '/api/pos/terminal/connection-token', [], $other['token']))['cashRegisterId'], 'sans cashRegisterId : le poste du terminal');
        } finally {
            $master->executeStatement('UPDATE tenant_app_keys SET cash_register_id = NULL WHERE id = ?', [$other['id']]);
            $this->db->executeStatement('UPDATE cash_register SET is_active = false WHERE id = ?', [$second]);
        }

        // Poste piloté par le serveur : jamais de secret client, jeton refusé
        $this->db->executeStatement('UPDATE cash_register SET terminal_mode = NULL WHERE id = ?', [$this->register]);
        $this->configureSimulatedReader();
        $status = $this->json($this->request('GET', "/api/pos/terminal?cashRegisterId=$this->register", null, $terminal['token']));
        $this->assertSame(['server', true, 'online'], [$status['mode'], $status['available'], $status['reader']['status']]);
        $this->assertSame([409, 'TERMINAL_MODE_INVALID'], $error($this->request('POST', '/api/pos/terminal/connection-token', ['cashRegisterId' => $this->register], $terminal['token'])));
        $server = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()], $terminal['token']), 201);
        $this->assertSame(['server', null, 'waiting_for_card'], [$server['channel'], $server['clientSecret'], $server['status']], 'canal serveur : pas de secret client');
        $this->assertNull($this->json($this->request('GET', '/api/pos/terminal/payments/' . $server['paymentId'], null, $terminal['token']))['clientSecret']);
        // Mode « serveur » réglé explicitement sur un poste au lecteur détaché : indisponible, pas de kit
        $this->db->executeStatement("UPDATE cash_register SET terminal_mode = 'server', terminal_enabled = false WHERE id = ?", [$this->register]);
        $explicit = $this->json($this->request('GET', "/api/pos/terminal?cashRegisterId=$this->register", null, $terminal['token']));
        $this->assertSame(['server', false], [$explicit['mode'], $explicit['available']]);
    }

    public function testTapToPayCardInteracRefundConfirmationExpiryCancelAndTimeout(): void
    {
        $this->db->executeStatement("UPDATE cash_register SET terminal_mode = 'tap_to_pay' WHERE id = ?", [$this->register]);
        $this->db->executeStatement('UPDATE entreprise SET pos_tap_to_pay_allowed = true');
        $this->loginAs(['ROLE_USER_POS']);
        $terminal = $this->posTerminal();
        $t = $terminal['token'];
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register], $t);
        $error = fn (Response $r) => [$r->getStatusCode(), json_decode($r->getContent(), true)['error'] ?? null];

        // Intention créée par le serveur, secret rendu à l'application ; X-Pos-Terminal obligatoire ; un paiement à la fois par terminal
        $this->assertSame([403, 'TERMINAL_REQUIRED'], $error($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()])));
        $this->key = self::uuid();
        $payment = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items(), 'reader' => ['type' => 'tap_to_pay', 'label' => 'iPhone du comptoir']], $t), 201);
        $this->key = null;
        $pi = $payment['stripePaymentIntentId'];
        $this->assertSame(['sdk', 'waiting_for_card', $pi . '_secret_test', $this->total, false], [$payment['channel'], $payment['status'], $payment['clientSecret'], $payment['amount'], $payment['captured']]);
        $this->assertSame('sdk:t' . $terminal['id'], $this->db->fetchOne('SELECT reader_id FROM pos_terminal_payment WHERE id = ?', [$payment['paymentId']]));
        $this->assertSame(['manual_preferred', 'sdk'], [FakeTerminalGateway::$intents[$pi]['types'] ? 'manual_preferred' : null, FakeTerminalGateway::$intents[$pi]['metadata']['pos_channel']]);
        $this->assertSame(['READER_BUSY', $payment['paymentId']], array_values(array_intersect_key(json_decode($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()], $t)->getContent(), true), ['error' => 1, 'paymentId' => 1])));
        $this->assertSame($payment['paymentId'], $this->json($this->request('GET', "/api/pos/terminal?cashRegisterId=$this->register", null, $t))['currentPayment']['paymentId'], 'paiement en cours du terminal');
        $this->assertStringContainsString('"reader":{"type":"tap_to_pay","label":"iPhone du comptoir"}', (string) $this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'CARD_PAYMENT_STARTED' ORDER BY id DESC LIMIT 1"));

        // Carte canadienne « PIN hors ligne » : refus Stripe exposé tel quel, l'intention reste utilisable pour une autre carte
        FakeTerminalGateway::sdkConfirm($pi, self::OFFLINE_PIN);
        $declined = $this->json($this->request('GET', '/api/pos/terminal/payments/' . $payment['paymentId'], null, $t));
        $this->assertSame(['waiting_for_card', 'offline_pin_required', $pi . '_secret_test'], [$declined['status'], $declined['failureCode'], $declined['clientSecret']]);
        $this->assertStringContainsString('insert the card', $declined['failureMessage']);
        // Visa : autorisée (à capturer avec la vente), secret retiré
        FakeTerminalGateway::sdkConfirm($pi, '4242424242424242');
        $ok = $this->json($this->request('GET', '/api/pos/terminal/payments/' . $payment['paymentId'], null, $t));
        $this->assertSame(['succeeded', false, 'card_present', 'visa', '4242', null, null], [$ok['status'], $ok['captured'], $ok['paymentMethodType'], $ok['cardBrand'], $ok['last4'], $ok['failureCode'], $ok['clientSecret']]);
        $this->assertSame('requires_capture', FakeTerminalGateway::$intents[$pi]['status']);
        // Vente : vérifiée puis capturée dans la transaction, exactement comme C4
        $sale = $this->request('POST', '/api/order/create-multi-payment', ['items' => $this->items(), 'paymentMethods' => [['type' => 1, 'amount' => $this->total / 100, 'terminalPaymentId' => $payment['paymentId']]], 'cashRegisterId' => $this->register], $t);
        $this->assertSame(201, $sale->getStatusCode(), $sale->getContent());
        $orderId = json_decode($sale->getContent(), true)['orderId'];
        $this->assertSame(['succeeded', (int) round($this->total * 100 / 10000)], [FakeTerminalGateway::$intents[$pi]['status'], FakeTerminalGateway::$intents[$pi]['fee']], 'capturé au taux carte de crédit');
        $row = $this->db->fetchAssociative('SELECT status, order_id, channel, stripe_charge_id FROM pos_terminal_payment WHERE id = ?', [$payment['paymentId']]);
        $this->assertSame(['captured', $orderId, 'sdk', 'ch_test_' . substr($pi, -8)], [$row['status'], (int) $row['order_id'], $row['channel'], $row['stripe_charge_id']]);
        $x = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register", null, $t));
        $this->assertSame([$this->total, 0, 1], [$x['cardTerminal']['terminal'], $x['cardTerminal']['interac'], $x['cardTerminal']['count']]);
        // Annulation : carte de crédit remboursée en ligne par le serveur, comme C4
        $manager = $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->assertSame(200, $this->request('POST', "/api/order/cancel/$orderId", ['paymentMethod' => 1], $t)->getStatusCode());
        $this->assertSame($this->total, FakeTerminalGateway::$intents[$pi]['refunded']);

        // Interac par le kit : débité aussitôt ; remboursement présenté sur le lecteur par l'application puis confirmé
        $p2 = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()], $t), 201);
        FakeTerminalGateway::sdkConfirm($p2['stripePaymentIntentId'], self::INTERAC);
        $i = $this->json($this->request('GET', '/api/pos/terminal/payments/' . $p2['paymentId'], null, $t));
        $this->assertSame(['succeeded', true, 'interac_present'], [$i['status'], $i['captured'], $i['paymentMethodType']]);
        $order2 = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $this->items(), 'paymentMethods' => [['type' => 1, 'amount' => $this->total / 100, 'terminalPaymentId' => $p2['paymentId']]], 'cashRegisterId' => $this->register], $t), 201)['orderId'];
        $this->assertSame([409, 'TERMINAL_REFUND_REQUIRED'], $error($this->request('POST', "/api/order/cancel/$order2", ['paymentMethod' => 1], $t)));
        $started = $this->json($this->request('POST', '/api/pos/terminal/payments/' . $p2['paymentId'] . '/refund', [], $t));
        $this->assertSame(['waiting_for_card', $this->total], [$started['refund']['status'], $started['refund']['amount']]);
        $this->assertSame([$p2['stripePaymentIntentId'], 'ch_test_' . substr($p2['stripePaymentIntentId'], -8), $this->total, 'cad', 15], array_values(array_intersect_key($started['refundInstruction'], array_flip(['paymentIntentId', 'chargeId', 'amount', 'currency', 'expiresInMinutes']))), 'instruction pour processRefund()');
        $this->assertSame('waiting_for_card', $this->json($this->request('GET', '/api/pos/terminal/payments/' . $p2['paymentId'], null, $t))['refund']['status'], 'avant l\'expiration : rien ne change');
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'REFUND_PENDING' AND payload LIKE ?", ['%"paymentId":' . $p2['paymentId'] . ',%']));
        // Confirmation : identifiant inconnu, remboursement d'un autre paiement, bon remboursement, rejeu, autre identifiant
        $this->assertSame([422, 'VALIDATION_FAILED'], $error($this->request('POST', '/api/pos/terminal/payments/' . $p2['paymentId'] . '/refund/confirm', ['stripeRefundId' => 'n\'importe quoi'], $t)));
        $this->assertSame([404, 'REFUND_NOT_FOUND'], $error($this->request('POST', '/api/pos/terminal/payments/' . $p2['paymentId'] . '/refund/confirm', ['stripeRefundId' => 're_test_inconnu00'], $t)));
        $foreign = FakeTerminalGateway::sdkRefund($pi, $this->total);
        $this->assertSame([409, 'REFUND_MISMATCH'], $error($this->request('POST', '/api/pos/terminal/payments/' . $p2['paymentId'] . '/refund/confirm', ['stripeRefundId' => $foreign], $t)));
        $re = FakeTerminalGateway::sdkRefund($p2['stripePaymentIntentId'], $this->total); // processRefund() réussi sur le lecteur
        $confirmed = $this->json($this->request('POST', '/api/pos/terminal/payments/' . $p2['paymentId'] . '/refund/confirm', ['stripeRefundId' => $re], $t));
        $this->assertSame(['succeeded', $re, $this->total, null], [$confirmed['refund']['status'], $confirmed['refund']['id'], $confirmed['refund']['amount'], $confirmed['refundInstruction']]);
        $this->assertSame($confirmed, $this->json($this->request('POST', '/api/pos/terminal/payments/' . $p2['paymentId'] . '/refund/confirm', ['stripeRefundId' => $re], $t)), 'rejeu : même réponse');
        $this->assertSame([409, 'REFUND_ALREADY_CONFIRMED'], $error($this->request('POST', '/api/pos/terminal/payments/' . $p2['paymentId'] . '/refund/confirm', ['stripeRefundId' => FakeTerminalGateway::sdkRefund($p2['stripePaymentIntentId'], 100)], $t)));
        $this->assertSame(['CARD_REFUND' => 1, 'REFUND_CONFIRMED' => 1], array_map('intval', $this->db->fetchAllKeyValue("SELECT type, COUNT(*) FROM pos_audit_log WHERE type IN ('REFUND_CONFIRMED', 'CARD_REFUND') AND payload LIKE ? GROUP BY type ORDER BY type", ['%"paymentId":' . $p2['paymentId'] . ',%'])));
        $this->assertSame(200, $this->request('POST', "/api/order/cancel/$order2", ['paymentMethod' => 1, 'terminalRefundId' => $re], $t)->getStatusCode(), 'annulation rejouée avec le remboursement confirmé');
        $this->assertSame(7, (int) $this->db->fetchOne('SELECT status_id FROM "order" WHERE id = ?', [$order2]));

        // Expiration d'un remboursement jamais confirmé, puis reprise par la réconciliation quand Stripe l'a bien fait
        $p3 = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()], $t), 201);
        FakeTerminalGateway::sdkConfirm($p3['stripePaymentIntentId'], self::INTERAC);
        $this->json($this->request('GET', '/api/pos/terminal/payments/' . $p3['paymentId'], null, $t));
        $this->json($this->request('POST', '/api/pos/terminal/payments/' . $p3['paymentId'] . '/refund', ['amount' => 1000], $t));
        $this->db->executeStatement("UPDATE pos_terminal_payment SET refund_requested_at = NOW() - INTERVAL '20 minutes' WHERE id = ?", [$p3['paymentId']]);
        $this->runReconcile();
        $expired = $this->json($this->request('GET', '/api/pos/terminal/payments/' . $p3['paymentId'], null, $t));
        $this->assertSame(['failed', null], [$expired['refund']['status'], $expired['refundInstruction']]);
        $this->assertStringContainsString('expirée', $expired['refund']['failureMessage']);
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'REFUND_EXPIRED' AND payload LIKE ?", ['%"paymentId":' . $p3['paymentId'] . ',%']));
        $again = $this->json($this->request('POST', '/api/pos/terminal/payments/' . $p3['paymentId'] . '/refund', ['amount' => 1000], $t));
        $this->assertSame('waiting_for_card', $again['refund']['status'], 'paiement de nouveau remboursable');
        FakeTerminalGateway::sdkRefund($p3['stripePaymentIntentId'], 1000); // fait sur le lecteur, jamais confirmé par l'application
        $this->db->executeStatement("UPDATE pos_terminal_payment SET refund_requested_at = NOW() - INTERVAL '20 minutes' WHERE id = ?", [$p3['paymentId']]);
        $this->runReconcile();
        $recovered = $this->db->fetchAssociative('SELECT refund_status, refund_amount, refunded_total FROM pos_terminal_payment WHERE id = ?', [$p3['paymentId']]);
        $this->assertSame(['succeeded', 1000, 1000], [$recovered['refund_status'], (int) $recovered['refund_amount'], (int) $recovered['refunded_total']], 'repris par la réconciliation');
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'REFUND_CONFIRMED' AND payload LIKE ? AND payload LIKE '%\"via\":\"reconcile\"%'", ['%"paymentId":' . $p3['paymentId'] . ',%']));

        // Annulation par le caissier avant la carte (aucune action lecteur côté serveur) ; délai dépassé → annulée
        $p4 = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()], $t), 201);
        $canceled = $this->json($this->request('POST', '/api/pos/terminal/payments/' . $p4['paymentId'] . '/cancel', [], $t));
        $this->assertSame(['canceled', 'canceled_by_cashier', 'canceled', null], [$canceled['status'], $canceled['failureCode'], FakeTerminalGateway::$intents[$p4['stripePaymentIntentId']]['status'], $canceled['clientSecret']]);
        $p5 = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()], $t), 201);
        $this->db->executeStatement("UPDATE pos_terminal_payment SET created_at = NOW() - INTERVAL '200 seconds' WHERE id = ?", [$p5['paymentId']]);
        $timeout = $this->json($this->request('GET', '/api/pos/terminal/payments/' . $p5['paymentId'], null, $t));
        $this->assertSame(['canceled', 'timeout', 'canceled'], [$timeout['status'], $timeout['failureCode'], FakeTerminalGateway::$intents[$p5['stripePaymentIntentId']]['status']]);
        $ids = [$payment['paymentId'], $p2['paymentId'], $p3['paymentId'], $p4['paymentId'], $p5['paymentId']];
        $this->assertSame(['sdk' => 5], array_map('intval', $this->db->fetchAllKeyValue('SELECT channel, COUNT(*) FROM pos_terminal_payment WHERE id IN (' . implode(',', $ids) . ') GROUP BY channel')), 'les cinq paiements du test sont du canal sdk (base partagée : jamais de décompte absolu)');
    }

    /** @return array{id: int, token: string} clé POS activée (terminal) */
    private function posTerminal(): array
    {
        $saved = $this->token;
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $key = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette kit', 'sendEmail' => false]), 201)['key'];
        $this->token = $saved;
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/app/activate', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['key' => $key, 'app' => 'pos']));
        $terminal = json_decode($this->client->getResponse()->getContent(), true)['terminal'];

        return ['id' => (int) $terminal['id'], 'token' => (string) $terminal['token']];
    }

    private function configureSimulatedReader(): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $register = $provider->getEntityManager()->find(CashRegister::class, $this->register);
        $provider->getEntityManager()->refresh($register);
        static::getContainer()->get(PosTerminalPaymentService::class)->createSimulatedReader($register);
        $this->assertTrue($register->hasTerminal());
    }

    private function runReconcile(): string
    {
        static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->clear(); // le cron tourne dans son propre processus : rien en mémoire
        $tester = new CommandTester((new Application(static::$kernel))->find('app:pos:terminal-reconcile'));
        $tester->execute(['--tenant' => MV_TEST_TENANT_CODE]);
        static::getContainer()->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $tester->getDisplay();
    }

    private function items(int $quantity = 1): array
    {
        return [['productVariantId' => $this->variant, 'quantity' => $quantity]];
    }

    private function json(Response $response, int $expected = 200): array
    {
        $this->assertSame($expected, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function request(string $method, string $path, ?array $body = null, ?string $terminal = null, ?string $approval = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
            'HTTP_IDEMPOTENCY_KEY' => $this->key,
            'HTTP_X_POS_TERMINAL' => $terminal,
            'HTTP_X_POS_APPROVAL' => $approval,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): User
    {
        $email = 'kit-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Kit')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
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
