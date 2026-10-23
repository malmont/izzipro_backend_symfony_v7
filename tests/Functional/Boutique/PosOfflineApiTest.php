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
 * Chantier C5 partie 2 (25/10/2026) : mode hors ligne. Pack (ETag, produits, codes-barres, taxes, réglages), jeu de cas
 * partagé rejoué, synchronisation d'un lot (accepté, doublon, rejets, acceptation forcée C3, recalcul au pack déclaré,
 * écart courant informatif, stock négatif, saisie tardive, chaînage), ticket, journal, rapports.
 */
class PosOfflineApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
    private int $terminalId = 0;
    private int $register;
    private int $cap;
    private int $tshirt;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeTerminalGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-horsligne@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_header_required = false,
            pos_pay_cash = true, pos_discount_enabled = true, pos_discount_reason_required = true,
            pos_offline_enabled = false, pos_offline_max_hours = 24, pos_offline_max_queued_sales = 100, pos_offline_max_queued_amount = 500000, pos_offline_price_tolerance = 5, pos_offline_pack_validity_hours = 24");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement("UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = 'C1-'");
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = $this->variant('DEMO-CASQUETTE', 2900);
        $this->tshirt = $this->variant('DEMO-TSHIRT', 1900);
        $this->db->executeStatement("UPDATE product SET is_pos = true WHERE code LIKE 'DEMO-%'");
        $this->db->executeStatement("UPDATE product_variant SET barcode = '2000000000015' WHERE id = ?", [$this->cap]);
        $this->db->executeStatement("UPDATE product SET barcode = '2000000000022' WHERE code = 'DEMO-TSHIRT'");
    }

    public function testSharedCasesAreReproducedByTheServer(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $cases = $this->json($this->request('GET', '/api/pos/offline-cases'));
        $this->assertSame(PosOfflineCalculator::RULE, $cases['rule']);
        $this->assertGreaterThanOrEqual(10, count($cases['cases']));
        foreach ($cases['cases'] as $case) {
            $r = PosOfflineCalculator::compute($case['lines'], $case['ticketDiscount'], $case['taxes'], $case['step'], $case['cashReceived']);
            $got = ['lines' => $r['lines'], 'subtotal' => $r['subtotal'], 'discountTotal' => $r['discountTotal'], 'taxes' => array_map(fn ($t) => ['label' => $t['label'], 'amount' => $t['amount']], $r['taxes']),
                'total' => $r['total'], 'cashTotal' => $r['cashTotal'], 'cashRounding' => $r['cashRounding'], 'change' => $r['change']];
            if (array_key_exists('taxableSubtotal', $case['expected'])) { // cas F7 (article libre, prix manuel) : assiette taxable exposée
                $got['taxableSubtotal'] = $r['taxableSubtotal'];
            }
            ksort($got);
            $expected = $case['expected'];
            ksort($expected);
            $this->assertSame($expected, $got, 'cas ' . $case['id']);
        }
        // La règle hors ligne donne le même total que le devis de caisse du serveur (même site, mêmes taxes)
        $quote = $this->json($this->request('POST', '/api/pos/quote', ['items' => [['productVariantId' => $this->cap, 'quantity' => 2, 'discounts' => [['type' => 'percent', 'value' => 10, 'reasonCode' => 'fidelite']]]], 'ticketDiscount' => ['type' => 'amount', 'value' => 300, 'reasonCode' => 'autre'], 'cashRegisterId' => $this->register]));
        $offline = PosOfflineCalculator::compute([['unitPrice' => 2900, 'quantity' => 2, 'discounts' => [['type' => 'percent', 'value' => 10]]]], ['type' => 'amount', 'value' => 300], [['label' => 'TPS', 'rate' => 0.05], ['label' => 'TVQ', 'rate' => 0.09975]], 5);
        $this->assertSame([$quote['total'], $quote['cashTotal'], array_column($quote['taxes'], 'amount')], [$offline['total'], $offline['cashTotal'], array_column($offline['taxes'], 'amount')]);
    }

    public function testPackSyncDriftForceStockLateEntryAndReports(): void
    {
        $manager = $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        static::getContainer()->get(PosPinService::class)->set($manager, '4321');
        $this->terminal = $this->posTerminal();
        $cashier = $this->loginAs(['ROLE_USER_POS']);
        $seller = $this->user(['ROLE_USER_POS']); // vendeur réel au moment des ventes hors ligne

        // Pack : désactivé par défaut, ETag et 304
        $s = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertSame([false, 'DISABLED', 5, 24], [$s['offline']['enabled'], $s['offline']['reason'], $s['offline']['priceTolerance'], $s['offline']['packValidityHours']]);
        $packResponse = $this->request('GET', "/api/pos/offline-pack?cashRegisterId=$this->register");
        $pack = $this->json($packResponse);
        $etag = $packResponse->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertSame(304, $this->request('GET', "/api/pos/offline-pack?cashRegisterId=$this->register", null, ['HTTP_IF_NONE_MATCH' => $etag])->getStatusCode(), 'pack inchangé : 304');
        $this->assertSame(304, $this->request('GET', "/api/pos/offline-pack?cashRegisterId=$this->register", null, ['HTTP_IF_NONE_MATCH' => 'W/' . $etag])->getStatusCode(), 'ETag faible (relais) accepté');
        $this->assertSame(['CAD', 'table', 5, 'QC'], [$pack['site']['currency'], $pack['site']['taxProvider'], $pack['site']['cashRoundingStep'], $pack['site']['province']]);
        $this->assertSame([['TPS', 0.05], ['TVQ', 0.09975]], array_map(fn ($t) => [$t['label'], $t['rate']], $pack['taxes']));
        $capProduct = array_values(array_filter($pack['products'], fn ($p) => $p['code'] === 'DEMO-CASQUETTE'))[0];
        $tshirtProduct = array_values(array_filter($pack['products'], fn ($p) => $p['code'] === 'DEMO-TSHIRT'))[0];
        $this->assertSame([2900, '2000000000015'], [$capProduct['price'], $capProduct['variants'][0]['barcode']], 'code-barres de variante');
        $this->assertSame('2000000000022', $tshirtProduct['barcode'], 'code-barres produit existant');
        $this->assertSame(PosOfflineCalculator::RULE, $pack['calculationRule']);
        $this->assertFalse($pack['settings']['offline']['enabled']);
        $this->assertTrue(array_values(array_filter($pack['paymentMethods'], fn ($m) => $m['type'] === 2))[0]['offline']);
        $this->assertNotEmpty($pack['denominations']);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM pos_offline_pack WHERE version = ?', [$pack['packVersion']]), 'instantané conservé');

        // Synchronisation refusée tant que le mode est désactivé ; terminal obligatoire
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $sale = $this->offlineSale($seller, 'P-' . $this->terminalId . '-1', [['productVariantId' => $this->cap, 'quantity' => 2]], $pack);
        $syncLogsBefore = (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'OFFLINE_SYNC'"); // base partagée entre tests
        $batch = ['packVersion' => $pack['packVersion'], 'deviceTime' => (new \DateTimeImmutable('-37 seconds'))->format(\DateTimeInterface::ATOM), 'clockOffsetSeconds' => 37]; // sans 'sales' : ajouté par lot ($batch + [...])
        $first = $batch + ['sales' => [$sale]];
        $this->assertSame('TERMINAL_REQUIRED', json_decode($this->requestNoTerminal('POST', '/api/pos/offline-sales', $first)->getContent(), true)['error']);
        $r = $this->json($this->request('POST', '/api/pos/offline-sales', $first));
        $this->assertSame(['rejected', 'OFFLINE_DISABLED', true], [$r['results'][0]['status'], $r['results'][0]['error'], $r['results'][0]['forceable'] === false]);

        // Activé : vente acceptée (numéro officiel, provisoire conservé, date corrigée, stock, journal)
        $this->db->executeStatement('UPDATE entreprise SET pos_offline_enabled = true');
        $stockBefore = (int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$this->cap]);
        $r = $this->json($this->request('POST', '/api/pos/offline-sales', $first));
        $a = $r['results'][0];
        $this->assertSame(['accepted', 'P-' . $this->terminalId . '-1', 0, true, false, false], [$a['status'], $a['provisionalNumber'], $a['priceDrift']['amount'], $a['priceDrift']['within'], $a['lateEntry'], $a['forced']], json_encode($a));
        $this->assertSame(["IZ-T-$this->register-" . $a['receiptNumber'], 'C1-' . $a['receiptNumber']], [$a['barcode'], $a['receiptReference']]);
        $this->assertSame($stockBefore - 2, (int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$this->cap]));
        $order = $this->db->fetchAssociative('SELECT user_id_id, total_amount, cash_rounding, synced_at, order_date FROM "order" WHERE id = ?', [$a['orderId']]);
        $this->assertSame([$seller->getId(), 6669.0, 1], [(int) $order['user_id_id'], (float) $order['total_amount'], (int) $order['cash_rounding']], 'vendeur réel, montants perçus');
        $this->assertNotNull($order['synced_at']);
        $this->assertEqualsWithDelta(new \DateTimeImmutable($sale['soldAt']), new \DateTimeImmutable($order['order_date']), 60, 'order_date = heure de vente corrigée (37 s)');
        $this->assertSame('P-' . $this->terminalId . '-1', $this->db->fetchOne('SELECT provisional_number FROM pos_receipt WHERE order_id = ?', [$a['orderId']]));
        $dup = $this->json($this->request('GET', '/api/pos/receipts?barcode=' . $a['barcode']));
        $this->assertSame('P-' . $this->terminalId . '-1', $dup['provisionalNumber'], 'réimpression : numéro provisoire conservé');
        $journal = json_decode($this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'SALE' AND payload LIKE ? ORDER BY id DESC LIMIT 1", ['%"provisionalNumber":"' . $a['provisionalNumber'] . '"%']), true);
        $data = $journal['data'] ?? $journal;
        $this->assertSame(['P-' . $this->terminalId . '-1', $pack['packVersion'], $cashier->getId()], [$data['offline']['provisionalNumber'], $data['offline']['packVersion'], $data['syncedBy']['id']]);
        $this->assertSame($syncLogsBefore + 2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'OFFLINE_SYNC'"), 'un lot journalisé par synchronisation, refusée ou acceptée');

        // Doublon : même clé → même réponse, aucune seconde commande
        $orders = (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"');
        $again = $this->json($this->request('POST', '/api/pos/offline-sales', $first));
        $this->assertSame(['duplicate', $a['orderId']], [$again['results'][0]['status'], $again['results'][0]['orderId']]);
        $this->assertSame($orders, (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"'));

        // Changement de prix légitime après le pack : recalcul au pack déclaré → aucun écart, écart courant informatif
        $this->db->executeStatement("UPDATE product SET price = 3100 WHERE code = 'DEMO-CASQUETTE'");
        $sale2 = $this->offlineSale($seller, 'P-' . $this->terminalId . '-2', [['productVariantId' => $this->cap, 'quantity' => 1]], $pack, $sale['chain']['hash']);
        $r = $this->json($this->request('POST', '/api/pos/offline-sales', $batch + ['sales' => [$sale2]]));
        $b = $r['results'][0];
        $this->assertSame(['accepted', 0, true, false], [$b['status'], $b['priceDrift']['amount'], $b['priceDrift']['within'], $b['chainBroken']]);
        $this->assertSame(3334 - (int) round(3100 * 1.14975), $b['currentPriceDrift'], 'écart avec le prix courant, à titre d\'information');

        // Écart réel au-delà de la tolérance : rejeté, puis accepté de force avec validation du responsable
        $sale3 = $this->offlineSale($seller, 'P-' . $this->terminalId . '-3', [['productVariantId' => $this->cap, 'quantity' => 1]], $pack, $sale2['chain']['hash']);
        $sale3['totals']['total'] -= 50;
        $sale3['totals']['cashTotal'] -= 50;
        $sale3['payments'][0]['amount'] -= 50;
        $r = $this->json($this->request('POST', '/api/pos/offline-sales', $batch + ['sales' => [$sale3]]));
        $c = $r['results'][0];
        $this->assertSame(['rejected', 'PRICE_DRIFT_EXCEEDED', true, -50], [$c['status'], $c['error'], $c['forceable'], $c['drift']]);
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_offline_sale WHERE provisional_number = ? AND status = 'rejected'", [$sale3['provisionalNumber']]));
        $granted = $this->json($this->request('POST', '/api/pos/approvals', ['action' => 'offline_force', 'managerId' => $manager->getId(), 'pin' => '4321', 'context' => ['terminalId' => $this->terminalId, 'counter' => 3, 'amount' => $sale3['totals']['cashTotal']]]), 201);
        $r = $this->json($this->request('POST', '/api/pos/offline-sales', $batch + ['sales' => [$sale3], 'approvals' => [$sale3['provisionalNumber'] => $granted['approvalToken']]]));
        $f = $r['results'][0];
        $this->assertSame(['accepted', true, -50, false], [$f['status'], $f['forced'], $f['priceDrift']['amount'], $f['priceDrift']['within']]);
        $this->assertSame($sale3['totals']['total'] + 0.0, (float) $this->db->fetchOne('SELECT total_amount FROM "order" WHERE id = ?', [$f['orderId']]), 'enregistrée au montant perçu');
        $journal = json_decode($this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'SALE' AND payload LIKE ? ORDER BY id DESC LIMIT 1", ['%"provisionalNumber":"' . $f['provisionalNumber'] . '"%']), true);
        $this->assertSame($manager->getId(), (($journal['data'] ?? $journal)['offline']['forced']['approvedBy'] ?? [])['id'] ?? null);

        // Stock insuffisant accepté (stockShortfall), chaînage rompu signalé, saisie tardive, rapport X
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 1 WHERE id = ?', [$this->tshirt]);
        $sale4 = $this->offlineSale($seller, 'P-' . $this->terminalId . '-4', [['productVariantId' => $this->tshirt, 'quantity' => 3]], $pack, 'mauvais-prev-hash');
        $sale4['soldAt'] = (new \DateTimeImmutable('-3 hours'))->format(\DateTimeInterface::ATOM); // avant l'ouverture de la session
        $sale4['chain']['hash'] = PosOfflineSyncService::hashOf($sale4, 'mauvais-prev-hash');
        $r = $this->json($this->request('POST', '/api/pos/offline-sales', $batch + ['sales' => [$sale4]]));
        $d = $r['results'][0];
        $this->assertSame(['accepted', [['productVariantId' => $this->tshirt, 'shortBy' => 2, 'stockBefore' => 1]], true, true], [$d['status'], $d['stockShortfall'], $d['lateEntry'], $d['chainBroken']], json_encode($d));
        $this->assertSame(-2, (int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$this->tshirt]));
        $this->assertTrue($r['chainBroken']);
        $x = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $this->assertSame(['count' => 4, 'priceDrift' => 50, 'stockShortfalls' => 1, 'lateEntries' => 1], array_diff_key($x['offline'], ['total' => 1]));
        $this->assertSame(4, $x['sales']['count']);

        // Rejets explicites : vendeur sans droit, pack inconnu, carte interdite, remise au-delà du plafond du vendeur, structure
        $noRights = $this->user(['ROLE_USER_INTERNET']);
        $bad = $this->offlineSale($noRights, 'P-' . $this->terminalId . '-5', [['productVariantId' => $this->cap, 'quantity' => 1]], $pack);
        $r = $this->json($this->request('POST', '/api/pos/offline-sales', $batch + ['sales' => [$bad]]));
        $this->assertSame('SELLER_INVALID', $r['results'][0]['error']);
        $unknown = $this->offlineSale($seller, 'P-' . $this->terminalId . '-6', [['productVariantId' => $this->cap, 'quantity' => 1]], $pack);
        $r = $this->json($this->request('POST', '/api/pos/offline-sales', array_replace($batch, ['packVersion' => 'inconnue', 'sales' => [$unknown]])));
        $this->assertSame('PACK_UNKNOWN', $r['results'][0]['error']);
        $card = $this->offlineSale($seller, 'P-' . $this->terminalId . '-7', [['productVariantId' => $this->cap, 'quantity' => 1]], $pack);
        $card['payments'] = [['type' => 1, 'amount' => $card['totals']['cashTotal']]];
        $r = $this->json($this->request('POST', '/api/pos/offline-sales', $batch + ['sales' => [$card]]));
        $this->assertSame('OFFLINE_SALE_INVALID', $r['results'][0]['error']);
        $big = $this->offlineSale($seller, 'P-' . $this->terminalId . '-8', [['productVariantId' => $this->cap, 'quantity' => 1, 'discounts' => [['type' => 'percent', 'value' => 30, 'reasonCode' => 'autre']]]], $pack);
        $r = $this->json($this->request('POST', '/api/pos/offline-sales', $batch + ['sales' => [$big]]));
        $this->assertSame(['rejected', 'OFFLINE_SALE_INVALID', false], [$r['results'][0]['status'], $r['results'][0]['error'] ?? null, $r['results'][0]['forceable'] ?? null], 'remise au-delà du plafond caissier : pas de validation hors ligne ; vu : ' . json_encode($r['results'][0]));
        $r = $this->json($this->request('POST', '/api/pos/offline-sales', $batch + ['sales' => [['provisionalNumber' => 'x']]]));
        $this->assertSame('OFFLINE_SALE_INVALID', $r['results'][0]['error']);
        $this->assertSame(422, $this->request('POST', '/api/pos/offline-sales', $batch + ['sales' => []])->getStatusCode());
        // Session fermée : rejet CASH_REGISTER_CLOSED, la vente reste à synchroniser
        $this->request('POST', '/api/caisse/close', ['cashRegisterId' => $this->register, 'countedCash' => 0, 'discrepancyReason' => 'test']);
        $late = $this->offlineSale($seller, 'P-' . $this->terminalId . '-9', [['productVariantId' => $this->cap, 'quantity' => 1]], $pack);
        $r = $this->json($this->request('POST', '/api/pos/offline-sales', $batch + ['sales' => [$late]]));
        $this->assertSame(['rejected', 'CASH_REGISTER_CLOSED'], [$r['results'][0]['status'], $r['results'][0]['error']]);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register]);
        $this->assertSame('accepted', $this->json($this->request('POST', '/api/pos/offline-sales', $batch + ['sales' => [$late]]))['results'][0]['status'], 'resynchronisée une fois la session ouverte');
        // Historique des lots (responsable)
        $this->assertSame(403, $this->request('GET', '/api/pos/offline-sales')->getStatusCode());
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->assertGreaterThanOrEqual(5, count($this->json($this->request('GET', '/api/pos/offline-sales?terminalId=' . $this->terminalId))['syncs']));
        // Clé d'idempotence déjà utilisée par une vente EN LIGNE (réponse perdue par la caisse, vente mise en file avec la même clé) :
        // même montant → duplicate avec la commande existante, aucune seconde commande ; autre montant → rejected IDEMPOTENCY_KEY_REUSED
        $onlineKey = self::uuid();
        $qo = $this->json($this->request('POST', '/api/pos/quote', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'cashRegisterId' => $this->register]));
        $online = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => $qo['cashTotal'] / 100]], 'cashRegisterId' => $this->register], ['HTTP_IDEMPOTENCY_KEY' => $onlineKey]), 201);
        $queued = $this->offlineSale($seller, "P-$this->terminalId-9", [['productVariantId' => $this->cap, 'quantity' => 1]], $pack, $sale3['chain']['hash']);
        $queued['idempotencyKey'] = $onlineKey;
        $queued['totals']['total'] = (int) round($online['totalAmount'] * 100); // même montant que la vente en ligne (prix courant, pas celui du pack)
        $queued['chain']['hash'] = PosOfflineSyncService::hashOf($queued, $sale3['chain']['hash']);
        $ordersBefore = (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"');
        $dupOnline = $this->json($this->request('POST', '/api/pos/offline-sales', $batch + ['sales' => [$queued]]))['results'][0];
        $this->assertArrayHasKey('onlineSale', $dupOnline, json_encode($dupOnline));
        $this->assertSame(['duplicate', true, $online['orderId'], $online['receiptNumber'], $online['barcode']], [$dupOnline['status'], $dupOnline['onlineSale'], $dupOnline['orderId'], $dupOnline['receiptNumber'], $dupOnline['barcode']], 'clé de la vente en ligne reconnue');
        $this->assertSame($ordersBefore, (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"'), 'aucune seconde commande');
        $this->assertSame('duplicate', $this->json($this->request('POST', '/api/pos/offline-sales', $batch + ['sales' => [$queued]]))['results'][0]['status'], 'rejeu : même réponse');
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'OFFLINE_SYNC' AND payload LIKE ?", ['%"kind":"online_duplicate"%"idempotencyKey":"' . $onlineKey . '"%']), 'journalisé');
        $other = $this->offlineSale($seller, "P-$this->terminalId-10", [['productVariantId' => $this->cap, 'quantity' => 2]], $pack, $queued['chain']['hash']);
        $other['idempotencyKey'] = $onlineKey;
        $other['provisionalNumber'] = "P-$this->terminalId-10";
        $other['chain']['hash'] = PosOfflineSyncService::hashOf($other, $queued['chain']['hash']);
        $reused = $this->json($this->request('POST', '/api/pos/offline-sales', $batch + ['sales' => [$other]]))['results'][0];
        $this->assertSame(['rejected', 'IDEMPOTENCY_KEY_REUSED', false, $online['orderId']], [$reused['status'], $reused['error'], $reused['forceable'], $reused['orderId']], 'autre montant : refus');
        $this->assertSame($ordersBefore, (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"'));
        $this->db->executeStatement('UPDATE entreprise SET pos_offline_enabled = false');
    }

    public function testExchangeQuoteMatchesTheExchange(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->tshirt, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 21.85]], 'cashRegisterId' => $this->register]), 201);
        $line = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale['orderId']))['lines'][0]['orderItemId'];
        $body = ['return' => ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $line, 'quantity' => 1]], 'reasonCode' => 'autre'], 'sale' => ['items' => [['productVariantId' => $this->cap, 'quantity' => 1, 'discounts' => [['type' => 'percent', 'value' => 10, 'reasonCode' => 'fidelite']]]], 'cashRegisterId' => $this->register]];
        $returnsBefore = (int) $this->db->fetchOne('SELECT COUNT(*) FROM pos_return');
        $q = $this->json($this->request('POST', '/api/pos/exchanges/quote', $body));
        $capNet = (int) round(2610 * 1.14975); // 3001
        $this->assertSame([2185, $capNet, 2185, $capNet - 2185, 0], [$q['returnAmount'], $q['sale']['total'], $q['creditApplied'], $q['amountDue'], $q['refundDue']]);
        $this->assertSame((int) (round($q['amountDue'] / 5) * 5), $q['cashAmountDue']);
        $this->assertSame($returnsBefore, (int) $this->db->fetchOne('SELECT COUNT(*) FROM pos_return'), 'simulation : aucune écriture');
        // L'échange réel encaisse exactement cashAmountDue
        $ex = $this->json($this->request('POST', '/api/pos/exchanges', ['return' => $body['return'], 'sale' => $body['sale'] + ['paymentMethods' => [['type' => 2, 'amount' => $q['cashAmountDue'] / 100]]]]), 201);
        $this->assertSame([$q['creditApplied'], round($q['amountDue'] / 100, 2)], [$ex['return']['creditApplied'], $ex['sale']['amountDue'] - $ex['sale']['cashRounding']]);
        // Retour supérieur à la vente : refundDue
        $line2 = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $ex['sale']['orderId']))['lines'][0]['orderItemId'];
        $q2 = $this->json($this->request('POST', '/api/pos/exchanges/quote', ['return' => ['orderId' => $ex['sale']['orderId'], 'lines' => [['orderItemId' => $line2, 'quantity' => 1]], 'reasonCode' => 'autre'], 'sale' => ['items' => [['productVariantId' => $this->tshirt, 'quantity' => 1]], 'cashRegisterId' => $this->register]]));
        $this->assertSame([$capNet, 2185, 2185, 0, $capNet - 2185], [$q2['returnAmount'], $q2['sale']['total'], $q2['creditApplied'], $q2['amountDue'], $q2['refundDue']]);
        $this->assertSame(422, $this->request('POST', '/api/pos/exchanges/quote', ['return' => ['orderId' => $sale['orderId']], 'sale' => []])->getStatusCode());
    }

    // ---------------------------------------------------------------- aides

    /** Vente hors ligne calculée avec la règle partagée à partir du pack (prix, taxes, pas), chaînée */
    private function offlineSale(User $seller, string $provisional, array $items, array $pack, string $prevHash = '', string $soldAt = '-30 seconds'): array
    {
        $lines = [];
        foreach ($items as $item) {
            $variant = null;
            foreach ($pack['products'] as $p) {
                foreach ($p['variants'] as $v) {
                    if ($v['id'] === $item['productVariantId']) {
                        $variant = ['unitPrice' => $v['price'] ?? $p['price'], 'quantity' => $item['quantity'], 'discounts' => $item['discounts'] ?? []];
                    }
                }
            }
            $lines[] = $variant;
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
        $key = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette hors ligne', 'sendEmail' => false]), 201)['key'];
        $this->token = $saved;
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/app/activate', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['key' => $key, 'app' => 'pos']));
        $terminal = json_decode($this->client->getResponse()->getContent(), true)['terminal'];
        $this->terminalId = (int) $terminal['id'];

        return $terminal['token'];
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

    private function requestNoTerminal(string $method, string $path, ?array $body = null): Response
    {
        $saved = $this->terminal;
        $this->terminal = null;
        $r = $this->request($method, $path, $body);
        $this->terminal = $saved;

        return $r;
    }

    private function request(string $method, string $path, ?array $body = null, array $extraHeaders = []): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null, 'HTTP_X_POS_TERMINAL' => $this->terminal,
        ]) + $extraHeaders, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function user(array $roles): User
    {
        $email = 'horsligne-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Hors')->setLastname('Ligne')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }

    private function loginAs(array $roles): User
    {
        $user = $this->user($roles);
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $user->getEmail(), 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];

        return $user;
    }
}
