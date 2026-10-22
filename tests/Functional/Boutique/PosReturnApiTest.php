<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\CashRegister;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\PosAuditService\PosPinService;
use App\Services\PosTerminalService\PosTerminalPaymentService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeTerminalGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot F2 (24/10/2026) : retours partiels et échanges. Vente retrouvée par ticket ou code-barres, quantités et montants
 * (prix payé + taxes au prorata), avoir dans la suite du poste, stock, rapports, moyen de remboursement (carte d'abord),
 * terminal Stripe (carte en ligne, Interac en deux temps), échange en une opération, validation C3, idempotence.
 */
class PosReturnApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $key = null;
    private ?string $terminal = null;
    private ?string $approval = null;
    private int $register;
    private int $cap;
    private int $tshirt;
    private int $capTotal = 3334; // 29,00 + TPS + TVQ

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeTerminalGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-retours@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_header_required = false,
            pos_pay_cash = true, pos_pay_declared_card = true, pos_pay_paypal = true, pos_pay_multi = true, pos_declared_card_blocked_with_terminal = false,
            pos_return_max_days = NULL, pos_return_without_receipt = false, pos_return_restock_default = true, pos_return_cash_for_card_sale = false, pos_return_reasons = NULL, pos_approval_return_mode = 'refund', pos_approval_return = NULL");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement("UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = 'C1-'");
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = $this->variant('DEMO-CASQUETTE', 2900);
        $this->tshirt = $this->variant('DEMO-TSHIRT', 1900);
    }

    public function testLookupPartialReturnNumberingStockAndReports(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $s = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertSame(['maxDays' => null, 'withoutReceipt' => false, 'restockDefault' => true, 'cashRefundForCardSale' => false], array_diff_key($s['returns'], ['reasons' => 1]));
        $this->assertSame(['taille_ou_modele', 'defectueux', 'ne_convient_pas', 'erreur_de_caisse', 'autre'], array_column($s['returns']['reasons'], 'code'));
        $this->assertSame(['required' => false, 'threshold' => null], $s['approvals']['return'], 'par défaut : seuil du remboursement (ici jamais)');

        // Vente : 2 casquettes + 1 t-shirt, en espèces
        $stockBefore = (int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$this->cap]);
        $quote = $this->json($this->request('POST', '/api/cart/quote', ['items' => [['productVariantId' => $this->cap, 'quantity' => 2], ['productVariantId' => $this->tshirt, 'quantity' => 1]], 'cashRegisterId' => $this->register]));
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 2], ['productVariantId' => $this->tshirt, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => $quote['total'] / 100]], 'cashRegisterId' => $this->register]), 201);
        $this->assertSame("IZ-T-$this->register-" . $sale['receiptNumber'], $sale['barcode'], 'code-barres du ticket');
        $this->assertEquals(0, $sale['creditApplied']);

        // Recherche par référence imprimée et par code-barres
        $found = $this->json($this->request('GET', '/api/pos/returns/lookup?receipt=' . urlencode($sale['receiptReference'])));
        $this->assertSame([$sale['orderId'], true, null], [$found['orderId'], $found['eligible']['allowed'], $found['eligible']['reason']]);
        $this->assertSame($found, $this->json($this->request('GET', '/api/pos/returns/lookup?barcode=' . $sale['barcode'])));
        $capLine = array_values(array_filter($found['lines'], fn ($l) => $l['productVariantId'] === $this->cap))[0];
        $this->assertSame([2, 0, 2, 2900], [$capLine['quantity'], $capLine['returnedQuantity'], $capLine['returnableQuantity'], $capLine['unitPrice']]);
        $this->assertSame($this->capTotal, $capLine['unitRefundable'], 'prix payé + taxes au prorata');
        $this->assertSame($quote['total'], $found['totalAmount']);
        $this->assertSame(404, $this->request('GET', '/api/pos/returns/lookup?barcode=IZ-T-' . $this->register . '-999999')->getStatusCode());
        $this->assertSame(422, $this->request('GET', '/api/pos/returns/lookup?barcode=abc')->getStatusCode());

        // Retour d'une casquette : avoir, stock, journal, rapport
        $creditNotes = (int) $this->db->fetchOne("SELECT COALESCE(MAX(number), 0) FROM pos_receipt WHERE cash_register_id = ? AND kind = 'credit_note'", [$this->register]);
        $this->key = self::uuid();
        $r = $this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $capLine['orderItemId'], 'quantity' => 1]], 'reasonCode' => 'defectueux', 'cashRegisterId' => $this->register]);
        $return = $this->json($r, 201);
        $this->assertSame([$this->capTotal, 'original', 3335, 0, 1, true], [$return['amount'], $return['refund']['method'], $return['refund']['cash'], $return['refund']['card'], $return['refund']['cashRounding'], $return['restock']], 'espèces arrondies au 5 ¢ (C1)');
        $this->assertSame([$creditNotes + 1, 'C1-' . ($creditNotes + 1), "IZ-A-$this->register-" . ($creditNotes + 1)], [$return['creditNoteNumber'], $return['creditNoteReference'], $return['barcode']]);
        $this->assertSame([['label' => 'TPS', 'amount' => 145], ['label' => 'TVQ', 'amount' => 289]], $return['taxes'], 'taxes au prorata : 5 % et 9,975 % de 29,00');
        $replay = $this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $capLine['orderItemId'], 'quantity' => 1]], 'reasonCode' => 'defectueux', 'cashRegisterId' => $this->register]);
        $this->assertSame(['true', $r->getContent()], [$replay->headers->get('Idempotent-Replayed'), $replay->getContent()], 'rejeu C5 : aucun second retour');
        $this->key = null;
        $this->assertSame($stockBefore - 2 + 1, (int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$this->cap]), 'une casquette remise en stock');
        $ro = $this->db->fetchAssociative('SELECT order_type_id, returned_from_id, total_amount, sub_total, total_tax, status_id FROM "order" WHERE id = ?', [$return['returnOrderId']]);
        $this->assertSame([2, $sale['orderId'], -3334.0, -2900.0, -434.0, 3], [(int) $ro['order_type_id'], (int) $ro['returned_from_id'], (float) $ro['total_amount'], (float) $ro['sub_total'], (float) $ro['total_tax'], (int) $ro['status_id']]);
        $this->assertSame(-3335.0, (float) $this->db->fetchOne('SELECT amount FROM payments WHERE order_payment_id = ?', [$return['returnOrderId']]), 'paiement espèces négatif, arrondi');
        $journal = json_decode($this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'REFUND' ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame(['return', $sale['orderId'], 'defectueux'], [$journal['data']['kind'] ?? $journal['kind'], $journal['data']['originalOrderId'] ?? $journal['originalOrderId'], ($journal['data']['reason'] ?? $journal['reason'])['code']]);
        $x = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $this->assertSame([1, $this->capTotal, 3335], [$x['refunds']['count'], $x['refunds']['total'], $x['refunds']['cash']], 'remboursement dans le rapport');
        $this->assertSame(['count' => 1, 'total' => $this->capTotal, 'restocked' => 1], $x['returns']);
        $this->assertSame($quote['total'] - $this->capTotal, $x['sales']['total'] - $x['refunds']['total']);

        // Quantités : plus que le reste → refus ; le reste exactement → ok ; puis plus rien
        $found = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale['orderId']));
        $capLine = array_values(array_filter($found['lines'], fn ($l) => $l['productVariantId'] === $this->cap))[0];
        $this->assertSame([1, 1], [$capLine['returnedQuantity'], $capLine['returnableQuantity']]);
        $tooMany = $this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $capLine['orderItemId'], 'quantity' => 2]], 'reasonText' => 'test', 'cashRegisterId' => $this->register]);
        $this->assertSame([422, 'RETURN_QUANTITY_EXCEEDED', 1], [$tooMany->getStatusCode(), json_decode($tooMany->getContent(), true)['error'], json_decode($tooMany->getContent(), true)['returnable']]);
        $this->assertSame(422, $this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => 999999, 'quantity' => 1]], 'reasonText' => 'x', 'cashRegisterId' => $this->register])->getStatusCode());
        $this->assertSame('RETURN_REASON_REQUIRED', json_decode($this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $capLine['orderItemId'], 'quantity' => 1]], 'cashRegisterId' => $this->register])->getContent(), true)['error']);
        $this->assertSame('RETURN_REASON_INVALID', json_decode($this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $capLine['orderItemId'], 'quantity' => 1]], 'reasonCode' => 'inconnu', 'cashRegisterId' => $this->register])->getContent(), true)['error']);
        $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $capLine['orderItemId'], 'quantity' => 1]], 'reasonText' => 'Trop grand', 'restock' => false, 'cashRegisterId' => $this->register]), 201);
        $this->assertSame($stockBefore - 2 + 1, (int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$this->cap]), 'restock false : stock inchangé');
        $this->assertSame(0, array_values(array_filter($this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale['orderId']))['lines'], fn ($l) => $l['productVariantId'] === $this->cap))[0]['returnableQuantity']);

        // Délai de retour et retour sans ticket
        $this->db->executeStatement('UPDATE entreprise SET pos_return_max_days = 30');
        $this->db->executeStatement('UPDATE "order" SET order_date = NOW() - INTERVAL \'40 days\' WHERE id = ?', [$sale['orderId']]);
        $late = $this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $found['lines'][1]['orderItemId'], 'quantity' => 1]], 'reasonText' => 'x', 'cashRegisterId' => $this->register]);
        $this->assertSame([409, 'RETURN_DEADLINE_EXCEEDED'], [$late->getStatusCode(), json_decode($late->getContent(), true)['error']]);
        $this->assertSame('RETURN_DEADLINE_EXCEEDED', $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale['orderId']))['eligible']['reason']);
        $noTicket = $this->request('POST', '/api/pos/returns', ['items' => [['productVariantId' => $this->tshirt, 'quantity' => 1]], 'reasonText' => 'sans ticket', 'cashRegisterId' => $this->register]);
        $this->assertSame([409, 'RETURN_WITHOUT_RECEIPT_FORBIDDEN'], [$noTicket->getStatusCode(), json_decode($noTicket->getContent(), true)['error']]);
        $this->db->executeStatement('UPDATE entreprise SET pos_return_without_receipt = true');
        $free = $this->json($this->request('POST', '/api/pos/returns', ['items' => [['productVariantId' => $this->tshirt, 'quantity' => 1]], 'reasonText' => 'sans ticket', 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([null, 'cash'], [$free['orderId'], $free['refund']['cash'] > 0 ? 'cash' : 'none'], 'retour sans ticket : prix actuels, espèces');
        $this->assertSame((int) round(1900 * 1.14975), $free['amount']);
    }

    public function testRefundMethodsCardFirstDeclaredCardTerminalAndInterac(): void
    {
        $this->configureSimulatedReader();
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 20000]);
        // Vente mixte : 2 casquettes, 20,00 en espèces + le reste en carte déclarée
        $total = $this->json($this->request('POST', '/api/cart/quote', ['items' => [['productVariantId' => $this->cap, 'quantity' => 2]], 'cashRegisterId' => $this->register]))['total'];
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 2]], 'paymentMethods' => [['type' => 2, 'amount' => 20], ['type' => 1, 'amount' => ($total - 2000) / 100]], 'cashRegisterId' => $this->register]), 201);
        $look = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale['orderId']))['lines'][0];
        $line = $look['orderItemId'];
        $one = $look['unitRefundable']; // 1re casquette d'une vente de 2 : TVQ 579 répartie 290 puis 289 (cumul exact)
        $this->assertSame([3335, $total - 3335], [$one, $total - $one], 'la somme des deux retours égale la vente');
        // Espèces pour une vente carte : refusé par défaut
        $cash = $this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $line, 'quantity' => 1]], 'reasonText' => 'x', 'refund' => ['method' => 'cash'], 'cashRegisterId' => $this->register]);
        $this->assertSame([409, 'CASH_REFUND_FORBIDDEN'], [$cash->getStatusCode(), json_decode($cash->getContent(), true)['error']]);
        // Moyen d'origine : la carte d'abord (4668 payés par carte ≥ 3334)
        $r1 = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $line, 'quantity' => 1]], 'reasonText' => 'x', 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([$one, 0, null], [$r1['refund']['card'], $r1['refund']['cash'], $r1['refund']['terminal']], 'carte déclarée d\'abord');
        // Second article : il ne reste que 1334 remboursables par carte → 1334 carte + 2000 espèces (arrondi 5 ¢ : 2000)
        $r2 = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $line, 'quantity' => 1]], 'reasonText' => 'x', 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([$total - 2000 - $one, 2000, 0], [$r2['refund']['card'], $r2['refund']['cash'], $r2['refund']['cashRounding']]);
        $this->assertSame($total, $r1['amount'] + $r2['amount'], 'deux retours = la vente, au cent');
        $this->db->executeStatement('UPDATE entreprise SET pos_return_cash_for_card_sale = true');

        // Vente payée sur le terminal, carte de crédit : remboursement partiel Stripe en ligne, clé dérivée de la clé POS
        $pid = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $total, 'items' => [['productVariantId' => $this->cap, 'quantity' => 2]]]), 201)['paymentId'];
        $pi = $this->json($this->request('POST', "/api/pos/terminal/payments/$pid/simulate", ['type' => 'card_present']))['stripePaymentIntentId'];
        $sale2 = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 2]], 'paymentMethods' => [['type' => 1, 'amount' => $total / 100, 'terminalPaymentId' => $pid]], 'cashRegisterId' => $this->register]), 201);
        $line2 = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale2['orderId']))['lines'][0]['orderItemId'];
        $this->key = self::uuid();
        $r3 = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale2['orderId'], 'lines' => [['orderItemId' => $line2, 'quantity' => 1]], 'reasonText' => 'x', 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([$one, 'card_present', $one], [$r3['refund']['card'], $r3['refund']['terminal']['paymentMethodType'], FakeTerminalGateway::$intents[$pi]['refunded']], 'remboursement partiel Stripe');
        $this->assertNotNull($r3['refund']['terminal']['refundId']);
        $this->request('POST', '/api/pos/returns', ['orderId' => $sale2['orderId'], 'lines' => [['orderItemId' => $line2, 'quantity' => 1]], 'reasonText' => 'x', 'cashRegisterId' => $this->register]);
        $this->assertSame($one, FakeTerminalGateway::$intents[$pi]['refunded'], 'rejeu : pas de second remboursement');
        $this->key = null;
        $this->assertSame(1, count(FakeTerminalGateway::$refunds));
        $this->assertSame($one, (int) $this->db->fetchOne('SELECT refunded_total FROM pos_terminal_payment WHERE id = ?', [$pid]));
        $this->assertSame($total - $one, $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale2['orderId']))['payments']['terminal']['refundable']);

        // Interac : deux temps (409, remboursement du montant sur le lecteur, rejeu avec terminalRefundId)
        $pid3 = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $total, 'items' => [['productVariantId' => $this->cap, 'quantity' => 2]]]), 201)['paymentId'];
        $pi3 = $this->json($this->request('POST', "/api/pos/terminal/payments/$pid3/simulate", ['type' => 'interac_present']))['stripePaymentIntentId'];
        $sale3 = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 2]], 'paymentMethods' => [['type' => 1, 'amount' => $total / 100, 'terminalPaymentId' => $pid3]], 'cashRegisterId' => $this->register]), 201);
        $line3 = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale3['orderId']))['lines'][0]['orderItemId'];
        $need = $this->request('POST', '/api/pos/returns', ['orderId' => $sale3['orderId'], 'lines' => [['orderItemId' => $line3, 'quantity' => 1]], 'reasonText' => 'x', 'cashRegisterId' => $this->register]);
        $this->assertSame([409, 'TERMINAL_REFUND_REQUIRED', $pid3, $one], [$need->getStatusCode(), json_decode($need->getContent(), true)['error'], json_decode($need->getContent(), true)['terminalPaymentId'], json_decode($need->getContent(), true)['amount']]);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM pos_return WHERE order_id = ?', [$sale3['orderId']]), 'rien créé');
        $this->json($this->request('POST', "/api/pos/terminal/payments/$pid3/refund", ['amount' => $one]));
        $refund = $this->json($this->request('POST', "/api/pos/terminal/payments/$pid3/simulate", ['type' => 'interac_present']))['refund'];
        $this->assertSame(['succeeded', $one], [$refund['status'], $refund['amount']]);
        $r4 = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale3['orderId'], 'lines' => [['orderItemId' => $line3, 'quantity' => 1]], 'reasonText' => 'x', 'refund' => ['terminalRefundId' => $refund['id']], 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([$refund['id'], 'interac_present', $one], [$r4['refund']['terminal']['refundId'], $r4['refund']['terminal']['paymentMethodType'], $r4['refund']['card']]);
        $again = $this->request('POST', '/api/pos/returns', ['orderId' => $sale3['orderId'], 'lines' => [['orderItemId' => $line3, 'quantity' => 1]], 'reasonText' => 'x', 'refund' => ['terminalRefundId' => $refund['id']], 'cashRegisterId' => $this->register]);
        $this->assertContains(json_decode($again->getContent(), true)['error'], ['TERMINAL_REFUND_REQUIRED', 'TERMINAL_REFUND_ALREADY_USED'], 'même remboursement non réutilisable');
        $this->assertSame($one, FakeTerminalGateway::$intents[$pi3]['refunded']);
    }

    public function testExchangeInOneOperationAndCashierApproval(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 20000]);
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->tshirt, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => round(1900 * 1.14975) / 100]], 'cashRegisterId' => $this->register]), 201);
        $line = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale['orderId']))['lines'][0]['orderItemId'];
        $tshirtTotal = (int) round(1900 * 1.14975); // 2185
        $cashBefore = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"))['expectedCash'];
        $tickets = (int) $this->db->fetchOne("SELECT MAX(number) FROM pos_receipt WHERE cash_register_id = ? AND kind = 'ticket'", [$this->register]);
        $notes = (int) $this->db->fetchOne("SELECT COALESCE(MAX(number), 0) FROM pos_receipt WHERE cash_register_id = ? AND kind = 'credit_note'", [$this->register]);
        $orders = (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"');

        // Échange t-shirt (21,85) contre casquette (33,34) : le client paie 11,49 en espèces (arrondi 11,50)
        $diff = $this->capTotal - $tshirtTotal; // 1149
        $this->key = self::uuid();
        $x = $this->request('POST', '/api/pos/exchanges', ['return' => ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $line, 'quantity' => 1]], 'reasonCode' => 'taille_ou_modele'],
            'sale' => ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 11.50]], 'cashRegisterId' => $this->register]]);
        $ex = $this->json($x, 201);
        $this->assertSame([$tshirtTotal, $tshirtTotal, 0, 0], [$ex['return']['amount'], $ex['return']['creditApplied'], $ex['return']['refund']['amount'], $ex['return']['refund']['cash']], 'retour entièrement en crédit');
        $this->assertSame([$notes + 1, $tickets + 1], [$ex['return']['creditNoteNumber'], $ex['sale']['receiptNumber']], 'un avoir et un ticket');
        $this->assertSame([21.85, round(($this->capTotal + 1 - $tshirtTotal) / 100, 2)], [$ex['sale']['creditApplied'], $ex['sale']['amountDue']], 'amountDue = total + arrondi − crédit');
        $this->assertSame($ex['sale']['orderId'], $ex['return']['exchangeOrderId']);
        $this->assertSame($orders + 2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"'));
        $replay = $this->request('POST', '/api/pos/exchanges', ['return' => ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $line, 'quantity' => 1]], 'reasonCode' => 'taille_ou_modele'],
            'sale' => ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 11.50]], 'cashRegisterId' => $this->register]]);
        $this->assertSame(['true', $orders + 2], [$replay->headers->get('Idempotent-Replayed'), (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"')]);
        $this->key = null;
        $saleRow = $this->db->fetchAssociative('SELECT total_amount, credit_applied, cash_rounding FROM "order" WHERE id = ?', [$ex['sale']['orderId']]);
        $this->assertSame([3334.0, $tshirtTotal, 1], [(float) $saleRow['total_amount'], (int) $saleRow['credit_applied'], (int) $saleRow['cash_rounding']]);
        $this->assertSame(1150.0, (float) $this->db->fetchOne('SELECT SUM(amount) FROM payments WHERE order_payment_id = ?', [$ex['sale']['orderId']]), 'seule la différence est payée');
        $xr = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $this->assertSame([2, 1], [$xr['sales']['count'], $xr['refunds']['count']]);
        $this->assertSame($cashBefore + 1150, $xr['expectedCash'], 'espèces : + la différence encaissée seulement, retour en crédit');

        // Échange inverse : casquette (33,34) rendue contre t-shirt (21,85) : 11,49 rendus en espèces (arrondi 11,50)
        $capLine = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $ex['sale']['orderId']))['lines'][0]['orderItemId'];
        $ex2 = $this->json($this->request('POST', '/api/pos/exchanges', ['return' => ['orderId' => $ex['sale']['orderId'], 'lines' => [['orderItemId' => $capLine, 'quantity' => 1]], 'reasonCode' => 'taille_ou_modele', 'refund' => ['method' => 'cash']],
            'sale' => ['items' => [['productVariantId' => $this->tshirt, 'quantity' => 1]], 'cashRegisterId' => $this->register]]), 201);
        $this->assertSame([$this->capTotal, $tshirtTotal, $diff, 1150, 1], [$ex2['return']['amount'], $ex2['return']['creditApplied'], $ex2['return']['refund']['amount'], $ex2['return']['refund']['cash'], $ex2['return']['refund']['cashRounding']]);
        $this->assertEquals([21.85, 0], [$ex2['sale']['creditApplied'], $ex2['sale']['amountDue']], 'vente couverte par le crédit : rien à payer');
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM payments WHERE order_payment_id = ?', [$ex2['sale']['orderId']]));

        // Caissier avec seuil : APPROVAL_REQUIRED action return {orderId, amount}, puis PIN du responsable
        $this->db->executeStatement("UPDATE entreprise SET pos_approval_return_mode = 'custom', pos_approval_return = 1000");
        $this->assertSame(['required' => true, 'threshold' => 1000], $this->json($this->request('GET', '/api/pos/settings'))['approvals']['return']);
        $manager = $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        static::getContainer()->get(PosPinService::class)->set($manager, '2468');
        $this->terminal = $this->posTerminal();
        $cashier = $this->loginAs(['ROLE_USER_POS']);
        $sale3 = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => $this->capTotal / 100]], 'cashRegisterId' => $this->register]), 201);
        $line3 = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale3['orderId']))['lines'][0]['orderItemId'];
        $need = $this->request('POST', '/api/pos/returns', ['orderId' => $sale3['orderId'], 'lines' => [['orderItemId' => $line3, 'quantity' => 1]], 'reasonCode' => 'autre', 'cashRegisterId' => $this->register]);
        $body = json_decode($need->getContent(), true);
        $this->assertSame([403, 'APPROVAL_REQUIRED', 'return', ['orderId' => $sale3['orderId'], 'amount' => $this->capTotal]], [$need->getStatusCode(), $body['error'], $body['action'], $body['context']]);
        $granted = $this->json($this->request('POST', '/api/pos/approvals', ['action' => 'return', 'managerId' => $manager->getId(), 'pin' => '2468', 'context' => ['orderId' => $sale3['orderId'], 'amount' => $this->capTotal]]), 201);
        $this->approval = $granted['approvalToken'];
        $ok = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale3['orderId'], 'lines' => [['orderItemId' => $line3, 'quantity' => 1]], 'reasonCode' => 'autre', 'cashRegisterId' => $this->register]), 201);
        $this->approval = null;
        $journal = json_decode($this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'REFUND' ORDER BY id DESC LIMIT 1"), true);
        $approvedBy = $journal['data']['approvedBy'] ?? $journal['approvedBy'] ?? null;
        $this->assertSame($manager->getId(), $approvedBy['id'] ?? null, 'journal : approvedBy du responsable');
        $this->db->executeStatement("UPDATE entreprise SET pos_approval_return_mode = 'refund', pos_approval_return = NULL");
    }

    public function testCancelAfterApprovedInteracRefundNeedsNoSecondApproval(): void
    {
        $this->configureSimulatedReader();
        $this->db->executeStatement('UPDATE entreprise SET pos_approval_refund = 0');
        $manager = $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        static::getContainer()->get(PosPinService::class)->set($manager, '1357');
        $this->terminal = $this->posTerminal();
        $this->loginAs(['ROLE_USER_POS']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register]);
        $pid = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->capTotal, 'items' => [['productVariantId' => $this->cap, 'quantity' => 1]]]), 201)['paymentId'];
        $saved = $this->token;
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->request('POST', "/api/pos/terminal/payments/$pid/simulate", ['type' => 'interac_present']);
        $this->token = $saved;
        $orderId = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'paymentMethods' => [['type' => 1, 'amount' => $this->capTotal / 100, 'terminalPaymentId' => $pid]], 'cashRegisterId' => $this->register]), 201)['orderId'];
        // Remboursement Interac validé par le responsable
        $this->approval = $this->json($this->request('POST', '/api/pos/approvals', ['action' => 'refund', 'managerId' => $manager->getId(), 'pin' => '1357', 'context' => ['orderId' => $orderId]]), 201)['approvalToken'];
        $this->json($this->request('POST', "/api/pos/terminal/payments/$pid/refund", []));
        $this->approval = null;
        $this->token = null;
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $refund = $this->json($this->request('POST', "/api/pos/terminal/payments/$pid/simulate", ['type' => 'interac_present']))['refund'];
        $this->token = $saved;
        // Annulation rejouée par le caissier avec ce terminalRefundId : pas de seconde validation, approvedBy repris
        $cancel = $this->request('POST', "/api/order/cancel/$orderId", ['paymentMethod' => 1, 'terminalRefundId' => $refund['id']]);
        $this->assertSame(200, $cancel->getStatusCode(), $cancel->getContent());
        $journal = json_decode($this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'REFUND' ORDER BY id DESC LIMIT 1"), true);
        $approvedBy = $journal['data']['approvedBy'] ?? $journal['approvedBy'] ?? null;
        $this->assertSame($manager->getId(), $approvedBy['id'] ?? null, 'approvedBy repris de la validation du remboursement');
        // Toute autre annulation garde la règle C3
        $orderId2 = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => $this->capTotal / 100]], 'cashRegisterId' => $this->register]), 201)['orderId'];
        $this->assertSame('APPROVAL_REQUIRED', json_decode($this->request('POST', "/api/order/cancel/$orderId2", ['paymentMethod' => 2])->getContent(), true)['error']);
        $this->db->executeStatement('UPDATE entreprise SET pos_approval_refund = NULL');
    }

    // ---------------------------------------------------------------- aides

    private function configureSimulatedReader(): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        static::getContainer()->get(PosTerminalPaymentService::class)->createSimulatedReader($provider->getEntityManager()->find(CashRegister::class, $this->register));
    }

    private function variant(string $code, int $price): int
    {
        $id = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = ? ORDER BY v.id LIMIT 1", [$code]);
        $this->db->executeStatement('UPDATE product SET price = ? WHERE code = ?', [$price, $code]);
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500, price = NULL WHERE id = ?', [$id]);

        return $id;
    }

    private function posTerminal(): string
    {
        $saved = $this->token;
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $key = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette retours', 'sendEmail' => false]), 201)['key'];
        $this->token = $saved;
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/app/activate', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['key' => $key, 'app' => 'pos']));

        return json_decode($this->client->getResponse()->getContent(), true)['terminal']['token'];
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
            'HTTP_IDEMPOTENCY_KEY' => $this->key, 'HTTP_X_POS_TERMINAL' => $this->terminal, 'HTTP_X_POS_APPROVAL' => $this->approval,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): User
    {
        $email = 'retour-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Retour')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        [$k, $a] = [$this->key, $this->approval];
        $this->key = $this->approval = null;
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $email, 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
        [$this->key, $this->approval] = [$k, $a];

        return $user;
    }
}
