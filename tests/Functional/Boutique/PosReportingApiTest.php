<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\PosAuditService\PosPinService;
use App\Services\PosOfflineService\PosOfflineSyncService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeTerminalGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Lot F14 (29/10/2026) : vecteur de chaînage hors ligne, fuseau du magasin, statistiques par caissier (= rapport Z), exports comptables équilibrés */
class PosReportingApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
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
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-rapports@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_header_required = false,
            pos_pay_cash = true, pos_pay_declared_card = true, pos_pay_paypal = true, pos_pay_multi = true, pos_declared_card_blocked_with_terminal = false, pos_return_max_days = NULL, pos_return_without_receipt = false, pos_return_cash_for_card_sale = true, pos_approval_return_mode = 'refund',
            pos_discount_enabled = true, pos_discount_ticket_enabled = true, pos_discount_reason_required = true, pos_discount_cashier_max_amount = 2000, pos_discount_cashier_max_percent_bp = 1000,
            pos_custom_item_enabled = true, pos_custom_item_max_amount = NULL, pos_price_override_enabled = true, pos_price_override_max_amount = NULL, pos_price_override_reason_required = false, pos_offline_enabled = false,
            pos_gift_card_enabled = true, pos_store_credit_enabled = false, pos_customers_enabled = true, pos_loyalty_enabled = false, pos_timezone = NULL, accounting_accounts = NULL");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement("UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = NULL");
        $this->db->executeStatement('UPDATE product SET is_pos = true');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = $this->variant('DEMO-CASQUETTE', 2900);
        $this->tshirt = $this->variant('DEMO-TSHIRT', 1900);
    }

    public function testChainVectorIsServedAndReproducible(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $cases = $this->json($this->request('GET', '/api/pos/offline-cases'));
        $this->assertSame(['rule', 'description', 'cases', 'chainVectors'], array_keys($cases), 'format actuel conservé, clé à part');
        $v = $cases['chainVectors'][0];
        $this->assertSame($v['canonical'], PosOfflineSyncService::canonical($v['sale']), 'JSON canonique identique à l\'octet');
        $this->assertSame($v['hash'], PosOfflineSyncService::hashOf($v['sale'], $v['prevHash']), 'hash reproduit');
        $this->assertSame($v['hash'], hash('sha256', $v['prevHash'] . $v['canonical']), 'hash = sha256(prevHash + canonique)');
        $this->assertStringContainsString('café crème à côté', $v['canonical'], 'accents non échappés');
        $this->assertStringContainsString('"ticketDiscount":{"reasonText":"Geste commercial — Noël","type":"amount","value":300}', $v['canonical'], 'clés triées');
    }

    public function testCashierStatisticsMatchTheZReportAndAccountingExportBalances(): void
    {
        $manager = $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        static::getContainer()->get(PosPinService::class)->set($manager, '3579');
        $this->terminal = $this->posTerminal();
        // Poste de caisse dédié : la base de test est partagée, d'autres tests ont des sessions aujourd'hui sur le poste principal
        $this->register = $this->json($this->request('POST', '/api/cash-registers', ['name' => 'Rapports ' . bin2hex(random_bytes(2))]), 201)['id'];
        $s = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertSame(['America/Toronto', 'province'], [$s['store']['timezone'], $s['store']['source']], 'fuseau déduit de la province (QC)');
        $this->assertSame(['4000', '2310', '2320', '2400'], [$s['accounting']['accounts']['sales']['number'], $s['accounting']['accounts']['tax_gst']['number'], $s['accounting']['accounts']['tax_qst']['number'], $s['accounting']['accounts']['gift_card_liability']['number']], 'plan par défaut québécois');
        $this->db->executeStatement("UPDATE entreprise SET pos_timezone = 'America/Martinique', accounting_accounts = 'sales=701000;Ventes de marchandises\ntax_gst=445710'");
        $s = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertSame(['America/Martinique', 'setting', '701000', 'Ventes de marchandises', true, '445710', 'TPS à payer'], [$s['store']['timezone'], $s['store']['source'], $s['accounting']['accounts']['sales']['number'], $s['accounting']['accounts']['sales']['name'], $s['accounting']['accounts']['sales']['custom'], $s['accounting']['accounts']['tax_gst']['number'], $s['accounting']['accounts']['tax_gst']['name']]);
        $this->db->executeStatement('UPDATE entreprise SET pos_timezone = NULL, accounting_accounts = NULL');
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('America/Toronto')))->format('Y-m-d');

        // Session : 2 ventes du caissier (remise, prix manuel, article libre), une carte-cadeau, un retour, une ouverture du tiroir ; clôture avec écart par le responsable
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $cashier = $this->loginAs(['ROLE_USER_POS']);
        $q1 = $this->json($this->request('POST', '/api/pos/quote', ['items' => [['productVariantId' => $this->cap, 'quantity' => 2, 'discounts' => [['type' => 'percent', 'value' => 10, 'reasonCode' => 'fidelite']]]], 'cashRegisterId' => $this->register]));
        $sale1 = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 2, 'discounts' => [['type' => 'percent', 'value' => 10, 'reasonCode' => 'fidelite']]]], 'paymentMethods' => [['type' => 2, 'amount' => $q1['cashTotal'] / 100]], 'cashRegisterId' => $this->register]), 201);
        $items2 = [['productVariantId' => $this->tshirt, 'quantity' => 1, 'priceOverride' => ['unitPrice' => 1500]], ['custom' => ['label' => 'Retouche', 'unitPrice' => 1000, 'taxable' => true], 'quantity' => 1]];
        $q2 = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items2, 'cashRegisterId' => $this->register]));
        $sale2 = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items2, 'paymentMethods' => [['type' => 1, 'amount' => $q2['total'] / 100]], 'cashRegisterId' => $this->register]), 201);
        $gift = $this->json($this->request('POST', '/api/pos/gift-cards', ['amount' => 2500, 'paymentMethods' => [['type' => 2, 'amount' => 25]], 'cashRegisterId' => $this->register]), 201);
        $line = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale1['orderId']))['lines'][0]['orderItemId'];
        $ret = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale1['orderId'], 'lines' => [['orderItemId' => $line, 'quantity' => 1]], 'reasonCode' => 'autre', 'cashRegisterId' => $this->register]), 201);
        $this->assertSame(201, $this->request('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Monnaie'])->getStatusCode());

        // Caissier : seulement ses chiffres, même avec cashierId d'un autre ; consultation journalisée
        $mine = $this->json($this->request('GET', "/api/pos/statistics/cashiers?from=$today&to=$today&cashierId=" . $manager->getId()));
        $this->assertSame(['self', $cashier->getId(), 1, $cashier->getId()], [$mine['period']['scope'], $mine['period']['cashierId'], count($mine['cashiers']), $mine['cashiers'][0]['cashierId']]);
        $this->assertNull($mine['cashiers'][0]['cashier']['email'], 'courriel masqué pour un caissier');
        $x = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));

        // Responsable : clôture avec écart de −50, puis statistiques de tous ; total = rapport Z
        $this->token = null;
        $closer = $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $z = $this->json($this->request('POST', '/api/caisse/close', ['cashRegisterId' => $this->register, 'countedCash' => $x['expectedCash'] - 50, 'discrepancyReason' => 'Test', 'keptFund' => 5000]))['report'];
        $this->assertSame(-50, $z['discrepancy']);
        $stats = $this->json($this->request('GET', "/api/pos/statistics/cashiers?from=$today&to=$today&cashRegisterId=$this->register"));
        $this->assertSame(['all', 'America/Toronto'], [$stats['period']['scope'], $stats['period']['timezone']]);
        $row = array_values(array_filter($stats['cashiers'], fn ($r) => $r['cashierId'] === $cashier->getId()))[0];
        $this->assertSame([2, 2, 1, 1, 1, 1, 1], [$row['sales']['count'], $row['discounts']['count'] + $row['priceOverrides']['count'], $row['customItems']['count'], $row['returns']['count'], $row['giftCardsSold']['count'], $row['drawerOpenings'], $row['discounts']['count']]);
        $this->assertSame([5800 + 1500 + 1000, 5220 + 1500 + 1000, 580, 400, 1000, 2500], [$row['sales']['grossHt'], $row['sales']['netHt'], $row['discounts']['amount'], $row['priceOverrides']['difference'], $row['customItems']['amount'], $row['giftCardsSold']['amount']], 'brut, net, remises, prix manuel, article libre, carte');
        $this->assertEquals([4, 2.0], [$row['sales']['items'], $row['sales']['itemsPerTicket']], '2 casquettes + t-shirt + retouche sur 2 tickets');
        $this->assertNotNull($row['cashier']['email'], 'responsable : courriel visible');
        $closerRow = array_values(array_filter($stats['cashiers'], fn ($r) => $r['cashierId'] === $closer->getId()))[0];
        $this->assertSame(['sessions' => 1, 'total' => -50, 'absolute' => 50], $closerRow['discrepancies'], 'écart de la session fermée par ce responsable');
        // Vendeurs de la session = rapport Z de la session : le caissier (unique à ce test) est le seul vendeur de cette session ; la base de test
        // partagée a d'autres sessions aujourd'hui sur ce poste, donc la ligne du caissier (et non le total du jour) doit égaler le Z
        $this->assertSame([$z['sales']['count'], $z['sales']['total'], $z['discounts']['total'], $z['returns']['count'], $z['giftCards']['sold']['amount'], $z['customItems']['count'], $z['priceOverrides']['count']],
            [$row['sales']['count'], $row['sales']['ttc'], $row['discounts']['amount'], $row['returns']['count'], $row['giftCardsSold']['amount'], $row['customItems']['count'], $row['priceOverrides']['count']], 'vendeur de la session = rapport Z');
        $this->assertGreaterThanOrEqual($row['sales']['count'], $stats['total']['sales']['count']);
        $this->assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'CASHIER_STATS_VIEWED' AND payload LIKE ?", ['%"from":"' . $today . '"%']));

        // Export comptable : aperçu, synthèse, écritures (generic et qbo), équilibre, règle de la journée d'ouverture
        $this->loginAs(['ROLE_USER_POS']);
        $this->assertSame(403, $this->request('GET', "/api/pos/exports/accounting?from=$today&to=$today")->getStatusCode(), 'caissier : export refusé');
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $e = $this->json($this->request('GET', "/api/pos/exports/accounting?from=$today&to=$today&cashRegisterId=$this->register"));
        $this->assertSame([1, $today, 'pos', true, []], [count($e['reports']), $e['days'][0]['date'], $e['source'], $e['totals']['balanced'], $e['legacyReports']], 'un seul rapport Z sur ce poste');
        $d = $e['days'][0];
        // brut HT = 5800 (casquettes) + 1500 (t-shirt au prix manuel) + 1000 (retouche) ; net = brut − 580 de remise
        $this->assertSame([2, 8300, 580, 7720, $z['sales']['total'], 1, 2500, -50, 1], [$d['salesCount'], $d['salesGrossHt'], $d['discounts'], $d['salesNetHt'], $d['salesTtc'], $d['returnsCount'], $d['giftCardsSold'], $d['discrepancy'], count($d['pieces'])]);
        $this->assertSame(['TPS', 'TVQ'], array_keys($d['taxes']));
        $this->assertSame($d['salesNetHt'], $d['taxes']['TPS']['base'], 'assiette TPS = net HT taxable');
        $this->assertGreaterThan(0, $d['receipts']['cash']);
        $this->assertSame($q2['total'], $d['receipts']['declaredCard'], 'carte déclarée ventilée à part');
        $this->assertSame($ret['refund']['cash'] + $ret['refund']['card'], $d['refunds']['cash'] + $d['refunds']['card'], 'remboursements du retour (espèces arrondies)');
        $this->assertSame(sprintf('Z-%d-%d', $this->register, $z['number']), $e['entries'][0]['piece']);
        $this->assertTrue($e['totals']['byDay'][$today]['balanced'], 'débits = crédits pour le jour');
        $this->assertSame('Un rapport Z entier est rattaché à la date d\'ouverture de sa session, dans le fuseau du magasin ; jamais coupé sur deux jours.', $e['rule']);
        $summary = $this->request('GET', "/api/pos/exports/accounting/summary.csv?from=$today&to=$today&cashRegisterId=$this->register");
        $this->assertSame(200, $summary->getStatusCode());
        $this->assertStringStartsWith("\xEF\xBB\xBFDate;\"Rapports Z\";\"Ventes (nombre)\";", $summary->getContent(), 'BOM et « ; » (champs avec espace entre guillemets)');
        $this->assertStringContainsString(';' . number_format($d['salesTtc'] / 100, 2, ',', '') . ';', $summary->getContent(), 'virgule décimale');
        $generic = $this->request('GET', "/api/pos/exports/accounting/entries.csv?from=$today&to=$today&cashRegisterId=$this->register")->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFDate;Journal;Compte;\"Libellé du compte\";Libellé;Débit;Crédit;Pièce;Source", $generic);
        $qbo = $this->request('GET', "/api/pos/exports/accounting/entries.csv?from=$today&to=$today&cashRegisterId=$this->register&format=qbo")->getContent();
        $this->assertStringStartsWith("JournalNo,JournalDate,AccountName,Description,Debits,Credits\r\n", $qbo, 'QuickBooks : virgule, sans BOM');
        $this->assertMatchesRegularExpression('/^Z-\d+-\d+,\d{2}\/\d{2}\/\d{4},"Ventes de marchandises","[^"]*",,\d+\.\d{2}\r$/m', $qbo, 'date JJ/MM/AAAA, point décimal');
        $this->assertStringContainsString('"Retours et remboursements sur ventes"', $qbo);
        $this->assertMatchesRegularExpression('/"Retours et remboursements sur ventes","[^"]*",\d+\.\d{2},\r$/m', $qbo, 'retours HT au débit');
        $this->assertSame(4, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'EXPORT_GENERATED' AND payload LIKE ?", ['%"from":"' . $today . '"%']), 'exports journalisés (aperçu, synthèse, 2 écritures)');
        // QuickBooks : au-delà de la limite de lignes par fichier, refus clair avec la période maximale conseillée (limite abaissée pour le test)
        $exports = static::getContainer()->get(\App\Services\AccountingService\PosAccountingExportService::class);
        $this->assertGreaterThan(10, count($e['entries']));
        try {
            $exports->entriesCsv($e, 'qbo', 10);
            $this->fail('EXPORT_TOO_LARGE attendu');
        } catch (\App\Services\CaisseService\CashRegisterException $ex) {
            $this->assertSame(['EXPORT_TOO_LARGE', 413, count($e['entries']), 10, null], [$ex->errorCode, $ex->status, $ex->context['lines'], $ex->context['limit'], $ex->context['suggestedTo']], 'une seule journée dépasse déjà la limite');
            $this->assertStringContainsString('format generic', $ex->getMessage());
        }
        $this->assertStringStartsWith('JournalNo', $exports->entriesCsv($e, 'qbo', count($e['entries'])), 'dans la limite : fichier produit');
        $two = $e;
        $two['days'][] = ['date' => '2099-01-01'] + array_diff_key($e['days'][0], ['date' => 1]);
        $two['entries'] = array_merge($e['entries'], array_map(fn ($l) => ['date' => '2099-01-01'] + $l, $e['entries']));
        try {
            $exports->entriesCsv($two, 'qbo', count($e['entries']));
            $this->fail('EXPORT_TOO_LARGE attendu');
        } catch (\App\Services\CaisseService\CashRegisterException $ex) {
            $this->assertSame([$today, count($e['entries'])], [$ex->context['suggestedTo'], $ex->context['linesUntilSuggestedTo']], 'période conseillée : dernier jour entier qui tient, pièces jamais coupées');
        }
        $this->db->executeStatement('UPDATE entreprise SET pos_gift_card_enabled = false, pos_custom_item_enabled = false, pos_price_override_enabled = false');
    }

    public function testChartOfAccountsSettingIsValidatedOnSave(): void
    {
        $validator = static::getContainer()->get(\Symfony\Component\Validator\Validator\ValidatorInterface::class);
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $e = $provider->getEntityManager()->getRepository(\App\Entity\Entreprise::class)->findOneBy([]);
        $violations = fn (?string $text) => array_values(array_map(fn ($v) => $v->getMessage(), array_filter(iterator_to_array($validator->validate($e->setAccountingAccounts($text))), fn ($v) => $v->getPropertyPath() === 'accountingAccounts')));
        $this->assertSame([], $violations(null), 'vide = plan par défaut');
        $this->assertSame([], $violations("sales=701000;Ventes de marchandises\ntax_gst=445710\nsales_rate_5=701005;Ventes à 5 %\ntax_hst_ON=445730;TVH Ontario\ntax_vat_20=445660\n\n"), 'clés fixes et dynamiques acceptées, lignes vides tolérées');
        $bad = $violations("sales=4000\nventes=4000\nsales=4001;Doublon\nn'importe quoi\ntax_hst_ONT=1\ncash=12 34");
        $this->assertCount(5, $bad, 'rien n\'est ignoré en silence');
        $this->assertStringContainsString('Ligne 2 : clé inconnue « ventes »', $bad[0]);
        $this->assertStringContainsString('Ligne 3 : clé « sales » en double (déjà définie ligne 1)', $bad[1]);
        $this->assertStringContainsString('Ligne 4 : format attendu « cle=numero;Nom »', $bad[2]);
        $this->assertStringContainsString('Ligne 5 : clé inconnue « tax_hst_ONT »', $bad[3]);
        $this->assertStringContainsString('Ligne 6 : numéro de compte invalide', $bad[4]);
        $e->setAccountingAccounts(null);
        $provider->getEntityManager()->flush();
    }

    // ---------------------------------------------------------------- aides

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
        $key = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette rapports', 'sendEmail' => false]), 201)['key'];
        $this->token = $saved;
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/app/activate', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['key' => $key, 'app' => 'pos']));

        return json_decode($this->client->getResponse()->getContent(), true)['terminal']['token'];
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
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null, 'HTTP_X_POS_TERMINAL' => $this->terminal,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function user(array $roles): User
    {
        $email = 'rapport-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Rapport')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
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
