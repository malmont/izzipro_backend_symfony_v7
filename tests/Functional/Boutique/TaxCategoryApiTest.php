<?php

namespace App\Tests\Functional\Boutique;

use App\Controller\Admin\TaxCrudController;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\OrderService\TaxCategoryAudit;
use App\Services\OrderService\TaxEngine;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * F18 partie 2a (01/11/2026) : catégories fiscales (produit, taxe, ligne figée), assiette et TTC par taux dans le devis, le ticket,
 * les rapports, le paquet hors ligne et l'export comptable ; États-Unis (taux combiné de l'État, produits exonérés) ; France HT à
 * plusieurs taux ; identifiants du magasin (store.taxIds). Sans catégorie (Québec), tout reste identique au centime.
 */
class TaxCategoryApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private int $register;
    private int $cap;
    private int $tshirt;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-taxes@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', prices_include_tax = false, siret = NULL, ein = NULL, tva_intracommunautaire = NULL, gst_number = '123456789 RT0001', qst_number = '1234567890 TQ0001', pos_receipt_show_tax_numbers = true,
            pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_return_max_days = NULL");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = NULL');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->db->executeStatement("DELETE FROM order_tax WHERE tax_id IN (SELECT id FROM tax WHERE country IN ('US', 'FR'))");
        $this->db->executeStatement("DELETE FROM tax WHERE country IN ('US', 'FR')");
        $this->db->executeStatement('UPDATE tax SET category = NULL');
        $this->db->executeStatement('UPDATE product SET tax_category = NULL');
        $this->db->executeStatement("UPDATE product SET is_pos = true WHERE code IN ('DEMO-CASQUETTE', 'DEMO-TSHIRT')"); // présents dans le paquet hors ligne
        $this->cap = $this->variant('DEMO-CASQUETTE', 2900);
        $this->tshirt = $this->variant('DEMO-TSHIRT', 1900);
        $this->loginAs(['ROLE_USER_POS']);
    }

    public function testQuebecWithoutCategoriesIsUnchangedAndTaxesCarryBaseAndTotal(): void
    {
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'cashRegisterId' => $this->register]));
        $this->assertSame([2900, 3334, 3335, false], [$q['subtotal'], $q['total'], $q['cashTotal'], $q['pricesIncludeTax']], 'casquette 29,00 $ : 33,34 $, 33,35 $ en espèces, comme avant');
        $this->assertSame([['TPS', 0.05, 2900, 145, 3045, null], ['TVQ', 0.09975, 2900, 289, 3189, null]], array_map(fn ($t) => [$t['label'], $t['rate'], $t['base'], $t['amount'], $t['total'], $t['category']], $q['taxes']), 'base et total par taux ajoutés, montants inchangés');
        $this->assertSame('standard', $q['lines'][0]['taxCategory']);

        // Point B (correctif du 02/11/2026) : 14,99 $ au Québec, TPS 74,95 → 75 et TVQ 149,525 → 150 ; total des taxes = Σ arrondis = 225 partout
        // (avant : round(224,475) = 224 au devis et à create-intent, 225 à la vente, au ticket et hors ligne : un cent d'écart)
        $this->db->executeStatement("UPDATE product SET price = 1499 WHERE code = 'DEMO-CASQUETTE'");
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'cashRegisterId' => $this->register]));
        $this->assertSame([[75, 150], 1724, 1725], [array_column($q['taxes'], 'amount'), $q['total'], $q['cashTotal']], 'devis : 14,99 + 2,25 = 17,24, espèces 17,25');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/cart/quote', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'shippingAddress' => ['country' => 'CA', 'province' => 'QC', 'city' => 'Montréal', 'postalCode' => 'H1M 0A9']]));
        $web = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame([1724, [75, 150]], [$web['total'], array_column($web['taxes'], 'amount')], 'boutique web : même total que la commande (montant autorisé par create-intent = total du devis)');
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 17.25]], 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([1724.0, 225.0], array_map('floatval', $this->db->fetchNumeric('SELECT total_amount, total_tax FROM "order" WHERE id = ?', [$sale['orderId']])), 'vente : Σ des taxes arrondies = 2,25 → 17,24, comme le devis');
        $receipt = $this->json($this->request('GET', '/api/pos/receipts?orderId=' . $sale['orderId']));
        $this->assertSame([0.75, 1.5, 14.99, 15.74], [$receipt['taxes'][0]['amount'], $receipt['taxes'][1]['amount'], $receipt['taxes'][0]['base'], $receipt['taxes'][0]['total']], 'ticket : taxes avec base et total en dollars');
        $this->assertSame([false, ['label' => 'TPS', 'number' => '123456789 RT0001'], ['label' => 'TVQ', 'number' => '1234567890 TQ0001']], [$receipt['pricesIncludeTax'], $receipt['store']['taxIds'][0], $receipt['store']['taxIds'][1]], 'store.taxIds au Québec : TPS, TVQ');
        $en = $this->json($this->request('GET', '/api/pos/receipts?orderId=' . $sale['orderId'] . '&locale=en'));
        $this->assertSame(['GST', 'QST'], array_column($en['store']['taxIds'], 'label'), 'site canadien en anglais : GST, QST');
        $this->assertSame(['TPS', 'TVQ'], array_column($this->json($this->request('GET', '/api/caisse/report/x?cashRegisterId=' . $this->register))['store']['taxIds'], 'label'), 'rapport X : même règle');
        $this->assertSame([1499, 1499], $this->db->fetchFirstColumn('SELECT base FROM order_tax WHERE order_tax_id = ? ORDER BY id', [$sale['orderId']]), 'assiette HT par taxe enregistrée');
        $this->assertSame('standard', $this->db->fetchOne('SELECT tax_category FROM order_items WHERE order_associated_id = ?', [$sale['orderId']]), 'produit sans catégorie : catégorie effective « standard » figée sur la ligne (null = lignes d\'avant 2a)');
        $x = $this->json($this->request('GET', '/api/caisse/report/x?cashRegisterId=' . $this->register));
        $this->assertSame([1499, 75, 1574], [$x['taxes'][0]['base'], $x['taxes'][0]['amount'], $x['taxes'][0]['total']], 'rapport X : base et total par taux');

        // Une catégorie sur le produit ne change rien tant que les taxes n'en portent pas (TPS et TVQ frappent tout)
        $this->db->executeStatement("UPDATE product SET tax_category = 'reduced', price = 2900 WHERE code = 'DEMO-CASQUETTE'");
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'cashRegisterId' => $this->register]));
        $this->assertSame([[145, 289], 3334, 'reduced'], [array_column($q['taxes'], 'amount'), $q['total'], $q['lines'][0]['taxCategory']]);
    }

    public function testUnitedStatesCombinedRateAndExemptProductThroughSaleReturnAndAccounting(): void
    {
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'US', state = 'New York', city = 'New York', zip = '10001'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'USD'"); // aucun arrondi des espèces hors CAD
        $this->db->executeStatement("INSERT INTO tax (id, name, rate, type, province, country, category) VALUES ((SELECT COALESCE(MAX(id), 0) + 1 FROM tax), 'Sales tax NY', 0.08875, 'sales_tax', 'NY', 'US', NULL)"); // id sans valeur par défaut
        $this->db->executeStatement("UPDATE product SET price = 1000 WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement("UPDATE product SET price = 500, tax_category = 'exempt' WHERE code = 'DEMO-TSHIRT'");
        $this->assertSame(['country' => 'US', 'province' => 'NY', 'city' => 'New York', 'postalCode' => '10001'], TaxEngine::normalizeAddress(['country' => 'US', 'state' => 'New York', 'city' => 'New York', 'zip' => '10001']), 'nom d\'État ramené à son code');

        $items = [['productVariantId' => $this->cap, 'quantity' => 1], ['productVariantId' => $this->tshirt, 'quantity' => 1]];
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register]));
        $this->assertSame([1500, 1589, 1589], [$q['subtotal'], $q['total'], $q['cashTotal']], '10,00 $ taxable + 5,00 $ exonéré à 8,875 % : 0,89 $ (88,75 ¢ arrondi) → 15,89 $');
        $this->assertSame([['Sales tax NY', 0.08875, 1000, 89, 1089]], array_map(fn ($t) => [$t['label'], $t['rate'], $t['base'], $t['amount'], $t['total']], $q['taxes']));
        $this->assertSame(['standard', 'exempt'], array_column($q['lines'], 'taxCategory'));
        $this->assertSame(array_sum(array_column($q['taxes'], 'amount')), $q['total'] - $q['subtotal'], 'Σ taxes[].amount = total des taxes (règle nouvelle)');

        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 15.89]], 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([1589.0, 89.0], array_map('floatval', $this->db->fetchNumeric('SELECT total_amount, total_tax FROM "order" WHERE id = ?', [$sale['orderId']])));
        $this->assertSame([['base' => 1000, 'amount' => 89.0]], array_map(fn ($r) => ['base' => (int) $r['base'], 'amount' => (float) $r['amount']], $this->db->fetchAllAssociative('SELECT base, amount FROM order_tax WHERE order_tax_id = ?', [$sale['orderId']])));
        $this->assertSame([['standard', 1000.0], ['exempt', 500.0]], array_map(fn ($r) => [$r['tax_category'], (float) $r['total_price']], $this->db->fetchAllAssociative('SELECT tax_category, total_price FROM order_items WHERE order_associated_id = ? ORDER BY id', [$sale['orderId']])), 'catégorie effective figée sur chaque ligne');

        // Retour : le t-shirt exonéré ne rend aucune taxe, la casquette rend les 0,89 $ (taux de la vente d'origine)
        $found = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale['orderId']));
        $byVariant = array_column($found['lines'], null, 'productVariantId');
        $this->assertSame([0, 89], [$byVariant[$this->tshirt]['unitTaxes'], $byVariant[$this->cap]['unitTaxes']], 'part de taxe par ligne selon sa catégorie');
        $ret = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $byVariant[$this->tshirt]['orderItemId'], 'quantity' => 1]], 'reasonCode' => 'defectueux', 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([500, 0], [$ret['amount'], array_sum(array_column($ret['taxes'], 'amount'))], 'retour de l\'article exonéré : 5,00 $ sans taxe');
        $this->assertSame([0.0, 0], [(float) $this->db->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM order_tax WHERE order_tax_id = ?', [$ret['returnOrderId']]), (int) $this->db->fetchOne('SELECT COALESCE(SUM(base), 0) FROM order_tax WHERE order_tax_id = ?', [$ret['returnOrderId']])], 'commande de retour : ni taxe ni assiette');
        $ret2 = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $byVariant[$this->cap]['orderItemId'], 'quantity' => 1]], 'reasonCode' => 'defectueux', 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([1089, 89, -1000], [$ret2['amount'], array_sum(array_column($ret2['taxes'], 'amount')), (int) $this->db->fetchOne('SELECT SUM(base) FROM order_tax WHERE order_tax_id = ?', [$ret2['returnOrderId']])], 'retour taxable : taxe et assiette négatives');

        // Rapport X : base = assiette de la seule ligne taxable (pas 15,00 $), net des retours ; export comptable : même assiette par taxe
        $x = $this->json($this->request('GET', '/api/caisse/report/x?cashRegisterId=' . $this->register));
        $this->assertSame([], $x['taxes'], 'après retour complet : tout est rendu, aucune taxe servie (base et montant nets à 0)');
        $this->json($this->request('POST', '/api/caisse/close', ['cashRegisterId' => $this->register, 'countedCash' => $x['expectedCash'], 'keptFund' => 0]));
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('America/Toronto')))->format('Y-m-d');
        $e = $this->json($this->request('GET', "/api/pos/exports/accounting?from=$today&to=$today&cashRegisterId=$this->register"));
        $d = $e['days'][0];
        $this->assertArrayHasKey('Sales tax NY', $d['taxes']); // d'autres ventes du jour (autres tests, TPS/TVQ) peuvent figurer : seule la taxe NY vient de cette vente
        $this->assertSame([1000, 89], [$d['taxes']['Sales tax NY']['base'], $d['taxes']['Sales tax NY']['tax']], 'export : assiette de la taxe = 10,00 $ (la ligne exonérée n\'y entre pas), avant retours');
        $this->assertTrue($e['totals']['balanced']);
    }

    public function testFranceSeveralRatesByCategoryCustomItemAndOrphanCategoryAlert(): void
    {
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'FR', state = '', city = 'Lyon', zip = '69001'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'EUR', siret = '732 829 320 00074', tva_intracommunautaire = 'FR40303265045', ein = 'EIN-NE-DOIT-PAS-SORTIR', pos_custom_item_enabled = true, pos_custom_item_max_amount = NULL"); // article libre autorisé (F7)
        foreach ([['TVA 20 %', 0.2, 'standard'], ['TVA 10 %', 0.1, 'intermediate'], ['TVA 5,5 %', 0.055, 'reduced'], ['TVA 2,1 %', 0.021, 'super_reduced']] as [$name, $rate, $cat]) {
            $this->db->executeStatement('INSERT INTO tax (id, name, rate, type, province, country, category) VALUES ((SELECT COALESCE(MAX(id), 0) + 1 FROM tax), ?, ?, ?, NULL, ?, ?)', [$name, $rate, 'vat', 'FR', $cat]);
        }
        $this->db->executeStatement("UPDATE product SET price = 1000 WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement("UPDATE product SET price = 521, tax_category = 'reduced' WHERE code = 'DEMO-TSHIRT'");
        $this->assertSame([], static::getContainer()->get(TaxCategoryAudit::class)->orphanCategories(), 'toutes les catégories utilisées ont leur taxe');

        $items = [['productVariantId' => $this->cap, 'quantity' => 1], ['productVariantId' => $this->tshirt, 'quantity' => 1]];
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register]));
        $this->assertSame([1521, 1750, 1750, 1], [$q['subtotal'], $q['total'], $q['cashTotal'], $this->json($this->request('GET', '/api/pos/settings'))['cashRoundingStep']], '10,00 € HT à 20 % + 5,21 € HT à 5,5 % = 17,50 € ; aucun arrondi des espèces en EUR');
        $this->assertSame([['TVA 20 %', 1000, 200, 'standard'], ['TVA 5,5 %', 521, 29, 'reduced']], array_map(fn ($t) => [$t['label'], $t['base'], $t['amount'], $t['category']], $q['taxes']), 'assiette par taux : chaque taxe ne frappe que sa catégorie ; les TVA sans ligne (10 %, 2,1 %) ne sont pas servies');
        $this->assertSame(229, $q['total'] - $q['subtotal']);

        // Remise de ticket de 10 % : répartie au prorata des nets (F1), puis taxes par catégorie sur les nets → 9,00 + 4,69 = 13,69 € HT, TVA 1,80 + 0,26
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register, 'ticketDiscount' => ['type' => 'percent', 'value' => 10, 'reasonCode' => 'fidelite']]));
        $this->assertSame([1369, 1575, [900, 469], [180, 26]], [$q['subtotal'], $q['total'], array_column($q['taxes'], 'base'), array_column($q['taxes'], 'amount')]);

        // Article libre à 10 % (custom.taxCategory) et article libre non taxable ; catégorie inconnue refusée
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => [['quantity' => 2, 'custom' => ['label' => 'Plat du jour', 'unitPrice' => 1250, 'taxCategory' => 'intermediate']], ['quantity' => 1, 'custom' => ['label' => 'Pourboire', 'unitPrice' => 300, 'taxable' => false]]], 'cashRegisterId' => $this->register]));
        $this->assertSame([2800, 3050, [2500], [250], ['intermediate', 'exempt']], [$q['subtotal'], $q['total'], array_column($q['taxes'], 'base'), array_column($q['taxes'], 'amount'), array_column($q['lines'], 'taxCategory')]);
        $bad = $this->request('POST', '/api/pos/quote', ['items' => [['quantity' => 1, 'custom' => ['label' => 'X', 'unitPrice' => 100, 'taxCategory' => 'luxe']]], 'cashRegisterId' => $this->register]);
        $this->assertSame(422, $bad->getStatusCode());
        $this->assertStringContainsString('custom.taxCategory', $bad->getContent());

        // Vente : catégories figées, assiette par taxe, ticket avec SIRET et TVA intracom. (pas l'EIN), paquet hors ligne v2
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 17.5]], 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([1750.0, 229.0], array_map('floatval', $this->db->fetchNumeric('SELECT total_amount, total_tax FROM "order" WHERE id = ?', [$sale['orderId']])));
        $this->assertSame([['standard', 1000.0], ['reduced', 521.0]], array_map(fn ($r) => [$r['tax_category'], (float) $r['total_price']], $this->db->fetchAllAssociative('SELECT tax_category, total_price FROM order_items WHERE order_associated_id = ? ORDER BY id', [$sale['orderId']])));
        $this->assertSame([['TVA 20 %', 1000, 200.0], ['TVA 5,5 %', 521, 29.0]], array_map(fn ($r) => [$r['name'], (int) $r['base'], (float) $r['amount']], $this->db->fetchAllAssociative('SELECT t.name, ot.base, ot.amount FROM order_tax ot JOIN tax t ON t.id = ot.tax_id WHERE ot.order_tax_id = ? ORDER BY ot.id', [$sale['orderId']])), 'order_tax : aucune ligne à 0');
        $receipt = $this->json($this->request('GET', '/api/pos/receipts?orderId=' . $sale['orderId']));
        $this->assertSame([['label' => 'SIRET', 'number' => '732 829 320 00074'], ['label' => 'TVA intracom.', 'number' => 'FR40303265045']], $receipt['store']['taxIds'], 'magasin français : SIRET puis TVA intracom., jamais l\'EIN');
        $this->assertSame(['TVA 20 %', 10.0, 2.0, 12.0, 'standard'], [$receipt['taxes'][0]['label'], (float) $receipt['taxes'][0]['base'], (float) $receipt['taxes'][0]['amount'], (float) $receipt['taxes'][0]['total'], $receipt['taxes'][0]['category']]);
        // Retour de l'article à 5,5 % : TVA 5,5 % seule, 0,29 €
        $found = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale['orderId']));
        $line = array_column($found['lines'], null, 'productVariantId')[$this->tshirt];
        $this->assertSame([29, ['TVA 5,5 %'], [29]], [$line['unitTaxes'], array_column($line['taxes'], 'label'), array_column($line['taxes'], 'amount')], 'taux de la vente d\'origine, par catégorie ; seules les taxes qui frappent la ligne');
        // SIRET vide : l'ancien champ ein (étiqueté « SIRET » avant le 01/11/2026) est imprimé à sa place
        $this->db->executeStatement("UPDATE entreprise SET siret = NULL, ein = '732 829 320 00075'");
        $receipt = $this->json($this->request('GET', '/api/pos/receipts?orderId=' . $sale['orderId']));
        $this->assertSame('732 829 320 00075', $receipt['store']['taxIds'][0]['number']);

        $pack = $this->json($this->request('GET', '/api/pos/offline-pack?cashRegisterId=' . $this->register));
        $this->assertSame([\App\Services\PosOfflineService\PosOfflineCalculator::RULE, false, ['standard', 'intermediate', 'reduced', 'super_reduced']], [$pack['calculationRule'], $pack['site']['pricesIncludeTax'], array_column($pack['taxes'], 'category')]); // v3 depuis 2b
        $byId = array_column($pack['products'], null, 'id');
        $this->assertSame('reduced', $byId[(int) $this->db->fetchOne("SELECT id FROM product WHERE code = 'DEMO-TSHIRT'")]['taxCategory']);
        $cases = $this->json($this->request('GET', '/api/pos/offline-cases'));
        $this->assertSame(\App\Services\PosOfflineService\PosOfflineCalculator::RULE, $cases['rule']);
        $this->assertContains('fr-ht-deux-taux-standard-et-reduit', array_column($cases['cases'], 'id'));
        $settings = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertSame([false, ['standard', 'intermediate', 'reduced', 'super_reduced']], [$settings['pricesIncludeTax'], array_column($settings['taxCategories'], 'category')], 'taxCategories = catégories des taxes du magasin, avec leur taux (2b)');

        // Point C : un produit « super_reduced » sans taxe de cette catégorie n'est pas taxé du tout → alerte EasyAdmin, devis sans taxe sur cette ligne
        $this->db->executeStatement("UPDATE tax SET category = 'standard' WHERE name = 'TVA 2,1 %'"); // plus aucune taxe ne vise « super_reduced » (la ligne reste : des commandes la référencent)
        $this->db->executeStatement("UPDATE product SET tax_category = 'super_reduced' WHERE code = 'DEMO-TSHIRT'");
        $this->assertSame(['super_reduced'], static::getContainer()->get(TaxCategoryAudit::class)->orphanCategories());
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => [['productVariantId' => $this->tshirt, 'quantity' => 1]], 'cashRegisterId' => $this->register]));
        $this->assertSame([521, 521, []], [$q['subtotal'], $q['total'], $q['taxes']], 'devis : aucune taxe sur la ligne orpheline (liste vide, statut calculated)');
        $this->loginAdmin();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'index', 'crudControllerFqcn' => TaxCrudController::class]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Super réduit (2,1 % en France)', $this->client->getResponse()->getContent(), 'alerte en tête de la liste des taxes');
        $this->assertStringContainsString('aucune taxe de ce site ne la frappe', $this->client->getResponse()->getContent());
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

    private function user(array $roles): User
    {
        $email = 'taxes-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Taxes')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }
}
