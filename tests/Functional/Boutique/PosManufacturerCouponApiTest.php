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
 * Lot F17 (01/11/2026) : coupons de fabricant en caisse — moyen de paiement (part type 6), taxes sur le prix plein, lecture des
 * codes-barres (GS1 DataBar 8110, UPC-A système 5), devis, vente, doublon de série, expiration, validation C3 au-delà du maximum,
 * terminal C4, retours (part coupon jamais remboursée, coupon annulé ou « retourné après réclamation »), annulation, rapports X,
 * réclamation par fabricant (JSON, CSV, marquage par lot), export comptable, hors ligne refusé.
 */
class PosManufacturerCouponApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    /** DataBar 8110 : préfixe 012345, offre 123456, 2,00 $ (code de valeur 0), expire le 31/12/2027, série 000123 */
    public const BARCODE_FIXED = '81100012345123456320011000032712315000012390000';
    /** DataBar 8110 : offre 654321, code de valeur 1 (article gratuit) : montant à saisir */
    public const BARCODE_FREE_ITEM = '811000123456543211011000091000';
    /** UPC-A système 5 : fabricant 12345, famille 123, code de valeur 25 : montant à saisir */
    public const BARCODE_UPC5 = '512345123257';
    /** DataBar 8110 expiré le 15/01/2020 */
    public const BARCODE_EXPIRED = '811000123451234563200110000320011550000124';

    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
    private ?string $approval = null;
    private int $register;
    private int $cap;   // 29,00 → 33,34 taxes comprises (TPS 5 %, TVQ 9,975 %)
    private int $total = 3334;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeTerminalGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-coupons@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false,
            pos_pay_cash = true, pos_pay_declared_card = true, pos_pay_paypal = true, pos_pay_multi = true, pos_declared_card_blocked_with_terminal = false, pos_return_max_days = NULL, pos_return_without_receipt = false, pos_return_cash_for_card_sale = true,
            pos_discount_enabled = true, pos_custom_item_enabled = false, pos_price_override_enabled = false, pos_offline_enabled = false, pos_gift_card_enabled = false, pos_store_credit_enabled = false,
            pos_coupon_enabled = false, pos_coupon_max_without_approval = 2000, pos_terminal_interac = NULL, pos_terminal_fee_card_bp = 100, pos_terminal_fee_interac_bp = 100");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement("UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, stripe_reader_label = NULL, stripe_reader_simulated = false, terminal_mode = NULL, receipt_prefix = NULL");
        $this->db->executeStatement('UPDATE stripe_config SET terminal_location_id = NULL');
        $this->db->executeStatement('UPDATE product SET is_pos = true');
        $this->db->executeStatement("UPDATE pos_manufacturer_coupon SET status = 'voided' WHERE status IN ('redeemed', 'claimed')"); // base partagée : les séries des exemples redeviennent libres
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = $this->variant('DEMO-CASQUETTE', 2900);
    }

    public function testCouponsAreAPaymentMethodTaxedOnFullPriceFromQuoteToClaims(): void
    {
        $manager = $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        static::getContainer()->get(PosPinService::class)->set($manager, '7788');
        $this->terminal = $this->posTerminal();
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 20000]);
        $cashier = $this->loginAs(['ROLE_USER_POS']);
        $items = [['productVariantId' => $this->cap, 'quantity' => 1]];
        $coupon = fn (array $c) => ['type' => 6, 'amount' => $c['amount'] / 100, 'coupon' => array_diff_key($c, ['amount' => 1])];

        // Réglages par défaut : coupons refusés
        $s = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertSame(['enabled' => false, 'maxWithoutApproval' => 2000, 'barcodeFormats' => ['gs1_databar_8110', 'upc_a_5'], 'taxOnFullPrice' => true, 'offline' => false], $s['coupons']);
        $this->assertSame(['required' => false, 'threshold' => 2000], $s['approvals']['manufacturerCoupon']);
        $this->assertSame([409, 'COUPON_DISABLED'], $this->error($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 31.35], $coupon(['amount' => 200, 'manufacturer' => 'Marque A'])], 'cashRegisterId' => $this->register])));
        $this->db->executeStatement('UPDATE entreprise SET pos_coupon_enabled = true');
        $this->assertSame(['required' => true, 'threshold' => 2000], $this->json($this->request('GET', '/api/pos/settings'))['approvals']['manufacturerCoupon']);

        // Lecture des trois exemples de codes-barres de la documentation
        $fixed = $this->json($this->request('POST', '/api/pos/coupons/parse', ['barcode' => self::BARCODE_FIXED]));
        $this->assertSame(['gs1_databar_8110', '012345', '123456', 200, 200, true, '2027-12-31', '000123', false, false], [$fixed['format'], $fixed['companyPrefix'], $fixed['offerCode'], $fixed['saveValue'], $fixed['amount'], $fixed['amountReliable'], $fixed['expiresAt'], $fixed['serial'], $fixed['expired'], $fixed['alreadyUsed']]);
        $free = $this->json($this->request('POST', '/api/pos/coupons/parse', ['barcode' => '(8110)' . substr(self::BARCODE_FREE_ITEM, 4)]));
        $this->assertSame(['012345', '654321', null, false, 'free_item', null], [$free['companyPrefix'], $free['offerCode'], $free['amount'], $free['amountReliable'], $free['valueCode'], $free['alreadyUsed']], 'article gratuit : montant à saisir, pas de série');
        $upc = $this->json($this->request('POST', '/api/pos/coupons/parse', ['barcode' => self::BARCODE_UPC5]));
        $this->assertSame(['upc_a_5', '12345', '123', '25', null, false], [$upc['format'], $upc['companyPrefix'], $upc['familyCode'], $upc['valueCode'], $upc['amount'], $upc['amountReliable']]);
        $this->assertSame([422, 'COUPON_BARCODE_INVALID'], $this->error($this->request('POST', '/api/pos/coupons/parse', ['barcode' => '512345123250'])), 'chiffre de contrôle faux');
        $this->assertSame([422, 'COUPON_BARCODE_INVALID'], $this->error($this->request('POST', '/api/pos/coupons/parse', ['barcode' => 'ABC'])));
        $this->assertTrue($this->json($this->request('POST', '/api/pos/coupons/parse', ['barcode' => self::BARCODE_EXPIRED]))['expired']);

        // Devis : taxes et total sur le prix plein, seul le dû diminue
        $plain = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register]));
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register, 'coupons' => [['barcode' => self::BARCODE_FIXED], ['amount' => 150, 'manufacturer' => 'Marque B', 'productVariantId' => $this->cap]]]));
        $this->assertSame([$plain['total'], $plain['taxes'], 3334], [$q['total'], $q['taxes'], $q['total']], 'devis identique hors coupons');
        $this->assertSame([2, 350, 3334 - 350, 2985], [$q['manufacturerCoupons']['count'], $q['manufacturerCoupons']['total'], $q['amountDue'], $q['cashAmountDue']]);
        $this->assertSame(['GS1 012345', 'scan', true], [$q['manufacturerCoupons']['coupons'][0]['manufacturer'], $q['manufacturerCoupons']['coupons'][0]['source'], $q['manufacturerCoupons']['coupons'][0]['amountReliable']]);
        $this->assertSame([422, 'COUPON_EXCEEDS_ITEM'], $this->error($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register, 'coupons' => [['amount' => 3000, 'manufacturer' => 'Marque B', 'productVariantId' => $this->cap]]])), 'coupon > prix de l\'article');
        $this->assertSame([422, 'COUPON_EXCEEDS_DUE'], $this->error($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register, 'coupons' => [['amount' => 2000, 'manufacturer' => 'A'], ['amount' => 2000, 'manufacturer' => 'B']]])));
        $this->assertSame([422, 'COUPON_AMOUNT_INVALID'], $this->error($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register, 'coupons' => [['barcode' => self::BARCODE_FREE_ITEM]]])), 'montant non lisible et non saisi');
        $this->assertSame([422, 'COUPON_EXPIRED'], $this->error($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register, 'coupons' => [['barcode' => self::BARCODE_EXPIRED, 'amount' => 200]]])));
        $this->assertSame([422, 'COUPON_EXPIRED'], $this->error($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register, 'coupons' => [['amount' => 200, 'manufacturer' => 'C', 'expiresAt' => '2020-01-01']]])), 'date saisie à la main');

        // Vente : coupon scanné 2,00 $ + espèces ; taxes sur 29,00 ; ticket avec la ligne coupon
        $taxesPlain = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 33.35]], 'cashRegisterId' => $this->register]), 201)['totalTax'];
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 31.35], $coupon(['amount' => 200, 'barcode' => self::BARCODE_FIXED, 'productVariantId' => $this->cap])], 'cashRegisterId' => $this->register]), 201);
        $this->assertEquals([33.34, $taxesPlain, 2.0, 31.35], [$sale['totalAmount'], $sale['totalTax'], $sale['manufacturerCouponApplied'], $sale['amountDue']], 'taxes du prix plein, dû diminué du coupon (+ arrondi des espèces)');
        $couponLine = array_values(array_filter($sale['payments'], fn ($p) => $p['type'] === 6))[0];
        $this->assertEquals(['Coupon fabricant – GS1 012345 (offre 123456)', 2.0], [$couponLine['label'], $couponLine['amount']]);
        $row = $this->db->fetchAssociative('SELECT manufacturer, company_prefix, offer_code, serial, amount, status, source, format, expires_at, order_item_id FROM pos_manufacturer_coupon WHERE order_id = ?', [$sale['orderId']]);
        $this->assertSame(['GS1 012345', '012345', '123456', '000123', 200, 'redeemed', 'scan', 'gs1_databar_8110', '2027-12-31'], [$row['manufacturer'], $row['company_prefix'], $row['offer_code'], $row['serial'], (int) $row['amount'], $row['status'], $row['source'], $row['format'], $row['expires_at']]);
        $this->assertNotNull($row['order_item_id'], 'coupon rattaché à la ligne');
        $this->assertSame([200, 434], [(int) $this->db->fetchOne('SELECT manufacturer_coupon_applied FROM "order" WHERE id = ?', [$sale['orderId']]), (int) $this->db->fetchOne('SELECT SUM(amount) FROM order_tax WHERE order_tax_id = ?', [$sale['orderId']])], 'taxes de 29,00 inchangées en base');
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM payments WHERE order_payment_id = ? AND payment_method_id = 6', [$sale['orderId']]), 'aucune ligne de paiement pour le coupon');
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'MANUFACTURER_COUPON_REDEEMED' AND payload LIKE ?", ['%"serial":"000123"%']));
        $this->assertTrue($this->json($this->request('POST', '/api/pos/coupons/parse', ['barcode' => self::BARCODE_FIXED]))['alreadyUsed']);

        // Même coupon sérialisé une seconde fois : refusé et journalisé ; sans série : accepté
        $this->assertSame([409, 'COUPON_ALREADY_USED'], $this->error($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 31.35], $coupon(['amount' => 200, 'barcode' => self::BARCODE_FIXED])], 'cashRegisterId' => $this->register])));
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'MANUFACTURER_COUPON_REFUSED' AND payload LIKE '%\"reason\":\"already_used\"%' AND payload LIKE ?", ['%"serial":"000123"%']));
        $this->assertSame([422, 'COUPON_EXPIRED'], $this->error($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 31.35], $coupon(['amount' => 200, 'barcode' => self::BARCODE_EXPIRED])], 'cashRegisterId' => $this->register])));
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'MANUFACTURER_COUPON_REFUSED' AND payload LIKE '%\"reason\":\"expired\"%' AND payload LIKE '%\"inSale\":true%'"), 'refus en vente journalisé malgré le retour arrière (les refus au devis le sont aussi)');
        $this->assertSame([400, 'PAYMENT_METHODS_INVALID'], $this->error($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 31.35], ['type' => 6, 'amount' => 2]], 'cashRegisterId' => $this->register])), 'coupon sans fabricant ni code-barres');

        // Au-delà de 20 $ : validation C3 action manufacturer_coupon {amount} ; responsable : direct
        $need = $this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 8.35], $coupon(['amount' => 2500, 'manufacturer' => 'Marque C', 'offerCode' => 'ETE26'])], 'cashRegisterId' => $this->register]);
        $body = json_decode($need->getContent(), true);
        $this->assertSame([403, 'APPROVAL_REQUIRED', 'manufacturer_coupon', ['amount' => 2500]], [$need->getStatusCode(), $body['error'], $body['action'], $body['context']]);
        $this->approval = $this->json($this->request('POST', '/api/pos/approvals', ['action' => 'manufacturer_coupon', 'managerId' => $manager->getId(), 'pin' => '7788', 'context' => ['amount' => 2500]]), 201)['approvalToken'];
        $big = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 8.35], $coupon(['amount' => 2500, 'manufacturer' => 'Marque C', 'offerCode' => 'ETE26'])], 'cashRegisterId' => $this->register]), 201);
        $this->approval = null;
        $this->assertEquals(25.0, $big['manufacturerCouponApplied']);
        // Validation groupée (P2-a) : remise + coupon demandés en une fois
        $discountMax = $this->db->fetchOne('SELECT pos_discount_cashier_max_amount FROM entreprise');
        $this->db->executeStatement('UPDATE entreprise SET pos_discount_cashier_max_amount = 0');
        $grouped = json_decode($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1, 'discounts' => [['type' => 'amount', 'value' => 100, 'reasonCode' => 'autre']]]], 'paymentMethods' => [['type' => 2, 'amount' => 7.2], $coupon(['amount' => 2500, 'manufacturer' => 'Marque C'])], 'cashRegisterId' => $this->register])->getContent(), true);
        $this->assertSame(['discount', 'manufacturer_coupon'], array_column($grouped['actions'] ?? [], 'action'), 'remise et coupon dans la même demande');
        $this->db->executeStatement('UPDATE entreprise SET pos_discount_cashier_max_amount = ?', [$discountMax]); // valeur d'avant rétablie (base partagée)

        // Terminal C4 : part coupon dans otherPayments, part carte = total − coupon
        $this->configureSimulatedReader();
        $tp = $this->json($this->request('POST', '/api/pos/terminal/payments', ['cashRegisterId' => $this->register, 'amount' => $this->total - 500, 'items' => $items, 'otherPayments' => [['type' => 6, 'amount' => 500]]]), 201);
        $this->assertSame(['waiting_for_card', $this->total - 500], [$tp['status'], $tp['amount']]);
        $this->json($this->request('POST', '/api/pos/terminal/payments/' . $tp['paymentId'] . '/cancel', []));

        // Retour F2 : le client récupère ce qu'il a payé ; la part coupon n'est jamais rendue ; coupon annulé (non réclamé)
        $look = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale['orderId']));
        $this->assertSame([200, 1, 'redeemed'], [$look['payments']['manufacturerCoupons']['total'], count($look['payments']['manufacturerCoupons']['items']), $look['payments']['manufacturerCoupons']['items'][0]['status']]);
        $ret = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $look['lines'][0]['orderItemId'], 'quantity' => 1]], 'reasonCode' => 'autre', 'refund' => ['method' => 'cash'], 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([3334, 3135, 200, 200, 0], [$ret['amount'], $ret['refund']['cash'], $ret['manufacturerCoupons']['notRefunded'], $ret['manufacturerCoupons']['voided'], $ret['manufacturerCoupons']['returnedAfterClaim']], 'remboursé 31,35 (payé), coupon de 2,00 annulé');
        $this->assertSame('voided', $this->db->fetchOne('SELECT status FROM pos_manufacturer_coupon WHERE order_id = ?', [$sale['orderId']]));
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'MANUFACTURER_COUPON_VOIDED' AND payload LIKE ?", ['%"subject":{"type":"order","id":' . $sale['orderId'] . '}%']), 'annulation du coupon journalisée');
        $this->assertFalse($this->json($this->request('POST', '/api/pos/coupons/parse', ['barcode' => self::BARCODE_FIXED]))['alreadyUsed'], 'série libérée par le retour avant réclamation');

        // Rapport X : bloc coupons
        $x = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $this->assertSame(['count' => 2, 'total' => 2700, 'voided' => 200], $x['manufacturerCoupons']);
        $this->assertSame(2700, $x['sales']['manufacturerCoupons']);

        // Réclamation : responsable seulement ; par fabricant ; CSV ; marquage par lot ; retour après réclamation
        $from = date('Y-m-d', strtotime('-2 days')); // période large : la journée du magasin (America/Toronto) n'est pas celle du serveur (UTC) ; -2 jours pour ne jamais partager le « from » d'un autre test (journal EXPORT_GENERATED compté en absolu ailleurs)
        $to = date('Y-m-d', strtotime('+1 day'));
        $this->assertSame([403, 'FORBIDDEN'], $this->error($this->request('GET', "/api/pos/coupons/claims?from=$from&to=$to")));
        $this->assertSame([403, 'FORBIDDEN'], $this->error($this->request('POST', '/api/pos/coupons/claims/mark', ['from' => $from, 'to' => $to])));
        $this->token = null;
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $claims = $this->json($this->request('GET', "/api/pos/coupons/claims?from=$from&to=$to"));
        $this->assertSame([1, 2500, 'to_claim', ['Marque C']], [$claims['count'], $claims['total'], $claims['status'], array_column($claims['byManufacturer'], 'manufacturer')], 'le coupon annulé n\'est plus à réclamer');
        $this->assertSame([119, 227], [$claims['taxFractions']['TPS'], $claims['taxFractions']['TVQ']], 'fractions de taxe 25,00 × 5/105 et × 9,975/109,975');
        $csv = $this->request('GET', "/api/pos/coupons/claims.csv?from=$from&to=$to");
        $this->assertSame(200, $csv->getStatusCode());
        $this->assertStringStartsWith("\xEF\xBB\xBFFabricant;", $csv->getContent());
        $this->assertStringContainsString('"Marque C";;ETE26;;', $csv->getContent());
        $this->assertStringContainsString('"Fraction TPS";"Fraction TVQ"', explode("\n", $csv->getContent())[0]);
        $this->assertStringNotContainsString('TVH', $csv->getContent(), 'seules les taxes facturées sur la vente');
        $mark = $this->json($this->request('POST', '/api/pos/coupons/claims/mark', ['from' => $from, 'to' => $to]));
        $this->assertSame([1, 2500, ['Marque C' => 2500]], [$mark['count'], $mark['total'], $mark['byManufacturer']]);
        $this->assertStringStartsWith('CL-', $mark['batch']);
        $this->assertSame([409, 'NOTHING_TO_CLAIM'], $this->error($this->request('POST', '/api/pos/coupons/claims/mark', ['from' => $from, 'to' => $to])));
        $this->assertSame(1, $this->json($this->request('GET', "/api/pos/coupons/claims?from=$from&to=$to&status=claimed"))['count']);
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'MANUFACTURER_COUPONS_CLAIMED' AND payload LIKE ?", ['%' . $mark['batch'] . '%']));
        $cancel = $this->request('POST', '/api/order/cancel/' . $big['orderId'], ['paymentMethod' => 2, 'cashRegisterId' => $this->register]);
        $this->assertSame(200, $cancel->getStatusCode(), $cancel->getContent());
        $this->assertSame('returned_after_claim', $this->db->fetchOne('SELECT status FROM pos_manufacturer_coupon WHERE order_id = ?', [$big['orderId']]), 'déjà réclamé : à régulariser');
        $this->assertSame(-835, (int) $this->db->fetchOne('SELECT SUM(amount) FROM payments WHERE order_payment_id = ? AND amount < 0', [$big['orderId']]), 'annulation : seuls les 8,35 payés sont rendus');
        $this->assertSame(1, $this->json($this->request('GET', "/api/pos/coupons/claims?from=$from&to=$to&status=returned_after_claim"))['count']);

        // Clôture et export comptable : compte « Coupons de fabricant à recevoir », colonnes de synthèse
        $x = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $this->json($this->request('POST', '/api/caisse/close', ['cashRegisterId' => $this->register, 'countedCash' => $x['expectedCash'], 'keptFund' => 0]));
        $e = $this->json($this->request('GET', "/api/pos/exports/accounting?from=$from&to=$to&cashRegisterId=$this->register"));
        $couponLines = array_values(array_filter($e['entries'], fn ($l) => $l['accountKey'] === 'coupon_receivable'));
        $this->assertSame(['1050', 2700, 2700], [$couponLines[0]['account'], (int) array_sum(array_column($couponLines, 'debit')), (int) array_sum(array_column($couponLines, 'credit'))], 'acceptés au débit, annulés au crédit');
        $this->assertTrue($e['totals']['balanced']);
        $summary = $this->request('GET', "/api/pos/exports/accounting/summary.csv?from=$from&to=$to&cashRegisterId=$this->register")->getContent();
        $this->assertStringContainsString(';"Coupons fabricant acceptés";"Coupons fabricant annulés"', explode("\n", $summary)[0]);
        $this->assertSame(['1050', 'Coupons de fabricant à recevoir'], array_values(array_intersect_key($this->json($this->request('GET', '/api/pos/settings'))['accounting']['accounts']['coupon_receivable'], ['number' => 1, 'name' => 1])));

        // Hors ligne : part de type 6 refusée à la synchronisation
        $this->db->executeStatement('UPDATE entreprise SET pos_offline_enabled = true');
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $pack = $this->json($this->request('GET', "/api/pos/offline-pack?cashRegisterId=$this->register"));
        $r = \App\Services\PosOfflineService\PosOfflineCalculator::compute([['unitPrice' => 2900, 'quantity' => 1]], null, $pack['taxes'], $pack['site']['cashRoundingStep'], null);
        $offline = ['idempotencyKey' => self::uuid(), 'provisionalNumber' => 'P-1-901', 'cashRegisterId' => $this->register, 'sellerId' => $cashier->getId(), 'soldAt' => (new \DateTimeImmutable('-30 seconds'))->format(\DateTimeInterface::ATOM),
            'items' => $items, 'ticketDiscount' => null, 'payments' => [['type' => 2, 'amount' => $r['cashTotal'] - 200], ['type' => 6, 'amount' => 200]], 'cashReceived' => $r['cashTotal'] - 200,
            'totals' => ['subtotal' => $r['subtotal'], 'taxes' => array_map(fn ($t) => ['label' => $t['label'], 'amount' => $t['amount']], $r['taxes']), 'total' => $r['total'], 'cashTotal' => $r['cashTotal'], 'cashRounding' => $r['cashRounding']]];
        $offline['chain'] = ['seq' => 1, 'prevHash' => '', 'hash' => \App\Services\PosOfflineService\PosOfflineSyncService::hashOf($offline, '')];
        $sync = $this->json($this->request('POST', '/api/pos/offline-sales', ['packVersion' => $pack['packVersion'], 'deviceTime' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), 'clockOffsetSeconds' => 0, 'sales' => [$offline]]));
        $this->assertSame(['rejected', 'OFFLINE_SALE_INVALID'], [$sync['results'][0]['status'], $sync['results'][0]['error']]);
        $this->assertStringContainsString('coupons de fabricant indisponibles hors ligne', json_encode($sync['results'][0], JSON_UNESCAPED_UNICODE));
        $this->db->executeStatement('UPDATE entreprise SET pos_offline_enabled = false, pos_coupon_enabled = false');
    }

    private function error(Response $r): array
    {
        return [$r->getStatusCode(), json_decode($r->getContent(), true)['error'] ?? null];
    }

    private function variant(string $code, int $price): int
    {
        $id = (int) $this->db->fetchOne('SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = ? ORDER BY v.id LIMIT 1', [$code]);
        $this->db->executeStatement('UPDATE product SET price = ?, special_price = NULL WHERE code = ?', [$price, $code]);
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500, price = NULL WHERE id = ?', [$id]);

        return $id;
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
        $key = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette coupons', 'sendEmail' => false]), 201)['key'];
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
            'HTTP_X_POS_TERMINAL' => $this->terminal, 'HTTP_X_POS_APPROVAL' => $this->approval,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): User
    {
        $email = 'coupon-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Coupon')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $a = $this->approval;
        $this->approval = null;
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $email, 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
        $this->approval = $a;

        return $user;
    }
}
