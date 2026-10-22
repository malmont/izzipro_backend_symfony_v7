<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\CashRegister;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\PosTerminalService\PosTerminalPaymentService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeTerminalGateway;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Chantier C4 (23/10/2026) : terminal de paiement Stripe piloté par le serveur, lecteur et cartes simulés
 * (FakeTerminalGateway). Vente carte (autorisation puis capture dans la transaction de la vente), Interac (débit
 * immédiat, remboursement sur le lecteur avant annulation), refus, annulation, montants, paiements sans vente,
 * réconciliation, capture réussie puis panne de base, idempotence, webhook isolé.
 */
class PosTerminalApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
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
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-terminal@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_interac = NULL, pos_terminal_fee_card_bp = 100, pos_terminal_fee_interac_bp = 150, pos_terminal_card_wait_seconds = 120, pos_terminal_auth_cancel_minutes = 30, pos_terminal_orphan_minutes = 5");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, stripe_reader_label = NULL, stripe_reader_simulated = false');
        $this->db->executeStatement('UPDATE stripe_config SET terminal_location_id = NULL');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement("UPDATE product SET price = 2900 WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500, price = NULL WHERE id = ?', [$this->variant]);
        $this->total = 3334; // 29,00 + TPS 5 % + TVQ 9,975 %
    }

    public function testWithoutTerminalTheRegisterIsUnavailableAndDeclaredCardStillWorks(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $status = $this->json($this->request('GET', "/api/pos/terminal?cashRegisterId=$this->register"));
        $this->assertFalse($status['available']);
        $this->assertNull($status['reader']);
        $refused = $this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()]);
        $this->assertSame([409, 'TERMINAL_UNAVAILABLE'], [$refused->getStatusCode(), json_decode($refused->getContent(), true)['error']]);
        $registers = $this->json($this->request('GET', '/api/cash-registers'));
        $this->assertNull($registers[0]['terminal'], 'GET /api/cash-registers : terminal null sans lecteur (ajout)');
        // Carte déclarée : vente inchangée
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register]);
        $sale = $this->request('POST', '/api/order/create-multi-payment', ['items' => $this->items(), 'paymentMethods' => [['type' => 1, 'amount' => $this->total / 100]], 'cashRegisterId' => $this->register]);
        $this->assertSame(201, $sale->getStatusCode(), $sale->getContent());
    }

    public function testCreditCardIsAuthorizedThenCapturedWithTheSaleAndRefundedOnCancel(): void
    {
        $this->configureSimulatedReader();
        $this->loginAs(['ROLE_USER_POS']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register]);
        $status = $this->json($this->request('GET', "/api/pos/terminal?cashRegisterId=$this->register"));
        $this->assertSame([true, 'online', true, ['card_present', 'interac_present']], [$status['available'], $status['reader']['status'], $status['reader']['simulated'], $status['paymentMethods']]);
        $this->assertSame('tml', substr((string) $this->db->fetchOne('SELECT terminal_location_id FROM stripe_config LIMIT 1'), 0, 3), 'Location créée sur le compte connecté');
        $this->assertNotNull($this->json($this->request('GET', '/api/cash-registers'))[0]['terminal']['readerId']);

        // Montant incohérent avec le devis → 422
        $bad = $this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total - 100, 'items' => $this->items()]);
        $this->assertSame([422, 'AMOUNT_MISMATCH', $this->total], [$bad->getStatusCode(), json_decode($bad->getContent(), true)['error'], json_decode($bad->getContent(), true)['expectedCard']]);

        // Paiement envoyé au lecteur, idempotent
        $this->key = self::uuid();
        $created = $this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()]);
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $payment = json_decode($created->getContent(), true);
        $this->assertSame(['waiting_for_card', false, $this->total, 'cad'], [$payment['status'], $payment['captured'], $payment['amount'], $payment['currency']]);
        $replay = $this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()]);
        $this->assertSame(['true', $created->getContent()], [$replay->headers->get('Idempotent-Replayed'), $replay->getContent()]);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM pos_terminal_payment'));
        $this->assertSame($this->key, $this->db->fetchOne('SELECT idempotency_key FROM pos_terminal_payment'), 'clé Stripe de création = clé POS');
        $this->key = null;
        $id = $payment['paymentId'];
        $this->assertSame((int) round($this->total * 150 / 10000), (int) $this->db->fetchOne('SELECT application_fee FROM pos_terminal_payment WHERE id = ?', [$id]), 'créé au taux Interac');

        // Second paiement sur le même lecteur → occupé
        $busy = $this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()]);
        $this->assertSame(['READER_BUSY', $id], [json_decode($busy->getContent(), true)['error'], json_decode($busy->getContent(), true)['paymentId']]);

        // Le client présente une Visa : autorisée, pas débitée
        $this->assertSame('waiting_for_card', $this->json($this->request('GET', "/api/pos/terminal/payments/$id"))['status']);
        $manager = $this->token;
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $presented = $this->json($this->request('POST', "/api/pos/terminal/payments/$id/simulate", ['type' => 'card_present', 'cardNumber' => '4242424242424242']));
        $this->assertSame(['succeeded', false, 'card_present', 'visa', '4242'], [$presented['status'], $presented['captured'], $presented['paymentMethodType'], $presented['cardBrand'], $presented['last4']]);
        $this->token = $manager;
        $this->assertSame('requires_capture', FakeTerminalGateway::$intents[$presented['stripePaymentIntentId']]['status']);

        // Vente : mauvais montant, puis capture dans la transaction
        $wrong = $this->request('POST', '/api/order/create-multi-payment', ['items' => $this->items(2), 'paymentMethods' => [['type' => 1, 'amount' => $this->total * 2 / 100, 'terminalPaymentId' => $id]], 'cashRegisterId' => $this->register]);
        $this->assertSame('TERMINAL_PAYMENT_AMOUNT_MISMATCH', json_decode($wrong->getContent(), true)['error'], $wrong->getContent());
        $this->assertSame('requires_capture', FakeTerminalGateway::$intents[$presented['stripePaymentIntentId']]['status'], 'rien capturé');
        $sale = $this->request('POST', '/api/order/create-multi-payment', ['items' => $this->items(), 'paymentMethods' => [['type' => 1, 'amount' => $this->total / 100, 'terminalPaymentId' => $id]], 'cashRegisterId' => $this->register]);
        $this->assertSame(201, $sale->getStatusCode(), $sale->getContent());
        $orderId = json_decode($sale->getContent(), true)['orderId'];
        $pi = FakeTerminalGateway::$intents[$presented['stripePaymentIntentId']];
        $this->assertSame(['succeeded', (int) round($this->total * 100 / 10000)], [$pi['status'], $pi['fee']], 'capturé au taux carte de crédit');
        $row = $this->db->fetchAssociative('SELECT status, order_id FROM pos_terminal_payment WHERE id = ?', [$id]);
        $this->assertSame(['captured', $orderId], [$row['status'], (int) $row['order_id']]);
        $pay = $this->db->fetchAssociative('SELECT stripe_payment_id, stripe_card_brand, stripe_last4 FROM payments WHERE order_payment_id = ? AND payment_method_id = 1', [$orderId]);
        $this->assertSame([$presented['stripePaymentIntentId'], 'visa', '4242'], array_values($pay));
        $reused = $this->request('POST', '/api/order/create-multi-payment', ['items' => $this->items(), 'paymentMethods' => [['type' => 1, 'amount' => $this->total / 100, 'terminalPaymentId' => $id]], 'cashRegisterId' => $this->register]);
        $this->assertSame('TERMINAL_PAYMENT_ALREADY_USED', json_decode($reused->getContent(), true)['error']);
        $this->assertSame(['CARD_PAYMENT_STARTED', 'CARD_SIMULATED', 'CARD_PAYMENT_SUCCEEDED'], $this->db->fetchFirstColumn("SELECT type FROM pos_audit_log WHERE type LIKE 'CARD_%' ORDER BY id"));

        // Rapport X : ventilation carte terminal
        $x = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $this->assertSame(['terminal' => $this->total, 'interac' => 0, 'declared' => 0, 'count' => 1, 'withoutSale' => 0], $x['cardTerminal']);

        // Annulation : remboursement Stripe en ligne de la part carte
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $cancel = $this->request('POST', "/api/order/cancel/$orderId", ['paymentMethod' => 1]);
        $this->assertSame(200, $cancel->getStatusCode(), $cancel->getContent());
        $refund = $this->db->fetchAssociative('SELECT refund_status, refund_amount, refund_id FROM pos_terminal_payment WHERE id = ?', [$id]);
        $this->assertSame(['succeeded', $this->total], [$refund['refund_status'], (int) $refund['refund_amount']]);
        $this->assertSame($this->total, FakeTerminalGateway::$intents[$presented['stripePaymentIntentId']]['refunded']);
        $this->assertSame('CARD_REFUND', $this->db->fetchOne("SELECT type FROM pos_audit_log WHERE type = 'CARD_REFUND'"));
    }

    public function testInteracIsCapturedImmediatelyAndRefundedOnTheReaderBeforeCancel(): void
    {
        $this->configureSimulatedReader();
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register]);
        // Vente mixte : 20,00 en espèces (arrondi admis) + carte
        $cash = 2000;
        $card = $this->total - $cash;
        $id = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $card, 'items' => $this->items(), 'otherPayments' => [['type' => 2, 'amount' => $cash]]]), 201)['paymentId'];
        $presented = $this->json($this->request('POST', "/api/pos/terminal/payments/$id/simulate", ['type' => 'interac_present']));
        $this->assertSame(['succeeded', true, 'interac_present'], [$presented['status'], $presented['captured'], $presented['paymentMethodType']], 'Interac débité aussitôt');
        $this->assertSame((int) round($card * 150 / 10000), (int) $this->db->fetchOne('SELECT application_fee FROM pos_terminal_payment WHERE id = ?', [$id]));
        $sale = $this->request('POST', '/api/order/create-multi-payment', ['items' => $this->items(), 'paymentMethods' => [['type' => 2, 'amount' => $cash / 100], ['type' => 1, 'amount' => $card / 100, 'terminalPaymentId' => $id]], 'cashRegisterId' => $this->register]);
        $this->assertSame(201, $sale->getStatusCode(), $sale->getContent());
        $orderId = json_decode($sale->getContent(), true)['orderId'];
        $x = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $this->assertSame([0, $card, 0], [$x['cardTerminal']['terminal'], $x['cardTerminal']['interac'], $x['cardTerminal']['declared']]);

        // Annulation par carte sans remboursement sur le lecteur → 409 ; en espèces : autorisée (repli)
        $required = $this->request('POST', "/api/order/cancel/$orderId", ['paymentMethod' => 1]);
        $this->assertSame([409, 'TERMINAL_REFUND_REQUIRED', $id, $card], [$required->getStatusCode(), json_decode($required->getContent(), true)['error'], json_decode($required->getContent(), true)['terminalPaymentId'], json_decode($required->getContent(), true)['amount']]);
        $this->assertNotSame(7, (int) $this->db->fetchOne('SELECT status_id FROM "order" WHERE id = ?', [$orderId]), 'vente non annulée');
        // Remboursement présenté sur le lecteur, suivi par GET
        $started = $this->json($this->request('POST', "/api/pos/terminal/payments/$id/refund", []));
        $this->assertSame(['waiting_for_card', $card], [$started['refund']['status'], $started['refund']['amount']]);
        $this->assertSame('waiting_for_card', $this->json($this->request('GET', "/api/pos/terminal/payments/$id"))['refund']['status']);
        $done = $this->json($this->request('POST', "/api/pos/terminal/payments/$id/simulate", ['type' => 'interac_present']));
        $this->assertSame('succeeded', $done['refund']['status']);
        $this->assertSame($card, FakeTerminalGateway::$intents[$presented['stripePaymentIntentId']]['refunded']);
        $cancel = $this->request('POST', "/api/order/cancel/$orderId", ['paymentMethod' => 1, 'terminalRefundId' => $done['refund']['id']]);
        $this->assertSame(200, $cancel->getStatusCode(), $cancel->getContent());
        $this->assertSame(7, (int) $this->db->fetchOne('SELECT status_id FROM "order" WHERE id = ?', [$orderId]));
    }

    public function testDeclinedCardCashierCancelOfflineReaderAndSimulationGuards(): void
    {
        $this->configureSimulatedReader();
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register]);
        $id = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()]), 201)['paymentId'];
        $declined = $this->json($this->request('POST', "/api/pos/terminal/payments/$id/simulate", ['type' => 'card_present', 'cardNumber' => '4000000000009995']));
        $this->assertSame(['failed', 'insufficient_funds'], [$declined['status'], $declined['failureCode']]);
        $this->assertSame('canceled', FakeTerminalGateway::$intents[$declined['stripePaymentIntentId']]['status'], 'intention refusée annulée');
        $sale = $this->request('POST', '/api/order/create-multi-payment', ['items' => $this->items(), 'paymentMethods' => [['type' => 1, 'amount' => $this->total / 100, 'terminalPaymentId' => $id]], 'cashRegisterId' => $this->register]);
        $this->assertSame([402, 'TERMINAL_PAYMENT_NOT_SUCCEEDED'], [$sale->getStatusCode(), json_decode($sale->getContent(), true)['error']]);

        // Annulation par le caissier pendant l'attente de la carte
        $id2 = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()]), 201)['paymentId'];
        $this->key = self::uuid();
        $canceled = $this->json($this->request('POST', "/api/pos/terminal/payments/$id2/cancel"));
        $this->assertSame(['canceled', 'canceled_by_cashier'], [$canceled['status'], $canceled['failureCode']]);
        $replayed = $this->request('POST', "/api/pos/terminal/payments/$id2/cancel");
        $this->assertSame(['true', 200], [$replayed->headers->get('Idempotent-Replayed'), $replayed->getStatusCode()], 'annulation idempotente (C5)');
        $this->key = null;
        $this->assertSame('canceled', $this->json($this->request('POST', "/api/pos/terminal/payments/$id2/cancel"))['status'], 'rejeu sans effet');
        $this->assertSame('CARD_PAYMENT_CANCELED', $this->db->fetchOne("SELECT type FROM pos_audit_log WHERE type = 'CARD_PAYMENT_CANCELED' ORDER BY id DESC LIMIT 1"));

        // Autorisation en cours : annulation refusée (lecteur occupé)
        $id3 = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()]), 201)['paymentId'];
        FakeTerminalGateway::$authorizing = [$this->readerId()];
        $busy = $this->request('POST', "/api/pos/terminal/payments/$id3/cancel");
        $this->assertSame([409, 'READER_BUSY'], [$busy->getStatusCode(), json_decode($busy->getContent(), true)['error']]);
        FakeTerminalGateway::$authorizing = [];
        $this->request('POST', "/api/pos/terminal/payments/$id3/cancel");

        // Lecteur hors ligne
        FakeTerminalGateway::$offline = [$this->readerId()];
        $this->assertSame('offline', $this->json($this->request('GET', "/api/pos/terminal?cashRegisterId=$this->register"))['reader']['status']);
        $offline = $this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()]);
        $this->assertSame([409, 'READER_OFFLINE'], [$offline->getStatusCode(), json_decode($offline->getContent(), true)['error']]);
        FakeTerminalGateway::$offline = [];

        // Simulation : clé live refusée, caissier simple refusé
        $id4 = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()]), 201)['paymentId'];
        FakeTerminalGateway::$live = true;
        $live = $this->request('POST', "/api/pos/terminal/payments/$id4/simulate", ['type' => 'card_present']);
        $this->assertSame([403, 'SIMULATION_FORBIDDEN'], [$live->getStatusCode(), json_decode($live->getContent(), true)['error']]);
        FakeTerminalGateway::$live = false;
        $this->loginAs(['ROLE_USER_POS']);
        $this->assertSame(403, $this->request('POST', "/api/pos/terminal/payments/$id4/simulate", ['type' => 'card_present'])->getStatusCode(), 'caissier : pas de simulation');
        $this->assertSame(403, $this->request('GET', '/api/pos/terminal/payments?orphans=1')->getStatusCode(), 'caissier : pas de liste des paiements sans vente');
        $this->assertSame(403, $this->request('POST', "/api/pos/terminal/payments/$id4/refund", [])->getStatusCode());
    }

    public function testCapturedAtStripeThenDatabaseFailureIsRecoveredByRetryOrListedWithoutSale(): void
    {
        $this->configureSimulatedReader();
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register]);
        $id = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()]), 201)['paymentId'];
        $pi = $this->json($this->request('POST', "/api/pos/terminal/payments/$id/simulate", ['type' => 'card_present']))['stripePaymentIntentId'];
        $orders = (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"');

        // Capture réussie chez Stripe, puis la base tombe avant la validation de la vente
        FakeTerminalGateway::$afterCapture = function () { throw new \RuntimeException('base de données indisponible'); };
        $this->key = self::uuid();
        $failed = $this->request('POST', '/api/order/create-multi-payment', ['items' => $this->items(), 'paymentMethods' => [['type' => 1, 'amount' => $this->total / 100, 'terminalPaymentId' => $id]], 'cashRegisterId' => $this->register]);
        $this->assertSame(400, $failed->getStatusCode(), $failed->getContent());
        FakeTerminalGateway::$afterCapture = null;
        $this->assertSame('succeeded', FakeTerminalGateway::$intents[$pi]['status'], 'capturé chez Stripe');
        $this->assertSame($orders, (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"'), 'vente non enregistrée');
        $this->assertSame(['authorized', null], array_values($this->db->fetchAssociative('SELECT status, order_id FROM pos_terminal_payment WHERE id = ?', [$id])), 'la base ne sait pas encore que la capture a eu lieu');

        // Réessai avec la même clé POS : même capture (clé Stripe pos-capture-<pi>), vente enregistrée une fois
        $captures = count(array_filter(array_keys(FakeTerminalGateway::$idempotencyKeys), fn ($k) => str_starts_with($k, 'pos-capture-')));
        $retry = $this->request('POST', '/api/order/create-multi-payment', ['items' => $this->items(), 'paymentMethods' => [['type' => 1, 'amount' => $this->total / 100, 'terminalPaymentId' => $id]], 'cashRegisterId' => $this->register]);
        $this->assertSame(201, $retry->getStatusCode(), $retry->getContent());
        $this->assertSame($captures, count(array_filter(array_keys(FakeTerminalGateway::$idempotencyKeys), fn ($k) => str_starts_with($k, 'pos-capture-'))), 'aucune seconde capture');
        $orderId = json_decode($retry->getContent(), true)['orderId'];
        $this->assertSame(['captured', $orderId], [$this->db->fetchOne('SELECT status FROM pos_terminal_payment WHERE id = ?', [$id]), (int) $this->db->fetchOne('SELECT order_id FROM pos_terminal_payment WHERE id = ?', [$id])]);
        $this->assertSame($orders + 1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"'));
        $this->key = null;

        // Sans réessai : le paiement capturé apparaît dans les paiements sans vente et n'est jamais annulé comme une autorisation
        $id2 = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()]), 201)['paymentId'];
        $pi2 = $this->json($this->request('POST', "/api/pos/terminal/payments/$id2/simulate", ['type' => 'card_present']))['stripePaymentIntentId'];
        FakeTerminalGateway::$afterCapture = function () { throw new \RuntimeException('base de données indisponible'); };
        $this->request('POST', '/api/order/create-multi-payment', ['items' => $this->items(), 'paymentMethods' => [['type' => 1, 'amount' => $this->total / 100, 'terminalPaymentId' => $id2]], 'cashRegisterId' => $this->register]);
        FakeTerminalGateway::$afterCapture = null;
        $this->db->executeStatement("UPDATE pos_terminal_payment SET updated_at = NOW() - INTERVAL '45 minutes', created_at = NOW() - INTERVAL '46 minutes' WHERE id = ?", [$id2]);
        $this->assertSame(404, $this->request('GET', '/api/pos/terminal/payments?orphans=1&cashRegisterId=99999')->getStatusCode(), 'poste inconnu');
        $orphans = $this->json($this->request('GET', "/api/pos/terminal/payments?orphans=1&cashRegisterId=$this->register"))['payments'];
        $this->assertSame([[$id2, 'succeeded', true]], array_map(fn ($p) => [$p['paymentId'], $p['status'], $p['captured']], $orphans), 'listé comme débité sans vente');
        $this->assertSame('succeeded', FakeTerminalGateway::$intents[$pi2]['status'], 'jamais annulé');
        $this->runReconcile();
        $this->assertSame('captured', $this->db->fetchOne('SELECT status FROM pos_terminal_payment WHERE id = ?', [$id2]));
        $x = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $this->assertSame(1, $x['cardTerminal']['withoutSale']);
        // Le responsable rembourse le paiement sans vente (carte de crédit : en ligne)
        $this->key = self::uuid();
        $refunded = $this->json($this->request('POST', "/api/pos/terminal/payments/$id2/refund", ['amount' => $this->total]));
        $this->assertSame(['succeeded', $this->total], [$refunded['refund']['status'], $refunded['refund']['amount']]);
        $this->assertSame('true', $this->request('POST', "/api/pos/terminal/payments/$id2/refund", ['amount' => $this->total])->headers->get('Idempotent-Replayed'), 'remboursement idempotent (C5)');
        $this->key = null;
        $this->assertSame(1, count(FakeTerminalGateway::$refunds), 'un seul remboursement Stripe');

        // Autorisation jamais suivie d'une vente : annulée par la réconciliation après le délai ; en deçà : conservée
        $id3 = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()]), 201)['paymentId'];
        $pi3 = $this->json($this->request('POST', "/api/pos/terminal/payments/$id3/simulate", ['type' => 'card_present']))['stripePaymentIntentId'];
        $this->runReconcile();
        $this->assertSame('authorized', $this->db->fetchOne('SELECT status FROM pos_terminal_payment WHERE id = ?', [$id3]));
        $this->db->executeStatement("UPDATE pos_terminal_payment SET updated_at = NOW() - INTERVAL '31 minutes' WHERE id = ?", [$id3]);
        $out = $this->runReconcile();
        $this->assertStringContainsString('1 en attente', $out);
        $this->assertSame(['canceled', 'authorization_expired'], array_values($this->db->fetchAssociative('SELECT status, failure_code FROM pos_terminal_payment WHERE id = ?', [$id3])));
        $this->assertSame('canceled', FakeTerminalGateway::$intents[$pi3]['status']);

        // Carte jamais présentée : annulé après le délai d'attente
        $id4 = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total, 'items' => $this->items()]), 201)['paymentId'];
        $this->db->executeStatement("UPDATE pos_terminal_payment SET created_at = NOW() - INTERVAL '3 minutes' WHERE id = ?", [$id4]);
        $this->assertSame(['canceled', 'timeout'], [$this->json($this->request('GET', "/api/pos/terminal/payments/$id4"))['status'], $this->json($this->request('GET', "/api/pos/terminal/payments/$id4"))['failureCode']]);
        $this->assertStringContainsString('Terminé : 1 site(s) avec terminal', $this->runReconcile());
    }

    public function testWebhookIgnoresTerminalPaymentsAndTerminalEvents(): void
    {
        foreach ([
            ['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_pos_1', 'object' => 'payment_intent', 'metadata' => ['store_code' => MV_TEST_TENANT_CODE, 'pos_terminal' => '1']]]],
            ['type' => 'payment_intent.amount_capturable_updated', 'data' => ['object' => ['id' => 'pi_pos_2', 'object' => 'payment_intent', 'metadata' => ['store_code' => MV_TEST_TENANT_CODE, 'pos_terminal' => '1']]]],
            ['type' => 'terminal.reader.action_succeeded', 'data' => ['object' => ['id' => 'tmr_1', 'object' => 'terminal.reader', 'action' => ['type' => 'process_payment_intent']]]],
        ] as $event) {
            $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/stripe/webhook', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($event));
            $body = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertSame([200, 'ignored'], [$this->client->getResponse()->getStatusCode(), $body['status']], $event['type']);
            $this->assertStringContainsString('terminal', $body['detail']);
        }
        // Un paiement de la boutique sans metadata pos_terminal suit toujours la branche boutique
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/stripe/webhook', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_web_unknown', 'object' => 'payment_intent', 'metadata' => ['store_code' => MV_TEST_TENANT_CODE]]]]));
        $this->assertSame('paiement sans panier enregistré', json_decode($this->client->getResponse()->getContent(), true)['detail']);
    }

    // ---------------------------------------------------------------- aides

    private function configureSimulatedReader(): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $register = $provider->getEntityManager()->find(CashRegister::class, $this->register);
        static::getContainer()->get(PosTerminalPaymentService::class)->createSimulatedReader($register);
        $this->assertTrue($register->hasTerminal());
        $this->assertSame('TERMINAL_CONFIGURED', $this->db->fetchOne("SELECT type FROM pos_audit_log WHERE type = 'TERMINAL_CONFIGURED' ORDER BY id DESC LIMIT 1"));
    }

    private function readerId(): string
    {
        return (string) $this->db->fetchOne('SELECT stripe_reader_id FROM cash_register WHERE id = ?', [$this->register]);
    }

    private function runReconcile(): string
    {
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

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
            'HTTP_IDEMPOTENCY_KEY' => $this->key,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): User
    {
        $email = 'terminal-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Term')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
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
