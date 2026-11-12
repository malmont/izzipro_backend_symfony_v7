<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Order;
use App\Entity\OrderTax;
use App\Entity\Tax;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\OrderService\TaxCalculationService;
use App\Services\OrderService\TaxEngine;
use App\Services\OrderService\TaxInUseException;
use App\Services\PosOfflineService\PosOfflineCalculator;
use App\Services\PosOfflineService\PosOfflineSyncService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeTerminalGateway;
use DoctrineMigrations\Version20261223100000;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * PAYS-1 (contrat validé le 12/11/2026) : taxe gelée sur chaque ligne order_tax à la vente (libellé, taux en millionièmes, code,
 * juridiction, catégorie, mode TTC, source). Un taux modifié ensuite ne change ni le ticket (ni sa réimpression), ni le X, ni le Z, ni
 * l'export comptable, ni le retour. Hors ligne : taux de la tablette gelé, écart avec le taux valide en base consigné. Taxe utilisée
 * protégée (TAX_IN_USE), archivable et bornable ; taxes datées ; déclencheur ; reprise des lignes existantes.
 */
class TaxFreezeApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
    private int $terminalId = 0;
    private int $register;
    private int $cap;
    /** @var array<int, array{name: string, rate: float}> taux d'origine, remis en fin de test */
    private array $savedTaxes = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeTerminalGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-gel-taxes@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', prices_include_tax = false, pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_header_required = false,
            pos_pay_cash = true, pos_return_max_days = 30, pos_offline_enabled = true, pos_offline_max_hours = 24, pos_offline_max_queued_sales = 100, pos_offline_max_queued_amount = 500000, pos_offline_price_tolerance = 5, pos_offline_pack_validity_hours = 24");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement("UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = 'C1-'");
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement("UPDATE product SET price = 2900, special_price = NULL, is_pos = true, tax_category = NULL WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500, price = NULL WHERE id = ?', [$this->cap]);
        $this->db->executeStatement('UPDATE tax SET archived_at = NULL, valid_from = NULL, valid_to = NULL');
        foreach (['tax', 'order_tax'] as $table) { // séquences alignées (lignes du modèle copiées en SQL)
            $this->db->executeStatement("SELECT setval('{$table}_id_seq', GREATEST((SELECT COALESCE(MAX(id), 0) FROM $table), (SELECT last_value FROM {$table}_id_seq)))");
        }
        foreach ($this->db->fetchAllAssociative("SELECT id, name, rate FROM tax WHERE name IN ('TPS', 'TVQ')") as $t) {
            $this->savedTaxes[(int) $t['id']] = ['name' => $t['name'], 'rate' => (float) $t['rate']];
        }
        $provider->getEntityManager()->clear();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedTaxes as $id => $t) { // taux d'origine (modifiés en SQL direct, comme une ancienne édition de la table)
            $this->db->executeStatement('UPDATE tax SET name = ?, rate = ? WHERE id = ?', [$t['name'], $t['rate'], $id]);
        }
        parent::tearDown();
    }

    public function testSaleFreezesTaxesAndLaterRateChangesLeaveReceiptXZExportAndReturnUnchanged(): void
    {
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 2]], 'paymentMethods' => [['type' => 2, 'amount' => 66.70]], 'cashRegisterId' => $this->register]), 201);
        $orderId = (int) $sale['orderId'];
        $this->assertSame([
            ['tax_label' => 'TPS', 'tax_rate_ppm' => 50000, 'tax_code' => 'gst', 'tax_jurisdiction' => 'CA-QC', 'tax_category' => null, 'prices_include_tax' => false, 'tax_source' => 'table'],
            ['tax_label' => 'TVQ', 'tax_rate_ppm' => 99750, 'tax_code' => 'qst', 'tax_jurisdiction' => 'CA-QC', 'tax_category' => null, 'prices_include_tax' => false, 'tax_source' => 'table'],
        ], $this->frozen($orderId), 'taxes gelées à la vente');
        $this->assertSame([
            ['name' => 'TPS', 'label' => 'TPS', 'rate' => 0.05, 'category' => null, 'code' => 'gst', 'ratePpm' => 50000, 'jurisdiction' => 'CA-QC', 'rateSource' => 'sale'],
            ['name' => 'TVQ', 'label' => 'TVQ', 'rate' => 0.09975, 'category' => null, 'code' => 'qst', 'ratePpm' => 99750, 'jurisdiction' => 'CA-QC', 'rateSource' => 'sale'],
        ], array_map(fn ($t) => array_diff_key($t, array_flip(['amount', 'base', 'total'])), $sale['taxes']), 'ticket : nouvelles clés en ajout');
        $receiptBefore = $this->json($this->request('GET', '/api/pos/receipts?orderId=' . $orderId));
        $xBefore = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));

        // Ancienne édition de la table (SQL direct, sans le garde) : nom et taux de la TVQ changent après la vente
        $this->db->executeStatement("UPDATE tax SET name = 'TVQ modifiée', rate = 0.12 WHERE name = 'TVQ'");
        $receiptAfter = $this->json($this->request('GET', '/api/pos/receipts?orderId=' . $orderId));
        $this->assertSame($receiptBefore['taxes'], $receiptAfter['taxes'], 'réimpression : mêmes taxes qu\'à la vente');
        $this->assertSame($sale['taxes'], $receiptAfter['taxes']);
        $xAfter = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $this->assertSame($xBefore['taxes'], $xAfter['taxes'], 'rapport X inchangé');
        $this->assertSame([['TPS', 0.05, 'gst', 50000], ['TVQ', 0.09975, 'qst', 99750]], array_map(fn ($t) => [$t['label'], $t['rate'], $t['code'], $t['ratePpm']], $xAfter['taxes']));

        // Retour d'une unité : la taxe de la vente d'origine, gelée sur le retour (source « return »)
        $line = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $orderId))['lines'][0];
        $this->assertSame([0.05, 0.09975], array_map(fn ($t) => $t['rate'], $line['taxes']), 'aperçu du retour au taux de la vente');
        $ret = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $orderId, 'lines' => [['orderItemId' => $line['orderItemId'], 'quantity' => 1]], 'reasonCode' => 'autre', 'cashRegisterId' => $this->register]), 201);
        $returnOrder = (int) $this->db->fetchOne('SELECT return_order_id FROM pos_return WHERE id = ?', [$ret['returnId'] ?? $ret['id']]);
        $this->assertSame([['TPS', 50000, 'gst', 'return'], ['TVQ', 99750, 'qst', 'return']], array_map(fn ($r) => [$r['tax_label'], $r['tax_rate_ppm'], $r['tax_code'], $r['tax_source']], $this->frozen($returnOrder)));
        $this->assertSame(['TPS', 'TVQ'], array_column($ret['taxes'], 'label'));

        // Clôture (Z) puis export comptable : libellés et taux gelés
        $x = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $z = $this->json($this->request('POST', '/api/caisse/close', ['cashRegisterId' => $this->register, 'countedCash' => $x['expectedCash'], 'keptFund' => 5000]))['report'];
        $this->assertSame([['TPS', 0.05], ['TVQ', 0.09975]], array_map(fn ($t) => [$t['label'], $t['rate']], $z['taxes']), 'Z : taux de la vente');
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('America/Toronto')))->format('Y-m-d');
        $e = $this->json($this->request('GET', "/api/pos/exports/accounting?from=$today&to=$today&cashRegisterId=$this->register"));
        $this->assertSame(['TPS', 'TVQ'], array_keys(end($e['days'])['taxes']), 'export : libellés de la vente');
        $csv = $this->request('GET', "/api/pos/exports/accounting/entries.csv?from=$today&to=$today&cashRegisterId=$this->register")->getContent();
        $this->assertStringContainsString('2320', $csv, 'compte de la TVQ (code qst gelé) malgré le nouveau nom de la taxe');
        $this->assertStringNotContainsString('TVQ modifiée', $csv);
    }

    public function testOfflineSaleFreezesTheTabletRateAndRecordsTheDriftWithoutRefusing(): void
    {
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->terminal = $this->posTerminal();
        $seller = $this->user(['ROLE_USER_POS']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $pack = $this->json($this->request('GET', "/api/pos/offline-pack?cashRegisterId=$this->register"));
        $this->assertSame([['TPS', 0.05], ['TVQ', 0.09975]], array_map(fn ($t) => [$t['label'], $t['rate']], $pack['taxes']));

        // Sans changement de taux : aucun écart
        $first = ['packVersion' => $pack['packVersion'], 'deviceTime' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), 'sales' => [$sale1 = $this->offlineSale($seller, 'P-' . $this->terminalId . '-1', [['productVariantId' => $this->cap, 'quantity' => 1]], $pack)]];
        $a = $this->json($this->request('POST', '/api/pos/offline-sales', $first))['results'][0];
        $this->assertSame(['accepted', []], [$a['status'], $a['taxRateDrift']], json_encode($a));
        $this->assertSame([['TPS', 50000, 'offline'], ['TVQ', 99750, 'offline']], array_map(fn ($r) => [$r['tax_label'], $r['tax_rate_ppm'], $r['tax_source']], $this->frozen((int) $a['orderId'])));

        // La TVQ change en base pendant que la tablette garde son pack : vente acceptée, taux de la tablette gelé, écart consigné
        $this->db->executeStatement("UPDATE tax SET rate = 0.1 WHERE name = 'TVQ'");
        $second = ['packVersion' => $pack['packVersion'], 'deviceTime' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), 'sales' => [$this->offlineSale($seller, 'P-' . $this->terminalId . '-2', [['productVariantId' => $this->cap, 'quantity' => 1]], $pack, $sale1['chain']['hash'])]];
        $b = $this->json($this->request('POST', '/api/pos/offline-sales', $second))['results'][0];
        $this->assertSame('accepted', $b['status'], json_encode($b));
        $this->assertSame([['label' => 'TVQ', 'code' => 'qst', 'appliedRatePpm' => 99750, 'expectedRatePpm' => 100000]], $b['taxRateDrift'], 'rapport de synchronisation');
        $this->assertSame([['TPS', 50000], ['TVQ', 99750]], array_map(fn ($r) => [$r['tax_label'], $r['tax_rate_ppm']], $this->frozen((int) $b['orderId'])), 'taux appliqué par la tablette');
        $payload = json_decode((string) $this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'SALE' AND payload LIKE ? ORDER BY id DESC LIMIT 1", ['%P-' . $this->terminalId . '-2%']), true);
        $this->assertSame($b['taxRateDrift'], $payload['data']['offline']['taxRateDrift'] ?? null, 'journal : taux appliqué et taux attendu');
        $stored = json_decode((string) $this->db->fetchOne('SELECT result FROM pos_offline_sale WHERE order_id = ?', [$b['orderId']]), true);
        $this->assertSame($b['taxRateDrift'], $stored['taxRateDrift'], 'rapport gardé avec la vente hors ligne');
        $payloadFirst = json_decode((string) $this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'SALE' AND payload LIKE ? ORDER BY id DESC LIMIT 1", ['%P-' . $this->terminalId . '-1"%']), true);
        $this->assertArrayNotHasKey('taxRateDrift', $payloadFirst['data']['offline'], 'sans écart : journal inchangé');
    }

    public function testStripeTaxIsFrozenWithItsOwnLabelRateAndJurisdiction(): void
    {
        $em = $this->em();
        $order = (new Order())->setReference('REF#gel-stripe-' . bin2hex(random_bytes(3)));
        $order->setPendingTaxes([['label' => 'GST', 'rate' => 0.05, 'base' => 2000, 'amount' => 100, 'total' => 2100, 'jurisdiction' => 'CA BC'], ['label' => 'PST', 'rate' => 0.07, 'base' => 2000, 'amount' => 140, 'total' => 2140, 'jurisdiction' => 'CA BC']]);
        static::getContainer()->get(TaxCalculationService::class)->calculateTaxes($order, 2000.0, false);
        $this->assertSame([['GST', 50000, 'gst', 'CA-BC', 'stripe'], ['PST', 70000, 'pst', 'CA-BC', 'stripe']],
            array_map(fn (OrderTax $t) => [$t->getTaxLabel(), $t->getTaxRatePpm(), $t->getTaxCode(), $t->getTaxJurisdiction(), $t->getTaxSource()], $order->getOrderTaxes()->toArray()));
        $this->assertSame(['GST', 0.05, 'sale'], [$order->getOrderTaxes()->first()->snapshot()['label'], $order->getOrderTaxes()->first()->snapshot()['rate'], $order->getOrderTaxes()->first()->snapshot()['rateSource']], 'Stripe Tax : libellé et taux réels au lieu de « Taxe » sans taux');
        $this->expectException(\LogicException::class);
        $order->getOrderTaxes()->first()->freeze(['label' => 'X', 'ratePpm' => 1, 'code' => 'other', 'jurisdiction' => null, 'category' => null, 'pricesIncludeTax' => false, 'source' => 'table']);
        unset($em);
    }

    public function testUsedTaxIsProtectedButArchivableAndBoundableAndDatesSelectTheTaxes(): void
    {
        $em = $this->em();
        $used = (new Tax())->setName('TVA test')->setRate(0.2)->setType('TVA')->setCountry('ZZ')->setProvince('Toutes');
        $free = (new Tax())->setName('Libre')->setRate(0.1)->setType('TVA')->setCountry('ZZ')->setProvince('Toutes');
        $em->persist($used);
        $em->persist($free);
        $em->flush();
        $orderId = (int) $this->db->fetchOne('SELECT MIN(id) FROM "order"');
        $this->db->executeStatement('INSERT INTO order_tax (id, order_tax_id, tax_id, amount) VALUES (nextval(\'order_tax_id_seq\'), ?, ?, 0)', [$orderId ?: null, $used->getId()]);
        try {
            $used->setRate(0.21);
            try {
                $em->flush();
                $this->fail('taux d\'une taxe utilisée modifié');
            } catch (TaxInUseException $e) {
                $this->assertSame(['rate'], $e->fields);
                $this->assertStringStartsWith('TAX_IN_USE', $e->getMessage());
            }
            $em->refresh($used);
            $used->setCode('vat');
            $this->assertSame(['code'], $this->refused($em, $used), 'code figé');
            $used->setValidFrom(new \DateTimeImmutable('2020-01-01'));
            $this->assertSame(['validFrom'], $this->refused($em, $used), 'début de validité figé');
            $em->remove($used);
            try {
                $em->flush();
                $this->fail('taxe utilisée supprimée');
            } catch (TaxInUseException $e) {
                $this->assertTrue($e->deletion);
            }
            $em->clear();
            $used = $em->find(Tax::class, $used->getId());
            $used->setArchived(true)->setValidTo(new \DateTimeImmutable('tomorrow'))->setType('TVA archivée');
            $em->flush(); // archivage, fin de validité et type : permis
            $this->assertTrue($em->find(Tax::class, $used->getId())->isArchived());
            $free = $em->find(Tax::class, $free->getId());
            $free->setRate(0.11)->setCode('vat');
            $em->flush(); // taxe jamais utilisée : modifiable
            $em->remove($free);
            $em->flush();
            $this->assertFalse((bool) $this->db->fetchOne('SELECT 1 FROM tax WHERE id = ?', [$free->getId() ?? 0]));

            // Dates (jours du magasin, du inclus, au exclu) et archivage
            $storeToday = new \DateTimeImmutable(static::getContainer()->get(\App\Services\CaisseService\StoreClock::class)->day(new \DateTimeImmutable()));
            $day = fn (?string $rel) => $rel === null ? null : $storeToday->modify($rel);
            $mk = fn (string $name, ?string $from, ?string $to, bool $archived = false) => (new Tax())->setName($name)->setRate(0.05)->setType('TVA')->setCountry('ZZ')->setProvince('Toutes')
                ->setValidFrom($day($from))->setValidTo($day($to))->setArchived($archived);
            foreach ([$mk('Toujours', null, null), $mk('Demain', '+1 day', null), $mk('Finie', null, '+0 day'), $mk('Archivée', null, null, true), $mk('Jusqu\'à demain', null, '+1 day')] as $t) {
                $em->persist($t);
            }
            $em->flush();
            $engine = static::getContainer()->get(TaxEngine::class);
            $names = fn (?\DateTimeImmutable $at) => array_values(array_filter(array_map(fn (Tax $t) => $t->getName(), $engine->applicableTaxes(['country' => 'ZZ', 'province' => null], $at)), fn ($n) => in_array($n, ['Toujours', 'Demain', 'Finie', 'Archivée', 'Jusqu\'à demain'], true)));
            $this->assertSame(['Toujours', 'Jusqu\'à demain'], $names(null), 'aujourd\'hui');
            $tz = static::getContainer()->get(\App\Services\CaisseService\StoreClock::class)->timezone();
            $this->assertSame(['Toujours', 'Demain'], $names(new \DateTimeImmutable($storeToday->modify('+1 day')->format('Y-m-d') . ' 12:00', $tz)), 'demain : début inclus, fin exclue');
            $this->assertSame(['Toujours', 'Demain'], $names(new \DateTimeImmutable('+2 days')), 'après-demain');
            $this->assertSame(['Toujours', 'Finie', 'Jusqu\'à demain'], $names(new \DateTimeImmutable('-2 days')), 'avant-hier');
        } finally {
            $this->db->executeStatement("DELETE FROM order_tax WHERE tax_id IN (SELECT id FROM tax WHERE country = 'ZZ')");
            $this->db->executeStatement("DELETE FROM tax WHERE country = 'ZZ'");
        }
    }

    public function testTriggerRefusesChangingAFrozenLineButAllowsAmountAndDelete(): void
    {
        $taxId = (int) array_key_first($this->savedTaxes);
        $id = (int) $this->db->fetchOne("INSERT INTO order_tax (id, tax_id, amount, tax_label, tax_rate_ppm, tax_code, tax_source) VALUES (nextval('order_tax_id_seq'), ?, 145, 'TPS', 50000, 'gst', 'table') RETURNING id", [$taxId]);
        $this->db->executeStatement('UPDATE order_tax SET amount = 0 WHERE id = ?', [$id]); // annulation : le montant n'est pas gelé
        foreach (['tax_rate_ppm = 1', "tax_label = 'X'", "tax_code = 'vat'", "tax_jurisdiction = 'FR'", "tax_category = 'reduced'", 'prices_include_tax = true', "tax_source = 'backfill'"] as $set) {
            try {
                $this->db->executeStatement("UPDATE order_tax SET $set WHERE id = ?", [$id]);
                $this->fail("modification acceptée : $set");
            } catch (\Doctrine\DBAL\Exception $e) {
                $this->assertStringContainsString('ORDER_TAX_FROZEN', $e->getMessage());
            }
        }
        $this->assertSame(1, $this->db->executeStatement('DELETE FROM order_tax WHERE id = ?', [$id]), 'suppression permise (tests, anonymisation)');
    }

    public function testBackfillFreezesExistingLinesOnceAndLegacyLinesFallBackToTheTable(): void
    {
        [$tps] = array_keys(array_filter($this->savedTaxes, fn ($t) => $t['name'] === 'TPS'));
        $orderId = (int) $this->db->fetchOne('SELECT MIN(id) FROM "order"');
        $legacy = (int) $this->db->fetchOne("INSERT INTO order_tax (id, order_tax_id, tax_id, amount, base) VALUES (nextval('order_tax_id_seq'), ?, ?, 145, 2900) RETURNING id", [$orderId, $tps]);
        $subscription = (int) $this->db->fetchOne("INSERT INTO order_tax (id, order_tax_id, tax_id, amount) VALUES (nextval('order_tax_id_seq'), ?, NULL, 524) RETURNING id", [$orderId]);
        try {
            $snap = $this->em()->find(OrderTax::class, $legacy)->snapshot();
            $this->assertSame(['TPS', 0.05, 50000, 'gst', 'live'], [$snap['label'], $snap['rate'], $snap['ratePpm'], $snap['code'], $snap['rateSource']], 'ligne non gelée : repli signalé');
            require_once dirname(__DIR__, 3) . '/migrations/Version20261223100000.php';
            foreach (Version20261223100000::STATEMENTS as $sql) {
                $this->db->executeStatement($sql);
            }
            $row = fn (int $id) => $this->db->fetchAssociative('SELECT tax_label, tax_rate_ppm, tax_code, tax_source FROM order_tax WHERE id = ?', [$id]);
            $this->assertSame(['tax_label' => 'TPS', 'tax_rate_ppm' => 50000, 'tax_code' => 'gst', 'tax_source' => 'backfill'], $row($legacy));
            $this->assertSame(['tax_label' => 'Taxe', 'tax_rate_ppm' => null, 'tax_code' => null, 'tax_source' => 'backfill'], $row($subscription));
            $this->db->executeStatement("UPDATE tax SET rate = 0.06 WHERE id = ?", [$tps]);
            foreach (Version20261223100000::STATEMENTS as $sql) { // seconde exécution : rien ne bouge
                $this->db->executeStatement($sql);
            }
            $this->assertSame(50000, $row($legacy)['tax_rate_ppm'], 'reprise idempotente');
            $snap = $this->em()->find(OrderTax::class, $legacy)->snapshot();
            $this->assertSame(['TPS', 0.05, 'backfill'], [$snap['label'], $snap['rate'], $snap['rateSource']]);
        } finally {
            $this->db->executeStatement('DELETE FROM order_tax WHERE id IN (?, ?)', [$legacy, $subscription]);
        }
    }

    /** @return list<string> champs refusés à l'enregistrement (fiche rechargée ensuite) */
    private function refused(\Doctrine\ORM\EntityManagerInterface $em, Tax $tax): array
    {
        try {
            $em->flush();
        } catch (TaxInUseException $e) {
            $em->refresh($tax);

            return $e->fields;
        }
        $this->fail('modification acceptée');
    }

    private function frozen(int $orderId): array
    {
        return array_map(fn ($r) => ['tax_label' => $r['tax_label'], 'tax_rate_ppm' => $r['tax_rate_ppm'] !== null ? (int) $r['tax_rate_ppm'] : null, 'tax_code' => $r['tax_code'], 'tax_jurisdiction' => $r['tax_jurisdiction'],
            'tax_category' => $r['tax_category'], 'prices_include_tax' => $r['prices_include_tax'], 'tax_source' => $r['tax_source']],
            $this->db->fetchAllAssociative('SELECT tax_label, tax_rate_ppm, tax_code, tax_jurisdiction, tax_category, prices_include_tax, tax_source FROM order_tax WHERE order_tax_id = ? ORDER BY id', [$orderId]));
    }

    private function em(): \Doctrine\ORM\EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function offlineSale(User $seller, string $provisional, array $items, array $pack, string $prevHash = ''): array
    {
        $lines = [];
        foreach ($items as $item) {
            foreach ($pack['products'] as $p) {
                foreach ($p['variants'] as $v) {
                    if ($v['id'] === $item['productVariantId']) {
                        $lines[] = ['unitPrice' => $v['price'] ?? $p['price'], 'quantity' => $item['quantity'], 'discounts' => []];
                    }
                }
            }
        }
        $r = PosOfflineCalculator::compute($lines, null, $pack['taxes'], $pack['site']['cashRoundingStep'], null);
        $sale = ['idempotencyKey' => self::uuid(), 'provisionalNumber' => $provisional, 'cashRegisterId' => $this->register, 'sellerId' => $seller->getId(), 'soldAt' => (new \DateTimeImmutable('-30 seconds'))->format(\DateTimeInterface::ATOM),
            'items' => $items, 'ticketDiscount' => null, 'payments' => [['type' => 2, 'amount' => $r['cashTotal']]], 'cashReceived' => $r['cashTotal'],
            'totals' => ['subtotal' => $r['subtotal'], 'taxes' => array_map(fn ($t) => ['label' => $t['label'], 'amount' => $t['amount']], $r['taxes']), 'total' => $r['total'], 'cashTotal' => $r['cashTotal'], 'cashRounding' => $r['cashRounding']]];
        $sale['chain'] = ['seq' => (int) substr($provisional, strrpos($provisional, '-') + 1), 'prevHash' => $prevHash, 'hash' => PosOfflineSyncService::hashOf($sale, $prevHash)];

        return $sale;
    }

    private function posTerminal(): string
    {
        $saved = $this->token;
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $key = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette gel des taxes', 'sendEmail' => false]), 201)['key'];
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

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null, 'HTTP_X_POS_TERMINAL' => $this->terminal,
            'HTTP_IDEMPOTENCY_KEY' => $method === 'POST' && str_contains($path, 'create-multi-payment') ? self::uuid() : null,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function user(array $roles): User
    {
        $email = 'gel-taxes-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gel')->setLastname('Taxes')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }

    private function loginAs(array $roles): User
    {
        $user = $this->user($roles);
        $saved = $this->terminal;
        $this->terminal = null;
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $user->getEmail(), 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
        $this->terminal = $saved;

        return $user;
    }
}
