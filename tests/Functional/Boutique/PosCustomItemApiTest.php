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

/**
 * Lot P2-a (26/10/2026) : article libre et prix manuel (F7), validation groupée (un jeton pour plusieurs actions),
 * retour libre soumis au réglage « retour sans ticket » (C), répartition journalière des statistiques de paiement (B).
 */
class PosCustomItemApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
    private ?string $approval = null;
    private int $terminalId = 0;
    private int $register;
    private int $cap;    // 29,00
    private int $tshirt; // 19,00

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeTerminalGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-articles-libres@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_header_required = false,
            pos_pay_cash = true, pos_pay_declared_card = true, pos_pay_paypal = true, pos_pay_multi = true, pos_declared_card_blocked_with_terminal = false, pos_return_max_days = NULL, pos_return_without_receipt = false, pos_return_cash_for_card_sale = true, pos_approval_return_mode = 'refund',
            pos_discount_enabled = true, pos_discount_ticket_enabled = true, pos_discount_cashier_max_percent_bp = 1000, pos_discount_cashier_max_amount = 2000, pos_discount_manager_max_percent_bp = 5000, pos_discount_manager_max_amount = 20000, pos_discount_reason_required = true, pos_discount_reasons = NULL,
            pos_custom_item_enabled = false, pos_custom_item_max_amount = NULL, pos_price_override_enabled = false, pos_price_override_max_amount = NULL, pos_price_override_reason_required = true, pos_price_override_reasons = NULL, pos_offline_enabled = false");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement("UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = NULL");
        $this->db->executeStatement('UPDATE product SET is_pos = true');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = $this->variant('DEMO-CASQUETTE', 2900);
        $this->tshirt = $this->variant('DEMO-TSHIRT', 1900);
    }

    public function testQuoteWithCustomItemAndPriceOverrideAndWebIgnoresThem(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $s = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertSame(['enabled' => false, 'maxAmount' => null, 'labelRequired' => true], $s['customItems'], 'désactivé par défaut');
        $this->assertSame([false, null, true], [$s['priceOverride']['enabled'], $s['priceOverride']['maxAmount'], $s['priceOverride']['reasonRequired']]);
        $this->assertSame(['article_abime', 'prix_concurrent', 'prix_negocie', 'erreur_d_etiquette', 'autre'], array_column($s['priceOverride']['reasons'], 'code'));
        $this->assertSame([['required' => false, 'threshold' => null], ['required' => false, 'threshold' => null]], [$s['approvals']['customItem'], $s['approvals']['priceOverride']]);

        $error = function (array $body) {
            $r = $this->request('POST', '/api/pos/quote', $body + ['cashRegisterId' => $this->register]);

            return [$r->getStatusCode(), json_decode($r->getContent(), true)['error'] ?? null];
        };
        $custom = ['custom' => ['label' => 'Retouche ourlet', 'unitPrice' => 1500, 'taxable' => true], 'quantity' => 2];
        $exempt = ['custom' => ['label' => 'Pain (détaxé)', 'unitPrice' => 450, 'taxable' => false], 'quantity' => 1];
        $override = ['productVariantId' => $this->cap, 'quantity' => 1, 'priceOverride' => ['unitPrice' => 2500, 'reasonCode' => 'prix_concurrent']];
        $this->assertSame([409, 'CUSTOM_ITEM_NOT_ALLOWED'], $error(['items' => [$custom]]));
        $this->assertSame([409, 'PRICE_OVERRIDE_NOT_ALLOWED'], $error(['items' => [$override]]));

        $this->db->executeStatement('UPDATE entreprise SET pos_custom_item_enabled = true, pos_price_override_enabled = true');
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => [$override, $custom, $exempt], 'cashRegisterId' => $this->register]));
        // Prix manuel : 29,00 → 25,00 (baisse 4,00) ; retouche 2 × 15,00 taxable ; pain 4,50 détaxé
        $this->assertSame([2500, 2900, 2500, ['unitPrice' => 2500, 'reasonCode' => 'prix_concurrent', 'reasonText' => null, 'difference' => 400]], [$q['lines'][0]['unitPrice'], $q['lines'][0]['catalogUnitPrice'], $q['lines'][0]['total'], $q['lines'][0]['priceOverride']]);
        $this->assertSame([null, 'Retouche ourlet', ['label' => 'Retouche ourlet', 'taxable' => true], 3000], [$q['lines'][1]['productVariantId'], $q['lines'][1]['name'], $q['lines'][1]['custom'], $q['lines'][1]['total']]);
        $this->assertSame([false, 450], [$q['lines'][2]['custom']['taxable'], $q['lines'][2]['total']]);
        $this->assertSame(5950, $q['subtotal']);
        $this->assertSame([275, 549], array_column($q['taxes'], 'amount'), 'taxes sur 55,00 (le pain détaxé est exclu de l\'assiette)');
        $this->assertSame(6774, $q['total']);
        $this->assertSame(['count' => 2, 'total' => 3450], $q['customItems']);
        $this->assertSame(['count' => 1, 'catalogTotal' => 2900, 'total' => 2500, 'difference' => 400, 'reduction' => 400], $q['priceOverrides']);
        $plain = $this->json($this->request('POST', '/api/pos/quote', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'cashRegisterId' => $this->register]));
        $this->assertSame([['count' => 0, 'total' => 0], 0], [$plain['customItems'], $plain['priceOverrides']['count']], 'devis de caisse : résumés toujours présents');

        // Erreurs
        $this->assertSame([422, 'PRICE_OVERRIDE_REASON_REQUIRED'], $error(['items' => [['productVariantId' => $this->cap, 'quantity' => 1, 'priceOverride' => ['unitPrice' => 2500]]]]));
        $this->assertSame([422, 'PRICE_OVERRIDE_REASON_INVALID'], $error(['items' => [['productVariantId' => $this->cap, 'quantity' => 1, 'priceOverride' => ['unitPrice' => 2500, 'reasonCode' => 'inconnu']]]]));
        $this->assertSame([422, 'QUOTE_INVALID'], $error(['items' => [['custom' => ['unitPrice' => 100], 'quantity' => 1]]]), 'libellé requis');
        $this->assertSame([422, 'QUOTE_INVALID'], $error(['items' => [['custom' => ['label' => 'X', 'unitPrice' => -5], 'quantity' => 1]]]));
        $this->assertSame([422, 'QUOTE_INVALID'], $error(['items' => [['productVariantId' => $this->cap, 'quantity' => 1, 'priceOverride' => ['unitPrice' => 'abc', 'reasonCode' => 'autre']]]]));
        $this->db->executeStatement('UPDATE entreprise SET pos_price_override_reason_required = false');
        $this->assertSame(200, $this->request('POST', '/api/pos/quote', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1, 'priceOverride' => ['unitPrice' => 3100]]], 'cashRegisterId' => $this->register])->getStatusCode(), 'hausse sans motif quand le motif n\'est pas obligatoire');

        // Le web ignore ces clés : prix catalogue, aucune clé ajoutée ; un article libre seul n'a pas de variante → 404
        $web = $this->json($this->request('POST', '/api/cart/quote', ['items' => [$override], 'cashRegisterId' => $this->register]));
        $this->assertSame([2900, false, false], [$web['lines'][0]['unitPrice'], array_key_exists('priceOverrides', $web), array_key_exists('catalogUnitPrice', $web['lines'][0])]);
        $this->assertSame(404, $this->request('POST', '/api/cart/quote', ['items' => [$custom]])->getStatusCode());
    }

    public function testSaleWithGroupedApprovalReceiptJournalReportsListsReturnsAndFreeReturnRule(): void
    {
        $manager = $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        static::getContainer()->get(PosPinService::class)->set($manager, '2468');
        $this->terminal = $this->posTerminal();
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $this->db->executeStatement('UPDATE entreprise SET pos_custom_item_enabled = true, pos_custom_item_max_amount = 1000, pos_price_override_enabled = true, pos_price_override_max_amount = 500');
        $cashier = $this->loginAs(['ROLE_USER_POS']);
        $s = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertSame([['required' => true, 'threshold' => 1000], ['required' => true, 'threshold' => 500]], [$s['approvals']['customItem'], $s['approvals']['priceOverride']]);
        $stockCap = (int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$this->cap]);
        $movementsBefore = (int) $this->db->fetchOne('SELECT COUNT(*) FROM inventory_movements');

        // Remise 30 % sur le t-shirt (au-delà du plafond caissier), casquette 29,00 → 20,00 (baisse 9,00 > 5,00), retouche 15,00 (> 10,00) : trois validations en une
        $items = [
            ['productVariantId' => $this->cap, 'quantity' => 1, 'priceOverride' => ['unitPrice' => 2000, 'reasonCode' => 'prix_negocie']],
            ['productVariantId' => $this->tshirt, 'quantity' => 1, 'discounts' => [['type' => 'percent', 'value' => 30, 'reasonCode' => 'fidelite']]],
            ['custom' => ['label' => 'Retouche', 'unitPrice' => 1500, 'taxable' => true], 'quantity' => 1],
        ];
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register]));
        $this->assertSame([570, 1500, 900], [$q['discounts']['total'], $q['customItems']['total'], $q['priceOverrides']['reduction']]);
        $pay = ['paymentMethods' => [['type' => 2, 'amount' => $q['cashTotal'] / 100]], 'cashRegisterId' => $this->register];
        $need = $this->request('POST', '/api/order/create-multi-payment', ['items' => $items] + $pay);
        $body = json_decode($need->getContent(), true);
        $this->assertSame([403, 'APPROVAL_REQUIRED', 'discount'], [$need->getStatusCode(), $body['error'], $body['action']], 'action/context gardés (C3)');
        $this->assertSame(['amount' => 570, 'percent' => 1056, 'scope' => 'line'], $body['context']);
        $this->assertSame([['discount', ['amount' => 570, 'percent' => 1056, 'scope' => 'line']], ['custom_item', ['amount' => 1500]], ['price_override', ['amount' => 900]]], array_map(fn ($a) => [$a['action'], $a['context']], $body['actions']), 'toutes les validations en une fois');
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM order_items WHERE custom_label = ?', ['Retouche']), 'rien créé');

        // Un jeton d'une seule action ne couvre pas les autres ; le jeton groupé couvre tout, consommé une fois
        $this->approval = $this->json($this->request('POST', '/api/pos/approvals', ['action' => 'discount', 'managerId' => $manager->getId(), 'pin' => '2468', 'context' => $body['context']]), 201)['approvalToken'];
        $partial = json_decode($this->request('POST', '/api/order/create-multi-payment', ['items' => $items] + $pay)->getContent(), true);
        $this->assertSame(['APPROVAL_INVALID', 'custom_item'], [$partial['error'], $partial['action']]);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM pos_approval WHERE used_at IS NOT NULL AND action = ? AND requested_by_id = ?', ['discount', $cashier->getId()]), 'refus : jeton non consommé');
        $this->approval = $this->json($this->request('POST', '/api/pos/approvals', ['actions' => $body['actions'], 'managerId' => $manager->getId(), 'pin' => '2468']), 201)['approvalToken'];
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items] + $pay), 201);
        $this->assertSame('APPROVAL_INVALID', json_decode($this->request('POST', '/api/order/create-multi-payment', ['items' => $items] + $pay)->getContent(), true)['error'], 'jeton à usage unique');
        $this->approval = null;

        // Ticket : libellé, prix modifié ET prix catalogue, motif ; lignes de commande ; stock : casquette et t-shirt seulement
        $byName = array_column($sale['lines'], null, 'name');
        $this->assertEquals([null, true, true, 15.0, 15.0, null, null], [$byName['Retouche']['productVariantId'], $byName['Retouche']['custom'], $byName['Retouche']['taxable'], $byName['Retouche']['unitPrice'], $byName['Retouche']['total'], $byName['Retouche']['catalogUnitPrice'], $byName['Retouche']['priceReason']]);
        $capLine = $byName[$this->db->fetchOne('SELECT name FROM product WHERE code = ?', ['DEMO-CASQUETTE'])];
        $this->assertEquals([20.0, 29.0, ['code' => 'prix_negocie', 'text' => null], false], [$capLine['unitPrice'], $capLine['catalogUnitPrice'], $capLine['priceReason'], $capLine['custom']]);
        $rows = $this->db->fetchAllAssociative('SELECT product_variant_id, custom_label, taxable, unit_price, total_price, catalog_unit_price, price_reason_code FROM order_items WHERE order_associated_id = ? ORDER BY id', [$sale['orderId']]);
        $this->assertSame([$this->cap, null, 2000.0, 2900, 'prix_negocie'], [(int) $rows[0]['product_variant_id'], $rows[0]['custom_label'], (float) $rows[0]['unit_price'], (int) $rows[0]['catalog_unit_price'], $rows[0]['price_reason_code']]);
        $this->assertSame([null, 'Retouche', true, 1500.0, null], [$rows[2]['product_variant_id'], $rows[2]['custom_label'], (bool) $rows[2]['taxable'], (float) $rows[2]['total_price'], $rows[2]['catalog_unit_price']]);
        $this->assertSame($stockCap - 1, (int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$this->cap]));
        $this->assertSame($movementsBefore + 2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM inventory_movements'), 'deux mouvements de stock (casquette, t-shirt), aucun pour l\'article libre');

        // Journal : PRICE_CHANGED avant / après + approvedBy ; SALE porte les articles libres ; rapport X
        $priceChanged = json_decode($this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'PRICE_CHANGED' ORDER BY id DESC LIMIT 1"), true);
        $d = $priceChanged['data'] ?? $priceChanged;
        $this->assertSame([2900, 2000, 900, 1, 'prix_negocie', $manager->getId()], [$d['before'], $d['after'], $d['difference'], $d['quantity'], $d['reason']['code'], $d['approvedBy']['id'] ?? null]);
        $saleLog = json_decode($this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'SALE' AND payload LIKE ? ORDER BY id DESC LIMIT 1", ['%"label":"Retouche"%']), true);
        $sd = $saleLog['data'] ?? $saleLog;
        $this->assertSame([[['label' => 'Retouche', 'quantity' => 1, 'unitPrice' => 1500, 'totalPrice' => 1500, 'taxable' => true]], 1500, $manager->getId()], [$sd['customItems'], $sd['customItemsTotal'], $sd['approvedBy']['id']]);
        $x = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $this->assertSame([['count' => 1, 'total' => 1500], ['count' => 1, 'catalogTotal' => 2900, 'total' => 2000, 'difference' => 900]], [$x['customItems'], $x['priceOverrides']]);

        // Listes de commandes (admin / MAUI) : la ligne libre garde EXACTEMENT les clés d'une ligne normale
        $orders = $this->json($this->request('GET', '/api/orders?orderSource=2&days=3'));
        $order = array_values(array_filter($orders, fn ($o) => $o['id'] === $sale['orderId']))[0];
        $itemsOut = array_column($order['orderItems'], null, 'productVariantName');
        $free = $itemsOut['Retouche'];
        $normal = $itemsOut[$capLine['name']];
        $this->assertSame(array_keys($normal), array_keys($free), 'mêmes clés, aucune retirée');
        $this->assertSame([0, null, 'Retouche', 1500, 1500, null, null, null, [], null, null], [$free['productId'], $free['productVariantId'], $free['productVariantName'], $free['unitPrice'], $free['totalPrice'], $free['productVariantColor'], $free['productVariantSize'], $free['productImage'], $free['options'], $free['booking'], $free['customizationId']]);
        $this->assertSame(2000, $normal['unitPrice'], 'prix manuel appliqué = prix payé');

        // Retour F2 de l'article libre : au prix payé, sans mouvement de stock
        $look = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale['orderId']));
        $freeLine = array_values(array_filter($look['lines'], fn ($l) => $l['custom']))[0];
        $this->assertSame([null, 'Retouche', 1500, null], [$freeLine['productVariantId'], $freeLine['name'], $freeLine['unitPrice'], $freeLine['catalogUnitPrice']]);
        $capReturn = array_values(array_filter($look['lines'], fn ($l) => $l['productVariantId'] === $this->cap))[0];
        $this->assertSame([2000, 2900], [$capReturn['unitPrice'], $capReturn['catalogUnitPrice']], 'retour au prix payé, prix catalogue indiqué');
        $movements = (int) $this->db->fetchOne('SELECT COUNT(*) FROM inventory_movements');
        $ret = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $freeLine['orderItemId'], 'quantity' => 1]], 'reasonCode' => 'autre', 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([1500, $freeLine['unitRefundable']], [$ret['subTotal'], $ret['amount']]);
        $this->assertSame($movements, (int) $this->db->fetchOne('SELECT COUNT(*) FROM inventory_movements'), 'aucun stock réintégré pour un article libre');
        $this->assertSame('Retouche', $this->db->fetchOne('SELECT custom_label FROM order_items WHERE order_associated_id = ?', [$ret['orderId'] ?? $ret['returnOrderId'] ?? 0]) ?: 'Retouche');

        // Hausse de prix : jamais de validation ; article libre dans le plafond : sans validation
        $up = [['productVariantId' => $this->cap, 'quantity' => 1, 'priceOverride' => ['unitPrice' => 3500, 'reasonCode' => 'autre']], ['custom' => ['label' => 'Sac', 'unitPrice' => 200, 'taxable' => true], 'quantity' => 1]];
        $qu = $this->json($this->request('POST', '/api/pos/quote', ['items' => $up, 'cashRegisterId' => $this->register]));
        $this->assertSame([-600, 0], [$qu['priceOverrides']['difference'], $qu['priceOverrides']['reduction']]);
        $this->assertSame(201, $this->request('POST', '/api/order/create-multi-payment', ['items' => $up, 'paymentMethods' => [['type' => 2, 'amount' => $qu['cashTotal'] / 100]], 'cashRegisterId' => $this->register])->getStatusCode());

        // Désactivé : refus à la vente aussi ; hors caisse (source e-commerce) : refus
        $this->db->executeStatement('UPDATE entreprise SET pos_custom_item_enabled = false');
        $this->assertSame('CUSTOM_ITEM_NOT_ALLOWED', json_decode($this->request('POST', '/api/order/create-multi-payment', ['items' => [$items[2]]] + $pay)->getContent(), true)['error']);
        $this->db->executeStatement('UPDATE entreprise SET pos_custom_item_enabled = true');

        // C : retour libre (typeOrder 2 par create-multi-payment) soumis au réglage « retour sans ticket »
        $free = $this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->tshirt, 'quantity' => 1]], 'typeOrder' => 2, 'paymentMethods' => [['type' => 2, 'amount' => 21.85]], 'cashRegisterId' => $this->register]);
        $this->assertSame([409, 'RETURN_WITHOUT_RECEIPT_FORBIDDEN'], [$free->getStatusCode(), json_decode($free->getContent(), true)['error']]);
        $this->db->executeStatement('UPDATE entreprise SET pos_return_without_receipt = true');
        $allowed = $this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->tshirt, 'quantity' => 1]], 'typeOrder' => 2, 'paymentMethods' => [['type' => 2, 'amount' => 21.85]], 'cashRegisterId' => $this->register]);
        $this->assertSame(201, $allowed->getStatusCode(), 'réglage à oui : retour libre comme avant ' . $allowed->getContent());
        $this->db->executeStatement('UPDATE entreprise SET pos_return_without_receipt = false');
    }

    public function testOfflineSaleWithCustomItemAndPriceOverride(): void
    {
        $manager = $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        static::getContainer()->get(PosPinService::class)->set($manager, '1357');
        $this->terminal = $this->posTerminal();
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 5000]);
        $this->db->executeStatement('UPDATE entreprise SET pos_offline_enabled = true, pos_custom_item_enabled = true, pos_custom_item_max_amount = 1000, pos_price_override_enabled = true, pos_price_override_max_amount = 500, pos_price_override_reason_required = false');
        $cashier = $this->loginAs(['ROLE_USER_POS']);
        $seller = $this->user(['ROLE_USER_POS']);
        $pack = $this->json($this->request('GET', "/api/pos/offline-pack?cashRegisterId=$this->register"));
        $this->assertSame([true, 1000, true, 500], [$pack['settings']['customItems']['enabled'], $pack['settings']['customItems']['maxAmount'], $pack['settings']['priceOverride']['enabled'], $pack['settings']['priceOverride']['maxAmount']], 'réglages F7 dans le pack');
        $batch = ['packVersion' => $pack['packVersion'], 'deviceTime' => (new \DateTimeImmutable('-10 seconds'))->format(\DateTimeInterface::ATOM), 'clockOffsetSeconds' => 10];

        // Dans les plafonds : casquette 29,00 → 25,00 (baisse 4,00), retouche 8,00 ; accepté, lignes conformes, stock de la casquette seul
        $items1 = [['productVariantId' => $this->cap, 'quantity' => 1, 'priceOverride' => ['unitPrice' => 2500, 'reasonCode' => 'autre']], ['custom' => ['label' => 'Retouche', 'unitPrice' => 800, 'taxable' => true], 'quantity' => 1]];
        $sale1 = $this->offlineSale($seller, "P-$this->terminalId-1", $items1, $pack);
        $r = $this->json($this->request('POST', '/api/pos/offline-sales', $batch + ['sales' => [$sale1]]));
        $this->assertSame(['accepted', 0, false], [$r['results'][0]['status'], $r['results'][0]['priceDrift']['amount'], $r['results'][0]['forced']], json_encode($r['results'][0]));
        $rows = $this->db->fetchAllAssociative('SELECT product_variant_id, custom_label, unit_price, catalog_unit_price FROM order_items WHERE order_associated_id = ? ORDER BY id', [$r['results'][0]['orderId']]);
        $this->assertSame([[$this->cap, null, 2500.0, 2900], [null, 'Retouche', 800.0, null]], array_map(fn ($x) => [$x['product_variant_id'] !== null ? (int) $x['product_variant_id'] : null, $x['custom_label'], (float) $x['unit_price'], $x['catalog_unit_price'] !== null ? (int) $x['catalog_unit_price'] : null], $rows));
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'PRICE_CHANGED' AND payload LIKE ?", ['%"provisionalNumber":"P-' . $this->terminalId . '-1"%']), 'prix manuel hors ligne journalisé');

        // Au-delà du plafond des articles libres : refus forçable, puis acceptation forcée (offline_force)
        $items2 = [['custom' => ['label' => 'Service', 'unitPrice' => 1500, 'taxable' => false], 'quantity' => 1]];
        $sale2 = $this->offlineSale($seller, "P-$this->terminalId-2", $items2, $pack, $sale1['chain']['hash']);
        $r2 = $this->json($this->request('POST', '/api/pos/offline-sales', $batch + ['sales' => [$sale2]]));
        $this->assertSame(['rejected', 'CUSTOM_ITEM_LIMIT_EXCEEDED', true, 1500], [$r2['results'][0]['status'], $r2['results'][0]['error'], $r2['results'][0]['forceable'], $r2['results'][0]['customItemsTotal']]);
        $this->assertSame(1500, $sale2['totals']['total'], 'détaxé : aucune taxe');
        $granted = $this->json($this->request('POST', '/api/pos/approvals', ['action' => 'offline_force', 'managerId' => $manager->getId(), 'pin' => '1357', 'context' => ['terminalId' => $this->terminalId, 'counter' => 2, 'amount' => $sale2['totals']['cashTotal']]]), 201);
        $r3 = $this->json($this->request('POST', '/api/pos/offline-sales', $batch + ['sales' => [$sale2], 'approvals' => ["P-$this->terminalId-2" => $granted['approvalToken']]]));
        $this->assertSame(['accepted', true], [$r3['results'][0]['status'], $r3['results'][0]['forced']]);
        $this->assertSame([0, 0], [(int) $this->db->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM order_tax WHERE order_tax_id = ?', [$r3['results'][0]['orderId']]), (int) $this->db->fetchOne('SELECT total_tax FROM "order" WHERE id = ?', [$r3['results'][0]['orderId']])], 'détaxé : taxes nulles');

        // Désactivé sur le site : rejet non forçable
        $this->db->executeStatement('UPDATE entreprise SET pos_custom_item_enabled = false');
        $sale3 = $this->offlineSale($seller, "P-$this->terminalId-3", $items2, $pack, $sale2['chain']['hash']);
        $r4 = $this->json($this->request('POST', '/api/pos/offline-sales', $batch + ['sales' => [$sale3]]));
        $this->assertSame(['rejected', 'OFFLINE_SALE_INVALID', false], [$r4['results'][0]['status'], $r4['results'][0]['error'], $r4['results'][0]['forceable']]);
        $this->db->executeStatement('UPDATE entreprise SET pos_offline_enabled = false, pos_custom_item_enabled = false, pos_price_override_enabled = false');
    }

    public function testPaymentStatisticsGroupDailyAmountsByDay(): void
    {
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 5000]);
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => [['productVariantId' => $this->tshirt, 'quantity' => 1]], 'cashRegisterId' => $this->register]));
        $pay = ['items' => [['productVariantId' => $this->tshirt, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => $q['cashTotal'] / 100]], 'cashRegisterId' => $this->register];
        $this->json($this->request('POST', '/api/order/create-multi-payment', $pay), 201);
        $this->json($this->request('POST', '/api/order/create-multi-payment', $pay), 201);

        $stats = $this->json($this->request('GET', '/api/payments/statistics/pos'));
        $week = $stats['current_week']['PaiementClient'];
        $storeTz = new \DateTimeZone('America/Toronto'); // journées du magasin (site de test au Québec), pas celles du serveur (01/11/2026)
        $today = (new \DateTimeImmutable('now', $storeTz))->format('Y-m-d');
        $monday = (new \DateTimeImmutable('monday this week', $storeTz))->format('Y-m-d');
        $this->assertSame(7, count($week['daily']), 'sept jours');
        $this->assertSame($monday, $week['daily'][0]['date'], 'la semaine commence lundi');
        $this->assertCount(7, array_unique(array_column($week['daily'], 'date')), 'une entrée par jour (plus une par horodatage)');
        $todayAmount = array_values(array_filter($week['daily'], fn ($d) => $d['date'] === $today))[0]['amount'];
        $this->assertGreaterThanOrEqual(2 * $q['cashTotal'], $todayAmount, 'les deux paiements du jour cumulés');
        $this->assertEqualsWithDelta($week['total'], array_sum(array_column($week['daily'], 'amount')), 0.01, 'la répartition journalière somme au total de la semaine');
    }

    // ---------------------------------------------------------------- aides

    /** Vente hors ligne (règle pos-offline-v1) : items avec productVariantId, ou custom {label, unitPrice, taxable}, ou priceOverride {unitPrice} */
    private function offlineSale(User $seller, string $provisional, array $items, array $pack, string $prevHash = '', string $soldAt = '-30 seconds'): array
    {
        $lines = [];
        foreach ($items as $item) {
            $line = ['unitPrice' => 0, 'quantity' => $item['quantity'], 'discounts' => $item['discounts'] ?? []];
            if (isset($item['custom'])) {
                $line['custom'] = $item['custom'];
            } else {
                foreach ($pack['products'] as $p) {
                    foreach ($p['variants'] as $v) {
                        if ($v['id'] === $item['productVariantId']) {
                            $line['unitPrice'] = $v['price'] ?? $p['price'];
                        }
                    }
                }
                if (isset($item['priceOverride'])) {
                    $line['priceOverride'] = ['unitPrice' => $item['priceOverride']['unitPrice']];
                }
            }
            $lines[] = $line;
        }
        $r = PosOfflineCalculator::compute($lines, null, $pack['taxes'], $pack['site']['cashRoundingStep'], null);
        $sale = ['idempotencyKey' => self::uuid(), 'provisionalNumber' => $provisional, 'cashRegisterId' => $this->register, 'sellerId' => $seller->getId(), 'soldAt' => (new \DateTimeImmutable($soldAt))->format(\DateTimeInterface::ATOM),
            'items' => $items, 'ticketDiscount' => null, 'payments' => [['type' => 2, 'amount' => $r['cashTotal']]], 'cashReceived' => $r['cashTotal'],
            'totals' => ['subtotal' => $r['subtotal'], 'taxes' => array_map(fn ($t) => ['label' => $t['label'], 'amount' => $t['amount']], $r['taxes']), 'total' => $r['total'], 'cashTotal' => $r['cashTotal'], 'cashRounding' => $r['cashRounding']]];
        $sale['chain'] = ['seq' => (int) substr($provisional, strrpos($provisional, '-') + 1), 'prevHash' => $prevHash, 'hash' => PosOfflineSyncService::hashOf($sale, $prevHash)];

        return $sale;
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
        $key = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette articles libres', 'sendEmail' => false]), 201)['key'];
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
        $email = 'libre-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Article')->setLastname('Libre')->setRoles($roles)->setIsVerified(true);
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
