<?php

namespace App\Tests\Functional\Boutique;

use App\Controller\Admin\EntrepriseCrudController;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\BoutiqueSettingsService\BoutiqueConfigurationValidator;
use App\Services\BoutiqueSettingsService\SiteCurrencyService;
use App\Services\OrderService\TaxInclusive;
use App\Services\OrderService\TaxInclusiveGuard;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * F18 partie 2b (02/11/2026) : prix taxes comprises (France). Extraction de la TVA par groupe de taux en entiers, livraison TTC au prorata,
 * devis caisse et web, vente (total = sous-total TTC), ticket, rapport X, commande lue, retour partiel et devis d'échange, coupons refusés,
 * paquet hors ligne v3, réglage EasyAdmin (refus Stripe Tax, taux non multiple de 0,01 %, verrou), langue des identifiants du magasin.
 */
class TaxInclusiveApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private int $register;
    private int $a;
    private int $b;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-ttc@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'FR', state = '', city = 'Lyon', zip = '69001'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'EUR', prices_include_tax = true, siret = '732 829 320 00074', tva_intracommunautaire = 'FR40303265045', ein = NULL, pos_receipt_show_tax_numbers = true,
            pos_custom_item_enabled = true, pos_custom_item_max_amount = NULL, pos_coupon_enabled = true,
            pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_return_max_days = NULL");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = NULL');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->db->executeStatement("DELETE FROM order_tax WHERE tax_id IN (SELECT id FROM tax WHERE country IN ('US', 'FR'))");
        $this->db->executeStatement("DELETE FROM tax WHERE country IN ('US', 'FR')");
        foreach ([['TVA 20 %', 0.2, 'standard'], ['TVA 10 %', 0.1, 'intermediate'], ['TVA 5,5 %', 0.055, 'reduced'], ['TVA 2,1 %', 0.021, 'super_reduced']] as [$name, $rate, $cat]) {
            $this->db->executeStatement('INSERT INTO tax (id, name, rate, type, province, country, category) VALUES ((SELECT COALESCE(MAX(id), 0) + 1 FROM tax), ?, ?, ?, NULL, ?, ?)', [$name, $rate, 'vat', 'FR', $cat]);
        }
        $this->db->executeStatement("UPDATE product SET tax_category = NULL, is_pos = true WHERE code IN ('DEMO-CASQUETTE', 'DEMO-TSHIRT')");
        $this->a = $this->variant('DEMO-CASQUETTE', 1200);
        $this->b = $this->variant('DEMO-TSHIRT', 550);
        $this->db->executeStatement("UPDATE product SET tax_category = 'reduced' WHERE code = 'DEMO-TSHIRT'");
        $this->db->executeStatement('UPDATE carrier SET price = 500 WHERE id = 1');
        $this->loginAs(['ROLE_USER_POS']);
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('UPDATE entreprise SET prices_include_tax = false');
        parent::tearDown();
    }

    public function testIntegerExtractionMatchesTheWrittenFormula(): void
    {
        // PAYS-1c (12/11/2026) : taux en millionièmes ; mêmes résultats qu'en dix-millièmes pour les multiples de 0,01 %
        $this->assertSame([200000, 55000, 21000, 85000, 17500, 10500, 130000, 9000], array_map(fn ($r) => TaxInclusive::ratePpm($r), [0.2, 0.055, 0.021, 0.085, 0.0175, 0.0105, 0.13, 0.009]), 'France et DOM');
        $this->assertSame([99750, false], [TaxInclusive::ratePpm(0.09975), TaxInclusive::isLegacyExactRate(0.09975)], '9,975 % : exact au millionième, pas au dix-millième');
        $this->assertNull(TaxInclusive::ratePpm(0.0997512), 'taux non exact au millionième : refusé');
        $this->assertSame([3, 8, 1000, 521, 469, 900], [TaxInclusive::base(3, 200000), TaxInclusive::base(9, 200000), TaxInclusive::base(1200, 200000), TaxInclusive::base(550, 55000), TaxInclusive::base(495, 55000), TaxInclusive::base(1080, 200000)], '0,03 → 0,03 ; 0,09 → 0,08 ; 12,00 → 10,00 ; 5,50 → 5,21 ; 4,95 → 4,69 ; 10,80 → 9,00');
        $this->assertSame(['a' => 343, 'b' => 157], TaxInclusive::prorate(500, ['a' => 1200, 'b' => 550]), 'livraison 5,00 € répartie 3,43 / 1,57');
    }

    public function testQuotesExtractTheVatByRateGroupAndShippingIsProrated(): void
    {
        $s = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertSame([true, [['category' => 'standard', 'rate' => 0.2], ['category' => 'intermediate', 'rate' => 0.1], ['category' => 'reduced', 'rate' => 0.055], ['category' => 'super_reduced', 'rate' => 0.021]], 1], [$s['pricesIncludeTax'], $s['taxCategories'], $s['cashRoundingStep']], 'réglages : TTC, catégories des taxes du magasin avec leur taux');
        $items = [['productVariantId' => $this->a, 'quantity' => 1], ['productVariantId' => $this->b, 'quantity' => 1]];
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register]));
        $this->assertSame([1750, 1750, 1750, true], [$q['subtotal'], $q['total'], $q['cashTotal'], $q['pricesIncludeTax']], '12,00 € (20 %) + 5,50 € (5,5 %) = 17,50 € TTC');
        $this->assertSame([['TVA 20 %', 1000, 200, 1200], ['TVA 5,5 %', 521, 29, 550]], array_map(fn ($t) => [$t['label'], $t['base'], $t['amount'], $t['total']], $q['taxes']), 'seules les taxes qui frappent une ligne');
        $this->assertSame($q['subtotal'], array_sum(array_column($q['taxes'], 'base')) + array_sum(array_column($q['taxes'], 'amount')), 'Σ bases + Σ TVA = TTC exactement');
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register, 'ticketDiscount' => ['type' => 'percent', 'value' => 10, 'reasonCode' => 'fidelite']]));
        $this->assertSame([1575, 1575, [900, 469], [180, 26]], [$q['subtotal'], $q['total'], array_column($q['taxes'], 'base'), array_column($q['taxes'], 'amount')], 'remise de ticket 10 % au prorata des TTC, puis extraction par groupe');
        // Égalités 0,03 € et 0,09 € à 20 %
        $this->db->executeStatement("UPDATE product SET price = 3 WHERE code = 'DEMO-CASQUETTE'");
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => [['productVariantId' => $this->a, 'quantity' => 1]], 'cashRegisterId' => $this->register]));
        $this->assertSame([3, 3, 0], [$q['total'], $q['taxes'][0]['base'], $q['taxes'][0]['amount']], '0,03 € : base 0,03, TVA 0,00');
        $this->db->executeStatement("UPDATE product SET price = 9 WHERE code = 'DEMO-CASQUETTE'");
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => [['productVariantId' => $this->a, 'quantity' => 1]], 'cashRegisterId' => $this->register]));
        $this->assertSame([9, 8, 1], [$q['total'], $q['taxes'][0]['base'], $q['taxes'][0]['amount']], '0,09 € : base 0,08, TVA 0,01');
        $this->db->executeStatement("UPDATE product SET price = 1200 WHERE code = 'DEMO-CASQUETTE'");
        // Article libre au taux choisi + exonéré
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => [['quantity' => 2, 'custom' => ['label' => 'Plat du jour', 'unitPrice' => 1250, 'taxCategory' => 'intermediate']], ['quantity' => 1, 'custom' => ['label' => 'Pourboire', 'unitPrice' => 300, 'taxable' => false]]], 'cashRegisterId' => $this->register]));
        $this->assertSame([2800, 2800, [2273], [227]], [$q['subtotal'], $q['total'], array_column($q['taxes'], 'base'), array_column($q['taxes'], 'amount')]);
        // Boutique web : livraison 5,00 € TTC répartie 3,43 (20 %) / 1,57 (5,5 %) ; total = subtotal + shipping
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/cart/quote', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['items' => $items, 'carrierId' => 1, 'shippingAddress' => ['country' => 'FR', 'city' => 'Lyon', 'postalCode' => '69001']]));
        $web = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertSame([1750, 500, 2250, true], [$web['subtotal'], $web['shipping'], $web['total'], $web['pricesIncludeTax']], 'devis web TTC : total = subtotal + shipping');
        $this->assertSame([[1286, 257, 1543], [670, 37, 707]], array_map(fn ($t) => [$t['base'], $t['amount'], $t['total']], $web['taxes']), '20 % : (1200 + 343) → 1286 + 257 ; 5,5 % : (550 + 157) → 670 + 37');
        // Commande web avec livraison : mêmes montants que le devis (sous-total lignes, livraison à part, total = sous-total + livraison)
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET', 'ROLE_USER_POS']); // membre du personnel : commande sans paiement Stripe (jeton de la connexion caisse)
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $address = (new \App\Entity\Adress())->setFirstname('Test')->setLastname('TTC')->setFullname('Test TTC')->setAddress('1 rue du Test')->setCity('Lyon')->setCodepostal('69001')->setCountry('FR')->setPhone('+33 4 00 00 00 00')->setProvince('')->setUserAdress($em->getRepository(User::class)->findOneBy(['email' => $this->adminEmail]));
        $em->persist($address);
        $em->flush();
        $created = $this->request('POST', '/api/order/create', ['addressId' => $address->getId(), 'carrierId' => 1, 'paymentMethod' => 1, 'items' => $items]);
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $orderId = (int) json_decode($created->getContent(), true)['orderId'];
        $this->assertSame([1750.0, 500.0, 2250.0, 294.0, true], array_map(fn ($v) => is_bool($v) ? $v : (float) $v, $this->db->fetchNumeric('SELECT sub_total, shipping_cost, total_amount, total_tax, prices_include_tax FROM "order" WHERE id = ?', [$orderId])), 'commande = devis : sous-total 17,50 (lignes), livraison 5,00, total 22,50, TVA incluse 2,94');
        $this->assertSame([[1286, 257.0], [670, 37.0]], array_map(fn ($r) => [(int) $r['base'], (float) $r['amount']], $this->db->fetchAllAssociative('SELECT base, amount FROM order_tax WHERE order_tax_id = ? ORDER BY id', [$orderId])));
        $read = $this->json($this->request('GET', "/api/orders/$orderId"));
        $this->assertSame([1750, 500, 2250, 294, true], [$read['subTotal'], $read['priceShipping'], $read['totalAmount'], $read['priceTax'], $read['pricesIncludeTax']], 'commande lue : subTotal lignes, priceShipping, totalAmount = subTotal + priceShipping');
        $this->assertSame([[1286, 257, 1543], [670, 37, 707]], array_map(fn ($t) => [$t['base'], $t['amount'], $t['total']], $read['taxes']));
        $this->assertSame(2250, array_sum(array_column($web['taxes'], 'total')), 'Σ TTC des groupes = total');
        // Panier entièrement exonéré : livraison exonérée
        $this->db->executeStatement("UPDATE product SET tax_category = 'exempt' WHERE code IN ('DEMO-CASQUETTE', 'DEMO-TSHIRT')");
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/cart/quote', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['items' => $items, 'carrierId' => 1, 'shippingAddress' => ['country' => 'FR', 'city' => 'Lyon', 'postalCode' => '69001']]));
        $web = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame([2250, []], [$web['total'], $web['taxes']], 'panier exonéré : aucune taxe servie');
        $this->db->executeStatement("UPDATE product SET tax_category = NULL WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement("UPDATE product SET tax_category = 'reduced' WHERE code = 'DEMO-TSHIRT'");
    }

    public function testSaleReceiptReportOrderReadReturnExchangeCouponsAndPack(): void
    {
        $items = [['productVariantId' => $this->a, 'quantity' => 1], ['productVariantId' => $this->b, 'quantity' => 1]];
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 17.5]], 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([1750.0, 229.0, 1750.0, true], array_map(fn ($v) => is_bool($v) ? $v : (float) $v, $this->db->fetchNumeric('SELECT total_amount, total_tax, sub_total, prices_include_tax FROM "order" WHERE id = ?', [$sale['orderId']])), 'commande TTC : total = sous-total, TVA incluse');
        $this->assertSame([[1000, 200.0], [521, 29.0]], array_map(fn ($r) => [(int) $r['base'], (float) $r['amount']], $this->db->fetchAllAssociative('SELECT base, amount FROM order_tax WHERE order_tax_id = ? ORDER BY id', [$sale['orderId']])), 'order_tax : seulement les taxes qui frappent une ligne, aucune ligne à 0');
        // Ticket : TTC, taxes avec base et total, identifiants français en français sans ?locale= (langue du site)
        $receipt = $this->json($this->request('GET', '/api/pos/receipts?orderId=' . $sale['orderId']));
        $this->assertSame([true, 17.5, 2.29, 'TVA 20 %', 10.0, 2.0, 12.0], [$receipt['pricesIncludeTax'], (float) $receipt['totalAmount'], (float) $receipt['totalTax'], $receipt['taxes'][0]['label'], (float) $receipt['taxes'][0]['base'], (float) $receipt['taxes'][0]['amount'], (float) $receipt['taxes'][0]['total']]);
        $this->assertSame(['SIRET', 'TVA intracom.'], array_column($receipt['store']['taxIds'], 'label'));
        // Rapport X : TTC, taxes par taux
        $x = $this->json($this->request('GET', '/api/caisse/report/x?cashRegisterId=' . $this->register));
        $this->assertSame([true, 1750, 1000, 200, 1200], [$x['pricesIncludeTax'], $x['sales']['total'], $x['taxes'][0]['base'], $x['taxes'][0]['amount'], $x['taxes'][0]['total']]);
        // Commande lue : pricesIncludeTax et taxes[] par taux avec la base
        $orders = $this->json($this->request('GET', '/api/orders?orderSource=2'));
        $read = array_values(array_filter($orders, fn ($o) => $o['id'] === $sale['orderId']))[0];
        $this->assertSame([1750, 1750, 229, true], [$read['totalAmount'], $read['subTotal'], $read['priceTax'], $read['pricesIncludeTax']], 'commande lue : subTotal TTC, priceTax = taxe incluse');
        $this->assertSame([['TVA 20 %', 0.2, 1000, 200, 1200], ['TVA 5,5 %', 0.055, 521, 29, 550]], array_map(fn ($t) => [$t['label'], $t['rate'], $t['base'], $t['amount'], $t['total']], array_values(array_filter($read['taxes'], fn ($t) => $t['amount'] > 0))));
        // Retour partiel : le t-shirt à 5,50 € TTC → remboursement 5,50, TVA 5,5 % 0,29 extraite, base 5,21
        $found = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale['orderId']));
        $line = array_column($found['lines'], null, 'productVariantId')[$this->b];
        $this->assertSame([550, 550, 29], [$line['unitPrice'], $line['unitRefundable'], $line['unitTaxes']], 'TTC : le remboursable est le net payé, la taxe est dedans');
        $this->assertSame([['TVA 5,5 %', 521, 29, 550]], array_map(fn ($t) => [$t['label'], $t['base'], $t['amount'], $t['total']], $line['taxes']), 'seule la taxe qui frappe la ligne');
        $xq = $this->json($this->request('POST', '/api/pos/exchanges/quote', ['return' => ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $line['orderItemId'], 'quantity' => 1]]], 'sale' => ['items' => [['productVariantId' => $this->a, 'quantity' => 1]]]]));
        $this->assertSame([550, 550, 29, true, [29], [521]], [$xq['returnAmount'], $xq['return']['subtotal'], $xq['return']['totalTax'], $xq['return']['pricesIncludeTax'], array_column($xq['return']['taxes'], 'amount'), array_column($xq['return']['taxes'], 'base')], 'devis d\'échange : même détail par taux pour le retour');
        $this->assertSame([1200, 650, 200], [$xq['sale']['total'], $xq['amountDue'], $xq['sale']['taxes'][0]['amount']], 'vente d\'échange TTC 12,00 € − avoir 5,50 € = 6,50 € dû');
        $ret = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $line['orderItemId'], 'quantity' => 1]], 'reasonCode' => 'defectueux', 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([550, 550, 29, true], [$ret['amount'], $ret['subTotal'], $ret['totalTax'], $ret['pricesIncludeTax']]);
        $this->assertSame([['TVA 5,5 %', 29, 521, 550]], array_map(fn ($t) => [$t['label'], $t['amount'], $t['base'], $t['total']], $ret['taxes']));
        $this->assertSame([-550.0, -29.0, -550.0], array_map('floatval', $this->db->fetchNumeric('SELECT total_amount, total_tax, sub_total FROM "order" WHERE id = ?', [$ret['returnOrderId']])), 'commande de retour TTC');
        $this->assertSame(-521, (int) $this->db->fetchOne("SELECT SUM(base) FROM order_tax WHERE order_tax_id = ? AND amount <> 0", [$ret['returnOrderId']]));
        // Coupons de fabricant refusés en TTC (devis et vente)
        $bad = $this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register, 'coupons' => [['amount' => 100, 'manufacturer' => 'Acme', 'barcode' => '512345123257']]]);
        $this->assertSame([409, 'COUPONS_NOT_AVAILABLE_TTC'], [$bad->getStatusCode(), json_decode($bad->getContent(), true)['error']]);
        $bad = $this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 6, 'amount' => 1, 'coupon' => ['manufacturer' => 'Acme', 'barcode' => '512345123257', 'offerCode' => 'A1', 'serial' => '1']], ['type' => 2, 'amount' => 16.5]], 'cashRegisterId' => $this->register]);
        $this->assertSame([409, 'COUPONS_NOT_AVAILABLE_TTC'], [$bad->getStatusCode(), json_decode($bad->getContent(), true)['error']], $bad->getContent());
        // Export comptable d'un site TTC : HT extrait (poste dédié pour isoler les ventes de ce test) ; vente avec remise 10 % : net HT 1369, brut HT 1521, remises 152
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $second = (int) $this->json($this->request('POST', '/api/cash-registers', ['name' => 'Poste TTC ' . bin2hex(random_bytes(2))]), 201)['id'];
        $this->assertNotSame($this->register, $second, 'poste dédié à ce test');
        $this->loginAs(['ROLE_USER_POS']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $second, 'cashFund' => 10000]);
        $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 15.75]], 'cashRegisterId' => $second, 'ticketDiscount' => ['type' => 'percent', 'value' => 10, 'reasonCode' => 'fidelite']]), 201);
        // Retour TTC dans la session exportée (12/11/2026) : « Retours HT » = HT (521), TVA sur retours 29, remboursement 550, sans écart d'arrondi
        $sale2 = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->b, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 5.5]], 'cashRegisterId' => $second]), 201);
        $line2 = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale2['orderId']))['lines'][0];
        $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale2['orderId'], 'lines' => [['orderItemId' => $line2['orderItemId'], 'quantity' => 1]], 'reasonCode' => 'defectueux', 'cashRegisterId' => $second]), 201);
        $x2 = $this->json($this->request('GET', '/api/caisse/report/x?cashRegisterId=' . $second));
        $this->json($this->request('POST', '/api/caisse/close', ['cashRegisterId' => $second, 'countedCash' => $x2['expectedCash'], 'keptFund' => 0]));
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $from = (new \DateTimeImmutable('-1 day'))->format('Y-m-d'); // le rapport Z est daté dans le fuseau du magasin (France ici) : fenêtre large
        $to = (new \DateTimeImmutable('+1 day'))->format('Y-m-d');
        $e = $this->json($this->request('GET', "/api/pos/exports/accounting?from=$from&to=$to&cashRegisterId=$second"));
        $days = array_values(array_filter($e['days'], fn ($day) => $day['salesCount'] > 0));
        $this->assertCount(1, $days, 'un seul jour de ventes sur le poste dédié');
        $d = $days[0];
        $by = [];
        foreach ($e['entries'] as $l) {
            $by[trim(preg_replace('/^Caisse .*?, session \d+ /', '', $l['label']))] = [$l['debit'], $l['credit']];
        }
        $this->assertSame([[521, 0], [29, 0], [0, 550]], [$by['Retours HT'] ?? null, $by['TVA 5,5 % sur retours'] ?? null, $by['Remboursements espèces'] ?? null], json_encode($by, JSON_UNESCAPED_UNICODE));
        $this->assertArrayNotHasKey('Écart d\'arrondi', $by);
        $this->assertSame([1575 + 550, 1369 + 521, 1521 + 521, 152], [$d['salesTtc'], $d['salesNetHt'], $d['salesGrossHt'], $d['discounts']], 'TTC dans salesTtc ; salesNetHt = Σ order_tax.base ; salesGrossHt = HT avant remises par la même extraction');
        $this->assertSame([['TVA 20 %', 900, 180], ['TVA 5,5 %', 469 + 521, 26 + 29]], array_map(fn ($l, $t) => [$l, $t['base'], $t['tax']], array_keys($d['taxes']), array_values($d['taxes'])));
        $this->assertTrue($e['totals']['balanced']);
        $this->loginAs(['ROLE_USER_POS']);
        // Paquet hors ligne v3
        $pack = $this->json($this->request('GET', '/api/pos/offline-pack?cashRegisterId=' . $this->register));
        $this->assertSame(['pos-offline-v3', true], [$pack['calculationRule'], $pack['site']['pricesIncludeTax']]);
        $cases = $this->json($this->request('GET', '/api/pos/offline-cases'));
        $this->assertSame('pos-offline-v3', $cases['rule']);
        $this->assertContains('fr-ttc-egalite-0-09-a-20', array_column($cases['cases'], 'id'));
    }

    public function testEasyAdminSettingIsGuardedAndSiteLanguageDrivesTheLabels(): void
    {
        $guard = static::getContainer()->get(TaxInclusiveGuard::class);
        // Taux non exact au millionième sur le magasin : refus (PAYS-1c : 9,975 % est désormais accepté)
        $this->db->executeStatement("INSERT INTO tax (id, name, rate, type, province, country, category) VALUES ((SELECT COALESCE(MAX(id), 0) + 1 FROM tax), 'Taxe bizarre', 0.0997512, 'vat', NULL, 'FR', 'intermediate')");
        try {
            $guard->assertCanEnable();
            $this->fail('taux inexact');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Taxe bizarre', $e->getMessage());
            $this->assertStringContainsString('9,9751', $e->getMessage());
            $this->assertStringContainsString('millionième', $e->getMessage());
        }
        $this->db->executeStatement("DELETE FROM tax WHERE name = 'Taxe bizarre'");
        // Stripe Tax : refus, dans les deux sens
        $this->db->executeStatement("INSERT INTO boutique_setting (id, configuration) VALUES ((SELECT COALESCE(MAX(id), 0) + 1 FROM boutique_setting), '{\"commerce\": {\"taxProvider\": \"stripe\"}}')");
        try {
            $guard->assertCanEnable();
            $this->fail('Stripe Tax');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Stripe Tax', $e->getMessage());
        }
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $errors = static::getContainer()->get(BoutiqueConfigurationValidator::class)->validateConfiguration(json_decode('{"commerce": {"taxProvider": "stripe"}}'));
        $this->assertContains('commerce.taxProvider', array_column($errors, 'path'), 'éditeur : stripe refusé quand le site est TTC');
        $this->assertSame([], array_filter(static::getContainer()->get(BoutiqueConfigurationValidator::class)->validateConfiguration(json_decode('{"commerce": {"taxProvider": "table"}}')), fn ($e) => $e['path'] === 'commerce.taxProvider'));
        // Fiche Entreprise : champ présent ; verrouillé dès qu'un montant existe (comme la devise), sinon modifiable et le garde-fou décide
        $this->loginAdmin();
        $id = (int) $this->db->fetchOne('SELECT MIN(id) FROM entreprise');
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'edit', 'crudControllerFqcn' => EntrepriseCrudController::class, 'entityId' => $id]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Prix affichés taxes comprises', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('applications (caisse, e-commerce) qui savent l', $this->client->getResponse()->getContent(), 'aide : à activer quand les applications à jour sont publiées');
        $field = $crawler->filter('input[name="Entreprise[pricesIncludeTax]"]');
        $this->assertCount(1, $field);
        if (static::getContainer()->get(SiteCurrencyService::class)->isLocked()) {
            $this->assertSame('disabled', $field->attr('disabled'), 'montants enregistrés : lecture seule');
        }
        // Langue du site sans ?locale= : France → fr ; Canada hors Québec → en (GST / QST) ; Québec → fr
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'ON', city = 'Toronto', zip = 'M5V 1J1'");
        $this->db->executeStatement("UPDATE entreprise SET prices_include_tax = false, gst_number = '123456789 RT0001', qst_number = NULL");
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'ON'");
        $this->assertSame('en', static::getContainer()->get(\App\Services\CaisseService\SiteLocaleResolver::class)->siteLocale());
        $this->db->executeStatement("UPDATE address_entreprise SET state = 'QC'");
        $this->assertSame('fr', static::getContainer()->get(\App\Services\CaisseService\SiteLocaleResolver::class)->siteLocale());
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'US', state = 'NY'");
        $this->assertSame('en', static::getContainer()->get(\App\Services\CaisseService\SiteLocaleResolver::class)->siteLocale());
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'FR', state = ''");
        $this->assertSame('fr', static::getContainer()->get(\App\Services\CaisseService\SiteLocaleResolver::class)->siteLocale());
    }

    private function variant(string $code, int $price): int
    {
        $id = (int) $this->db->fetchOne('SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = ? ORDER BY v.id LIMIT 1', [$code]);
        $this->db->executeStatement('UPDATE product SET price = ? WHERE code = ?', [$price, $code]);
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500, price = NULL WHERE id = ?', [$id]);

        return $id;
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
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function loginAdmin(): void
    {
        $this->client->loginUser($this->user(['ROLE_ADMIN']), 'main');
    }

    private function loginAs(array $roles): void
    {
        $user = $this->user($roles);
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $user->getEmail(), 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
    }

    private string $adminEmail = '';

    private function user(array $roles): User
    {
        $email = 'ttc-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $this->adminEmail = $email;
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('TTC')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }
}
