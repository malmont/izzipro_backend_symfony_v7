<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\PosAuditService\PosPinService;
use App\Services\PosOfflineService\PosOfflineCalculator;
use App\Services\PosOfflineService\PosOfflineSyncService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeTerminalGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Lot F9 (27/10/2026) : cartes-cadeaux et avoirs — vente, consultation, utilisation, retours, annulation, ajustements, remplacement, rapports, hors ligne, anti-balayage */
class PosGiftCardApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
    private ?string $approval = null;
    private int $terminalId = 0;
    private int $register;
    private int $cap;    // 29,00 → 33,34 taxes comprises, 33,35 en espèces

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeTerminalGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-cartes-cadeaux@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_header_required = false,
            pos_pay_cash = true, pos_pay_declared_card = true, pos_pay_paypal = true, pos_pay_multi = true, pos_declared_card_blocked_with_terminal = false, pos_return_max_days = NULL, pos_return_without_receipt = false, pos_return_cash_for_card_sale = true, pos_approval_return_mode = 'refund',
            pos_discount_enabled = true, pos_custom_item_enabled = false, pos_price_override_enabled = false, pos_offline_enabled = false,
            pos_gift_card_enabled = false, pos_gift_card_amounts = NULL, pos_gift_card_expiry_months = NULL, pos_store_credit_enabled = false, pos_store_credit_expiry_months = NULL, pos_gift_card_cash_refund_max = 500, pos_gift_card_cash_refund_beyond = false");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement("UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = NULL");
        $this->db->executeStatement('UPDATE product SET is_pos = true');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = $this->variant('DEMO-CASQUETTE', 2900);
    }

    public function testGiftCardLifecycleRedemptionReturnsReportsAndStatistics(): void
    {
        $manager = $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        static::getContainer()->get(PosPinService::class)->set($manager, '7788');
        $this->terminal = $this->posTerminal();
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 20000]);
        $cashier = $this->loginAs(['ROLE_USER_POS']);
        $s = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertSame(['enabled' => false, 'suggestedAmounts' => [2500, 5000, 10000], 'expiryMonths' => null, 'storeCredits' => ['enabled' => false, 'expiryMonths' => null], 'cashRefundMaxBalance' => 500, 'cashRefundBeyond' => false], array_diff_key($s['giftCards'], ['codeFormat' => 1, 'barcodePrefix' => 1, 'offline' => 1, 'codeAttemptsPer10Min' => 1]), 'désactivé par défaut, règle du Québec');
        $this->assertSame([false, ['required' => true]], [$s['giftCards']['offline'], $s['approvals']['giftCardCancel']]);
        $sell = fn (array $body) => $this->request('POST', '/api/pos/gift-cards', $body + ['cashRegisterId' => $this->register]);
        $this->assertSame('GIFT_CARD_DISABLED', json_decode($sell(['amount' => 5000, 'paymentMethods' => [['type' => 2, 'amount' => 50]]])->getContent(), true)['error']);
        $this->db->executeStatement('UPDATE entreprise SET pos_gift_card_enabled = true, pos_store_credit_enabled = true');

        $cashBefore = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"))['expectedCash'];
        // Statistiques avant : chiffre d'affaires et nombre de commandes caisse ; argent encaissé
        $revenueBefore = $this->json($this->request('GET', '/api/statistiques/chiffre-affaires/pos'));
        $countBefore = $this->json($this->request('GET', '/api/statistiques/nombre-commandes/pos'));
        $paymentsBefore = $this->json($this->request('GET', '/api/payments/statistics/pos'))['current_week']['PaiementClient']['total'];

        // Vente d'une carte de 50,00 en espèces (Idempotency-Key), ticket carte-cadeau, commande de type 3, transaction 9
        $sale = $this->json($this->request('POST', '/api/pos/gift-cards', ['amount' => 5000, 'paymentMethods' => [['type' => 2, 'amount' => 50]], 'cashRegisterId' => $this->register], ['HTTP_IDEMPOTENCY_KEY' => self::uuid()]), 201);
        $code = $sale['giftCard']['code'];
        $this->assertMatchesRegularExpression('/^GC-[23456789A-HJ-NP-Z]{4}-[23456789A-HJ-NP-Z]{4}-[23456789A-HJ-NP-Z]{4}$/', $code);
        $this->assertSame([5000, 5000, 'gift_card', 'active', null, 'IZ-G-' . $code, substr($code, -4)], [$sale['giftCard']['balance'], $sale['giftCard']['initialAmount'], $sale['giftCard']['kind'], $sale['giftCard']['status'], $sale['giftCard']['expiresAt'], $sale['giftCard']['barcode'], $sale['giftCard']['last4']]);
        $this->assertEquals(['gift_card', 'gift_card', 'IZ-C-' . $this->register . '-' . $sale['receiptNumber'], 50.0, 0.0, 50.0], [$sale['kind'], $sale['receiptKind'], $sale['barcode'], $sale['totalAmount'], $sale['totalTax'], $sale['amountDue']]);
        $this->assertSame([substr($code, -4), 'gift_card'], [$sale['giftCard']['last4'], $sale['lines'][0]['custom'] ? 'gift_card' : 'x'], 'ticket : ligne libre « Carte-cadeau •••• »');
        $this->assertSame('Carte-cadeau •••• ' . substr($code, -4), $sale['lines'][0]['name']);
        $row = $this->db->fetchAssociative('SELECT o.order_type_id, o.total_amount, o.total_tax, i.custom_label, i.taxable FROM "order" o JOIN order_items i ON i.order_associated_id = o.id WHERE o.id = ?', [$sale['orderId']]);
        $this->assertSame([3, 5000.0, 0.0, false], [(int) $row['order_type_id'], (float) $row['total_amount'], (float) $row['total_tax'], (bool) $row['taxable']]);
        $this->assertSame([9, 5000], array_map('intval', $this->db->fetchNumeric('SELECT transaction_type_id, cash_amount FROM transaction_caisse WHERE order_caisse_id = ?', [$sale['orderId']])));
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM payments WHERE order_payment_id = ?', [$sale['orderId']]), 'paiement espèces enregistré (argent encaissé)');
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM pos_gift_card WHERE code_hash = ?', [$code]), 'le code en clair n\'est jamais en base');

        // Carte physique pré-imprimée : code fourni (format et unicité), refus explicite si déjà pris ; une carte ne paie pas une carte
        $pre = $this->json($sell(['amount' => 2500, 'code' => 'gc-abcd-efgh-jklm', 'paymentMethods' => [['type' => 2, 'amount' => 25]]]), 201);
        $this->assertSame(['GC-ABCD-EFGH-JKLM', 'JKLM'], [$pre['giftCard']['code'], $pre['giftCard']['last4']]);
        $this->assertSame([409, 'GIFT_CARD_CODE_TAKEN'], $this->error($sell(['amount' => 2500, 'code' => 'GC-ABCD-EFGH-JKLM', 'paymentMethods' => [['type' => 2, 'amount' => 25]]])));
        $this->assertSame([422, 'GIFT_CARD_CODE_INVALID'], $this->error($sell(['amount' => 2500, 'code' => 'GC-0000-1111-OOOO', 'paymentMethods' => [['type' => 2, 'amount' => 25]]])));
        $this->assertSame([409, 'GIFT_CARD_PAYMENT_INVALID'], $this->error($sell(['amount' => 2500, 'paymentMethods' => [['type' => 4, 'amount' => 25, 'code' => $code]]])));
        $this->assertSame([400, 'PAYMENT_TOTAL_MISMATCH'], $this->error($sell(['amount' => 2500, 'paymentMethods' => [['type' => 2, 'amount' => 20]]])));

        // Statistiques après : chiffre d'affaires et nombre de commandes inchangés (passif), argent encaissé +75,00
        $this->assertSame($revenueBefore, $this->json($this->request('GET', '/api/statistiques/chiffre-affaires/pos')), 'la vente d\'une carte n\'est pas un chiffre d\'affaires');
        $this->assertSame($countBefore, $this->json($this->request('GET', '/api/statistiques/nombre-commandes/pos')));
        $this->assertEqualsWithDelta($paymentsBefore + 7500, $this->json($this->request('GET', '/api/payments/statistics/pos'))['current_week']['PaiementClient']['total'], 0.01, 'argent encaissé compté');

        // Consultation : code tel quel, en minuscules, avec le préfixe du code-barres ; inconnu 404 ; journal
        $look = $this->json($this->request('GET', '/api/pos/gift-cards/' . strtolower($code)));
        $this->assertSame([5000, 'active', true, null], [$look['balance'], $look['status'], $look['usable'], $look['code']], 'code en clair jamais renvoyé à la consultation');
        $this->assertSame(5000, $this->json($this->request('GET', '/api/pos/gift-cards/IZ-G-' . $code))['balance']);
        $this->assertSame([404, 'GIFT_CARD_NOT_FOUND'], $this->error($this->request('GET', '/api/pos/gift-cards/GC-ZZZZ-ZZZZ-ZZZZ')));
        $this->assertSame([422, 'GIFT_CARD_CODE_INVALID'], $this->error($this->request('GET', '/api/pos/gift-cards/ABC')));
        $this->assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'GIFT_CARD_LOOKUP' AND payload LIKE ?", ['%"result":"GIFT_CARD_%']), 'échecs journalisés');

        // Devis avec carte : part applicable et reste à payer, sans débit
        $items = [['productVariantId' => $this->cap, 'quantity' => 1]];
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'giftCards' => [['code' => $code]], 'cashRegisterId' => $this->register]));
        $this->assertSame([3334, 3334, 0, 0, 5000], [$q['total'], $q['giftCardApplied'], $q['amountDue'], $q['cashAmountDue'], $q['giftCards'][0]['balance']]);
        $q2 = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'giftCards' => [['code' => $code, 'amount' => 1000]], 'cashRegisterId' => $this->register]));
        $this->assertSame([1000, 2334, 2335], [$q2['giftCardApplied'], $q2['amountDue'], $q2['cashAmountDue']]);
        $this->assertSame(5000, $this->json($this->request('GET', '/api/pos/gift-cards/' . $code))['balance'], 'devis : aucun débit');

        // Vente payée 10,00 par carte-cadeau + 23,35 espèces : débit dans la transaction, aucune ligne payments pour la carte, ticket
        $pay = fn (array $methods) => $this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => $methods, 'cashRegisterId' => $this->register]);
        $this->assertSame([400, 'PAYMENT_METHODS_INVALID'], $this->error($pay([['type' => 4, 'amount' => 10]])), 'code requis');
        $this->assertSame([409, 'GIFT_CARD_INSUFFICIENT'], $this->error($pay([['type' => 4, 'amount' => 33.34, 'code' => 'GC-ABCD-EFGH-JKLM']])), 'carte de 25,00 pour 33,34');
        $this->assertSame([409, 'GIFT_CARD_KIND_MISMATCH'], $this->error($pay([['type' => 5, 'amount' => 10, 'code' => $code], ['type' => 2, 'amount' => 23.35]])));
        $this->assertSame(5000, $this->json($this->request('GET', '/api/pos/gift-cards/' . $code))['balance'], 'refus : rien débité');
        $sale1 = $this->json($pay([['type' => 4, 'amount' => 10, 'code' => $code], ['type' => 2, 'amount' => 23.35]]), 201);
        $this->assertEquals([10.0, 23.35, [['kind' => 'gift_card', 'last4' => substr($code, -4), 'amount' => 10.0]], 33.34], [$sale1['giftCardApplied'], $sale1['amountDue'], $sale1['giftCardPayments'], $sale1['totalAmount']]);
        $this->assertSame([1000, 1], [(int) $this->db->fetchOne('SELECT gift_card_applied FROM "order" WHERE id = ?', [$sale1['orderId']]), (int) $this->db->fetchOne('SELECT COUNT(*) FROM payments WHERE order_payment_id = ?', [$sale1['orderId']])]);
        $this->assertSame(4000, $this->json($this->request('GET', '/api/pos/gift-cards/' . $code))['balance']);
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'GIFT_CARD_REDEEMED' AND payload LIKE ?", ['%"reference":"' . $this->db->fetchOne('SELECT reference FROM "order" WHERE id = ?', [$sale1['orderId']]) . '"%']), 'utilisation journalisée (base partagée entre tests : filtre sur la vente)');

        // Rapport X : bloc giftCards, part carte-cadeau des ventes ; les ventes de cartes ne sont pas des ventes
        $x = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $this->assertSame([['count' => 2, 'amount' => 7500], ['count' => 1, 'amount' => 1000], 1000, 1, 3334], [$x['giftCards']['sold'], $x['giftCards']['redeemed'], $x['sales']['giftCards'], $x['sales']['count'], $x['sales']['total']]);
        $this->assertGreaterThanOrEqual(6500, $x['giftCards']['outstanding']);
        $this->assertSame($cashBefore + 7500 + 2335, $x['expectedCash'], 'espèces attendues : + cartes vendues + part espèces de la vente');

        // Retour de la vente payée par carte : recrédit d'abord sur la carte, puis espèces ; espèces interdites pour la part carte sauf réglage
        $look1 = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale1['orderId']));
        $this->assertSame([1000, 1000], [$look1['payments']['giftCard'], $look1['payments']['giftCardRefundable']]);
        $this->assertSame([409, 'CASH_REFUND_FORBIDDEN'], $this->error($this->request('POST', '/api/pos/returns', ['orderId' => $sale1['orderId'], 'lines' => [['orderItemId' => $look1['lines'][0]['orderItemId'], 'quantity' => 1]], 'reasonCode' => 'autre', 'refund' => ['method' => 'cash'], 'cashRegisterId' => $this->register])));
        $ret = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale1['orderId'], 'lines' => [['orderItemId' => $look1['lines'][0]['orderItemId'], 'quantity' => 1]], 'reasonCode' => 'autre', 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([3334, 1000, 2335, 0], [$ret['amount'], $ret['refund']['giftCard'], $ret['refund']['cash'], $ret['refund']['card']], 'carte recréditée en premier, reste en espèces');
        $this->assertSame(5000, $this->json($this->request('GET', '/api/pos/gift-cards/' . $code))['balance'], 'solde rétabli');
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'GIFT_CARD_REFUNDED' AND payload LIKE ?", ['%"returnOrderId":' . $ret['returnOrderId'] . ',%']), 'recrédit journalisé pour ce retour (base partagée entre tests)');

        // Avoir : vente en espèces puis retour remboursé « store_credit » ; l'avoir paie une vente (type 5)
        $base = $this->json($pay([['type' => 2, 'amount' => 33.35]]), 201);
        $line = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $base['orderId']))['lines'][0]['orderItemId'];
        $credit = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $base['orderId'], 'lines' => [['orderItemId' => $line, 'quantity' => 1]], 'reasonCode' => 'autre', 'refund' => ['method' => 'store_credit'], 'cashRegisterId' => $this->register]), 201);
        $av = $credit['refund']['storeCredit'];
        $this->assertMatchesRegularExpression('/^AV-/', $av['code']);
        $this->assertSame([3334, 'store_credit', 0, 0, null], [$av['balance'], $av['kind'], $credit['refund']['cash'], $credit['refund']['card'], $av['expiresAt']]);
        $this->assertSame($av['id'], (int) $this->db->fetchOne('SELECT store_credit_id FROM pos_return WHERE id = ?', [$credit['returnId']]));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM payments WHERE order_payment_id = ?', [$credit['returnOrderId']]), 'avoir : aucun paiement sur la commande de retour');
        $this->assertSame([409, 'GIFT_CARD_KIND_MISMATCH'], $this->error($pay([['type' => 4, 'amount' => 33.34, 'code' => $av['code']]])));
        $sale2 = $this->json($pay([['type' => 5, 'amount' => 33.34, 'code' => $av['code']]]), 201);
        $this->assertEquals([33.34, 0.0], [$sale2['giftCardApplied'], $sale2['amountDue']]);
        $used = $this->json($this->request('GET', '/api/pos/gift-cards/' . $av['code']));
        $this->assertSame(['depleted', 0, false], [$used['status'], $used['balance'], $used['usable']]);
        $this->assertSame([409, 'GIFT_CARD_INACTIVE'], $this->error($pay([['type' => 5, 'amount' => 1, 'code' => $av['code']], ['type' => 2, 'amount' => 32.35]])));
        $xr = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $this->assertSame([['count' => 1, 'amount' => 3334], 2], [$xr['giftCards']['creditsIssued'], $xr['giftCards']['redeemed']['count']]);

        // Annulation d'une carte vendue jamais utilisée : validation C3, remboursement espèces, avoir (ticket), carte annulée ; carte utilisée : refus
        $sold = $this->json($sell(['amount' => 2500, 'paymentMethods' => [['type' => 2, 'amount' => 25]]]), 201);
        $need = $this->request('POST', '/api/pos/gift-cards/' . $sold['giftCard']['code'] . '/cancel', ['cashRegisterId' => $this->register]);
        $body = json_decode($need->getContent(), true);
        $this->assertSame([403, 'APPROVAL_REQUIRED', 'gift_card_cancel', ['amount' => 2500]], [$need->getStatusCode(), $body['error'], $body['action'], $body['context']]);
        $this->approval = $this->json($this->request('POST', '/api/pos/approvals', ['action' => 'gift_card_cancel', 'managerId' => $manager->getId(), 'pin' => '7788', 'context' => ['amount' => 2500]]), 201)['approvalToken'];
        $canceled = $this->json($this->request('POST', '/api/pos/gift-cards/' . $sold['giftCard']['code'] . '/cancel', ['cashRegisterId' => $this->register]));
        $this->approval = null;
        $this->assertSame(['canceled', 0, [['type' => 2, 'amount' => 2500]], 2500, 'gift_card_cancellation', 'credit_note'], [$canceled['giftCard']['status'], $canceled['giftCard']['balance'], $canceled['refunds'], $canceled['cash'], $canceled['kind'], $canceled['receiptKind']]);
        $this->assertSame([7, 10], [(int) $this->db->fetchOne('SELECT status_id FROM "order" WHERE id = ?', [$sold['orderId']]), (int) $this->db->fetchOne('SELECT transaction_type_id FROM transaction_caisse WHERE order_caisse_id = ? ORDER BY id DESC LIMIT 1', [$sold['orderId']])]);
        $this->assertSame([404, 'GIFT_CARD_NOT_FOUND'], $this->error($this->request('POST', '/api/pos/gift-cards/' . $sold['giftCard']['code'] . '/cancel', ['cashRegisterId' => $this->register])), 'carte annulée : réponse uniforme, sans révéler son existence (S2)');
        $this->assertSame([409, 'GIFT_CARD_ORDER'], $this->error($this->request('POST', '/api/order/cancel/' . $sale['orderId'], ['paymentMethod' => 2, 'cashRegisterId' => $this->register])), 'une vente de carte ne s\'annule pas par /api/order/cancel');
        $this->token = null;
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->assertSame([409, 'GIFT_CARD_USED'], $this->error($this->request('POST', '/api/pos/gift-cards/' . $code . '/cancel', ['cashRegisterId' => $this->register])), 'carte ayant servi (même recréditée)');

        // Ajustement (responsable), remboursement du solde en espèces (seuil 5,00 $), remplacement d'une carte perdue
        $adj = $this->json($this->request('POST', '/api/pos/gift-cards/' . $pre['giftCard']['code'] . '/adjust', ['amount' => -2100, 'reason' => 'Erreur de montant à la vente', 'cashRegisterId' => $this->register]));
        $this->assertSame(400, $adj['giftCard']['balance']);
        $this->assertSame([422, 'VALIDATION_FAILED'], $this->error($this->request('POST', '/api/pos/gift-cards/' . $pre['giftCard']['code'] . '/adjust', ['amount' => 100, 'cashRegisterId' => $this->register])), 'motif requis');
        $this->assertSame([409, 'GIFT_CARD_CASH_OUT_FORBIDDEN'], $this->error($this->request('POST', '/api/pos/gift-cards/' . $code . '/cash-out', ['cashRegisterId' => $this->register])), '50,00 > seuil');
        $cashOut = $this->json($this->request('POST', '/api/pos/gift-cards/' . $pre['giftCard']['code'] . '/cash-out', ['cashRegisterId' => $this->register]));
        $this->assertSame([400, 400, 0, 'depleted'], [$cashOut['amount'], $cashOut['cash'], $cashOut['cashRounding'], $cashOut['giftCard']['status']]);
        $this->assertSame(10, (int) $this->db->fetchOne('SELECT transaction_type_id FROM transaction_caisse WHERE order_caisse_id IS NULL ORDER BY id DESC LIMIT 1'));
        $this->assertSame([404, 'GIFT_CARD_NOT_FOUND'], $this->error($this->request('POST', '/api/pos/gift-cards/replace', ['orderId' => $sale['orderId'], 'last4' => 'ZZZZ', 'cashRegisterId' => $this->register])));
        $rep = $this->json($this->request('POST', '/api/pos/gift-cards/replace', ['barcode' => $sale['barcode'], 'last4' => substr($code, -4), 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([5000, 'replaced', 0, $rep['giftCard']['id']], [$rep['giftCard']['balance'], $rep['replaced']['status'], $rep['replaced']['balance'], $rep['replaced']['replacedById']]);
        $this->assertNotSame($code, $rep['giftCard']['code']);
        $this->assertSame([404, 'GIFT_CARD_NOT_FOUND'], $this->error($this->request('GET', '/api/pos/gift-cards/' . $code)), 'carte remplacée : réponse uniforme (S2)');
        $this->assertSame(5000, $this->json($this->request('GET', '/api/pos/gift-cards/' . $rep['giftCard']['code']))['balance']);
        $this->assertSame(['GIFT_CARD_SOLD', 'GIFT_CARD_REDEEMED', 'GIFT_CARD_REFUNDED', 'STORE_CREDIT_ISSUED', 'GIFT_CARD_CANCELED', 'GIFT_CARD_ADJUSTED', 'GIFT_CARD_CASH_OUT', 'GIFT_CARD_REPLACED'],
            array_values(array_filter(['GIFT_CARD_SOLD', 'GIFT_CARD_REDEEMED', 'GIFT_CARD_REFUNDED', 'STORE_CREDIT_ISSUED', 'GIFT_CARD_CANCELED', 'GIFT_CARD_ADJUSTED', 'GIFT_CARD_CASH_OUT', 'GIFT_CARD_REPLACED'], fn ($t) => (int) $this->db->fetchOne('SELECT COUNT(*) FROM pos_audit_log WHERE type = ?', [$t]) > 0)), 'tout est journalisé');

        // Cashier : ajustement et remplacement exigent un responsable (C3)
        $this->loginAs(['ROLE_USER_POS']);
        $this->assertSame([403, 'APPROVAL_REQUIRED'], $this->error($this->request('POST', '/api/pos/gift-cards/' . $rep['giftCard']['code'] . '/adjust', ['amount' => -100, 'reason' => 'test', 'cashRegisterId' => $this->register])));
        $this->assertSame([403, 'APPROVAL_REQUIRED'], $this->error($this->request('POST', '/api/pos/gift-cards/replace', ['orderId' => $sale['orderId'], 'last4' => substr($rep['giftCard']['code'], -4), 'cashRegisterId' => $this->register])));

        // Hors ligne : refus clair ; pack : giftCards.offline false
        $this->db->executeStatement('UPDATE entreprise SET pos_offline_enabled = true');
        $pack = $this->json($this->request('GET', "/api/pos/offline-pack?cashRegisterId=$this->register"));
        $this->assertSame([true, false], [$pack['settings']['giftCards']['enabled'], $pack['settings']['giftCards']['offline']]);
        $seller = $this->user(['ROLE_USER_POS']);
        $r = PosOfflineCalculator::compute([['unitPrice' => 2900, 'quantity' => 1]], null, $pack['taxes'], $pack['site']['cashRoundingStep'], null);
        $offline = ['idempotencyKey' => self::uuid(), 'provisionalNumber' => "P-$this->terminalId-1", 'cashRegisterId' => $this->register, 'sellerId' => $seller->getId(), 'soldAt' => (new \DateTimeImmutable('-30 seconds'))->format(\DateTimeInterface::ATOM),
            'items' => $items, 'ticketDiscount' => null, 'payments' => [['type' => 4, 'amount' => $r['cashTotal'], 'code' => $rep['giftCard']['code']]], 'cashReceived' => null,
            'totals' => ['subtotal' => $r['subtotal'], 'taxes' => array_map(fn ($t) => ['label' => $t['label'], 'amount' => $t['amount']], $r['taxes']), 'total' => $r['total'], 'cashTotal' => $r['cashTotal'], 'cashRounding' => $r['cashRounding']]];
        $offline['chain'] = ['seq' => 1, 'prevHash' => '', 'hash' => PosOfflineSyncService::hashOf($offline, '')];
        $sync = $this->json($this->request('POST', '/api/pos/offline-sales', ['packVersion' => $pack['packVersion'], 'deviceTime' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), 'clockOffsetSeconds' => 0, 'sales' => [$offline]]));
        $this->assertSame(['rejected', 'OFFLINE_SALE_INVALID', false], [$sync['results'][0]['status'], $sync['results'][0]['error'], $sync['results'][0]['forceable']]);
        $this->assertStringContainsString('carte-cadeau', $sync['results'][0]['message']);
        $this->db->executeStatement('UPDATE entreprise SET pos_offline_enabled = false');

        // Anti-balayage : 20 consultations par terminal et 10 minutes, puis 429 (limite de production, testée)
        $status = [];
        for ($i = 0; $i < 25; $i++) {
            $status[] = $this->request('GET', '/api/pos/gift-cards/GC-ZZZZ-ZZZZ-ZZZ' . self::ALPHABET_SAMPLE[$i % 8])->getStatusCode();
        }
        $this->assertContains(429, $status, 'balayage de codes bloqué');
        $this->assertSame(429, end($status));
        $this->assertSame('GIFT_CARD_LOOKUP_LIMIT', json_decode($this->request('GET', '/api/pos/gift-cards/' . $rep['giftCard']['code'])->getContent(), true)['error']);
        $this->db->executeStatement('UPDATE entreprise SET pos_gift_card_enabled = false, pos_store_credit_enabled = false');
    }

    private const ALPHABET_SAMPLE = ['2', '3', '4', '5', '6', '7', '8', '9'];

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
        $key = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette cartes-cadeaux', 'sendEmail' => false]), 201)['key'];
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
        $email = 'cadeau-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Carte')->setLastname('Cadeau')->setRoles($roles)->setIsVerified(true);
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
