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

/** Lot F8 (28/10/2026) : fiche client, recherche, rattachement web, fidélité, consentements, historique, reçu par courriel, rapports, effacement, fusion, hors ligne */
class PosCustomerApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
    private ?string $approval = null;
    private int $terminalId = 0;
    private int $register;
    private int $cap; // 29,00 → 33,34 TTC, 33,35 en espèces

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeTerminalGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-fidelite@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_header_required = false,
            pos_pay_cash = true, pos_pay_declared_card = true, pos_pay_paypal = true, pos_pay_multi = true, pos_declared_card_blocked_with_terminal = false, pos_return_max_days = NULL, pos_return_without_receipt = false, pos_return_cash_for_card_sale = true, pos_approval_return_mode = 'refund',
            pos_discount_enabled = true, pos_discount_ticket_enabled = true, pos_discount_reason_required = true, pos_discount_cashier_max_amount = 2000, pos_discount_cashier_max_percent_bp = 1000, pos_custom_item_enabled = false, pos_price_override_enabled = false, pos_offline_enabled = false,
            pos_gift_card_enabled = true, pos_store_credit_enabled = false, pos_receipt_email_enabled = true,
            pos_customers_enabled = true, pos_customer_link_enabled = true, pos_loyalty_enabled = false, pos_loyalty_points_per_dollar = 1, pos_loyalty_value_per100 = 100, pos_loyalty_min_redeem = 500, pos_loyalty_redeem_step = 100, pos_loyalty_expiry_months = NULL, pos_consent_text_version = '2026-10', pos_customer_search_per_minute = 30");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement("UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = NULL");
        $this->db->executeStatement('UPDATE product SET is_pos = true');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = $this->variant('DEMO-CASQUETTE', 2900);
    }

    public function testCustomerFileLoyaltyPrivacyAndReports(): void
    {
        $manager = $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        static::getContainer()->get(PosPinService::class)->set($manager, '2468');
        $this->terminal = $this->posTerminal();
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 20000]);
        $cashier = $this->loginAs(['ROLE_USER_POS']);
        $s = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertSame([true, true, '2026-10', false, 1, 100, 500, 100, null], [$s['customers']['enabled'], $s['customers']['linkEnabled'], $s['customers']['consentTextVersion'], $s['loyalty']['enabled'], $s['loyalty']['pointsPerDollar'], $s['loyalty']['valuePer100Points'], $s['loyalty']['minRedeem'], $s['loyalty']['redeemStep'], $s['loyalty']['expiryMonths']]);
        $this->assertSame([['required' => true], ['required' => true]], [$s['approvals']['loyaltyAdjust'], $s['approvals']['customerErase']]);

        // Création rapide, masquage pour le caissier, consentements datés, code de fidélité ; doublon refusé
        $this->assertSame([422, 'VALIDATION_FAILED'], $this->error($this->request('POST', '/api/pos/customers', ['phone' => '5145551234'])), 'nom requis');
        $this->assertSame([422, 'VALIDATION_FAILED'], $this->error($this->request('POST', '/api/pos/customers', ['lastName' => 'Sans contact'])), 'téléphone ou courriel requis');
        $c = $this->json($this->request('POST', '/api/pos/customers', ['firstName' => 'Marie', 'lastName' => 'Tremblay', 'phone' => '(514) 555-1234', 'email' => 'Marie.T@Example.invalid', 'consents' => ['loyalty' => true, 'receipts' => true]], ['HTTP_IDEMPOTENCY_KEY' => self::uuid()]), 201);
        $this->assertSame(['pos', 'Marie Tremblay', '•••• 1234', 'm***@example.invalid', false, null], [$c['source'], $c['displayName'], $c['phone'], $c['email'], $c['linkedAccount'], $c['userId']], 'caissier : téléphone et courriel masqués');
        $this->assertMatchesRegularExpression('/^FD-[23456789A-HJ-NP-Z]{4}-[23456789A-HJ-NP-Z]{4}$/', $c['loyalty']['code']);
        $this->assertSame([true, true, false, false, '2026-10'], [$c['consents']['loyalty']['granted'], $c['consents']['receipts']['granted'], $c['consents']['marketing']['granted'], $c['consents']['account_link']['granted'], $c['consents']['loyalty']['textVersion']], 'marketing jamais présumé');
        $this->assertSame([409, 'CUSTOMER_EXISTS'], $this->error($this->request('POST', '/api/pos/customers', ['firstName' => 'M', 'lastName' => 'T', 'phone' => '+1 514 555 1234'])), 'même numéro sous une autre écriture (E.164)');
        $this->assertSame('+15145551234', $this->db->fetchOne('SELECT phone FROM pos_customer WHERE id = ?', [$c['id']]), 'téléphone enregistré en E.164');
        $id = $c['id'];

        // Recherche : nom (3 caractères), téléphone, courriel, code de fidélité ; journal des recherches avec requête masquée
        $this->assertSame([422, 'VALIDATION_FAILED'], $this->error($this->request('GET', '/api/pos/customers?q=tr')));
        $this->assertSame([$id], array_column($this->json($this->request('GET', '/api/pos/customers?q=trem'))['results'], 'id'));
        foreach (['+1 514 555 1234', '514-555-1234', '(514) 555-1234', '5145551234'] as $written) {
            $this->assertSame([$id], array_column($this->json($this->request('GET', '/api/pos/customers?q=' . urlencode($written)))['results'], 'id'), "même fiche pour « $written »");
        }
        $this->assertSame($id, $this->json($this->request('GET', '/api/pos/customers?q=marie.t@example.invalid'))['results'][0]['id']);
        $this->assertSame($id, $this->json($this->request('GET', '/api/pos/customers?q=IZ-F-' . $c['loyalty']['code']))['results'][0]['id']);
        $this->assertSame([], $this->json($this->request('GET', '/api/pos/customers?q=inconnu'))['results']);
        $logs = $this->db->fetchFirstColumn("SELECT payload FROM pos_audit_log WHERE type = 'CUSTOMER_SEARCHED' ORDER BY id DESC LIMIT 8");
        $this->assertCount(8, $logs);
        $this->assertStringNotContainsString('marie.t@', implode('', $logs), 'courriel jamais en clair dans le journal');
        $this->assertStringNotContainsString('5145551234', implode('', $logs), 'téléphone jamais en clair dans le journal');
        $this->assertStringContainsString('m***@example.invalid', implode('', $logs));

        // Limite de recherches par utilisateur et par minute (anti-énumération des comptes web), réglable ; le refus est journalisé sans compter
        $this->db->executeStatement('UPDATE entreprise SET pos_customer_search_per_minute = 8');
        $limited = $this->request('GET', '/api/pos/customers?q=limite');
        $this->assertSame([429, 'CUSTOMER_SEARCH_RATE_LIMITED'], $this->error($limited), 'déjà 8 recherches dans la minute');
        $this->assertSame(8, json_decode($limited->getContent(), true)['limit']);
        $this->db->executeStatement('UPDATE entreprise SET pos_customer_search_per_minute = 30');
        $this->assertSame(200, $this->request('GET', '/api/pos/customers?q=limite')->getStatusCode(), 'limite relevée');
        $this->db->executeStatement('UPDATE entreprise SET pos_customer_search_per_minute = 0');
        $this->assertSame(200, $this->request('GET', '/api/pos/customers?q=limite')->getStatusCode(), '0 = sans limite');
        $this->db->executeStatement('UPDATE entreprise SET pos_customer_search_per_minute = 30');

        // Compte web : sortie seulement sur courriel exact ; jamais par nom ; rattachement avec consentement account_link
        $web = $this->user(['ROLE_USER', 'ROLE_USER_INTERNET']);
        $this->db->executeStatement('UPDATE "user" SET firstname = ?, lastname = ? WHERE id = ?', ['Jean', 'Webclient', $web->getId()]);
        $r = $this->json($this->request('GET', '/api/pos/customers?q=' . $web->getEmail()));
        $this->assertSame([['web', $web->getId(), false]], array_map(fn ($x) => [$x['source'], $x['userId'], $x['linkedAccount']], $r['results']));
        $this->assertStringStartsWith('c***@', $r['results'][0]['email'], 'courriel du compte web masqué pour le caissier');
        $this->assertSame([], $this->json($this->request('GET', '/api/pos/customers?q=webclient'))['results'], 'jamais de parcours du fichier web par nom');
        $this->assertSame([409, 'CONSENT_REQUIRED'], $this->error($this->request('POST', "/api/pos/customers/$id/link", ['userId' => $web->getId()])));
        $this->json($this->request('PATCH', "/api/pos/customers/$id", ['consents' => ['account_link' => true]]));
        $linked = $this->json($this->request('POST', "/api/pos/customers/$id/link", ['userId' => $web->getId()]));
        $this->assertSame([true, null], [$linked['linkedAccount'], $linked['userId']], 'rattaché ; identifiant du compte caché au caissier');
        $this->assertSame([], $this->json($this->request('GET', '/api/pos/customers?q=' . $web->getEmail()))['results'], 'compte rattaché : plus proposé comme résultat web');
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM "user" WHERE email = ?', ['marie.t@example.invalid']), 'aucun compte web créé');
        $other = $this->json($this->request('POST', '/api/pos/customers', ['firstName' => 'Paul', 'lastName' => 'Doublon', 'phone' => '4385550000', 'consents' => ['account_link' => true]]), 201);
        $this->assertSame([409, 'USER_ALREADY_LINKED'], $this->error($this->request('POST', "/api/pos/customers/{$other['id']}/link", ['userId' => $web->getId()])));

        // Fiche et consultation journalisée ; responsable : coordonnées en clair
        $this->json($this->request('GET', "/api/pos/customers/$id"));
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'CUSTOMER_VIEWED' AND payload LIKE ?", ['%"customerId":' . $id . '%']));

        // Vente rattachée sans programme : aucun point ; programme activé : 1 point par dollar sur le net hors part carte-cadeau
        $items = [['productVariantId' => $this->cap, 'quantity' => 1]];
        $sale0 = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 33.35]], 'cashRegisterId' => $this->register, 'customerId' => $id]), 201);
        $this->assertSame(['Marie T.', 0, 0], [$sale0['customer']['name'], $sale0['customer']['loyalty']['earned'], $sale0['customer']['loyalty']['balance']]);
        $this->assertSame($id, (int) $this->db->fetchOne('SELECT customer_id FROM "order" WHERE id = ?', [$sale0['orderId']]));
        $this->assertSame([404, 'CUSTOMER_NOT_FOUND'], $this->error($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 33.35]], 'cashRegisterId' => $this->register, 'customerId' => 999999])));
        $this->db->executeStatement('UPDATE entreprise SET pos_loyalty_enabled = true');
        $sale1 = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 33.35]], 'cashRegisterId' => $this->register, 'customerId' => $id]), 201);
        $this->assertSame([29, 29, $c['loyalty']['code']], [$sale1['customer']['loyalty']['earned'], $sale1['customer']['loyalty']['balance'], $sale1['customer']['loyalty']['code']], '29,00 net → 29 points');
        $gc = $this->json($this->request('POST', '/api/pos/gift-cards', ['amount' => 5000, 'paymentMethods' => [['type' => 2, 'amount' => 50]], 'cashRegisterId' => $this->register, 'customerId' => $id]), 201);
        $this->assertSame([$id, 29], [(int) $this->db->fetchOne('SELECT customer_id FROM pos_gift_card WHERE id = ?', [$gc['giftCard']['id']]), $this->json($this->request('GET', "/api/pos/customers/$id"))['loyalty']['points']], 'achat d\'une carte : rattaché, aucun point');
        $sale2 = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 4, 'amount' => 10, 'code' => $gc['giftCard']['code']], ['type' => 2, 'amount' => 23.35]], 'cashRegisterId' => $this->register, 'customerId' => $id]), 201);
        $this->assertSame([19, 48], [$sale2['customer']['loyalty']['earned'], $sale2['customer']['loyalty']['balance']], '(29,00 − 10,00 carte) → 19 points');

        // Rachat : remise de ticket calculée par le serveur, hors plafond caissier ; minimum, pas, solde, conflit avec une remise de ticket
        $need = $this->request('POST', "/api/pos/customers/$id/loyalty/adjust", ['points' => 552, 'reason' => 'test', 'cashRegisterId' => $this->register]);
        $this->assertSame([403, 'APPROVAL_REQUIRED', 'loyalty_adjust', ['customerId' => $id, 'points' => 552]], [$need->getStatusCode(), json_decode($need->getContent(), true)['error'], json_decode($need->getContent(), true)['action'], json_decode($need->getContent(), true)['context']], 'caissier : validation C3');
        $this->token = null;
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $adj = $this->json($this->request('POST', "/api/pos/customers/$id/loyalty/adjust", ['points' => 552, 'reason' => 'bonus de bienvenue', 'cashRegisterId' => $this->register]));
        $this->assertSame(600, $adj['customer']['loyalty']['points']);
        $this->loginAs(['ROLE_USER_POS']);
        $pay = fn (array $extra) => $this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'cashRegisterId' => $this->register, 'customerId' => $id] + $extra);
        $this->assertSame([422, 'LOYALTY_POINTS_INVALID'], $this->error($pay(['loyalty' => ['redeemPoints' => 50], 'paymentMethods' => [['type' => 2, 'amount' => 33.35]]])));
        $this->assertSame([409, 'LOYALTY_INSUFFICIENT'], $this->error($pay(['loyalty' => ['redeemPoints' => 10000], 'paymentMethods' => [['type' => 2, 'amount' => 33.35]]])));
        $this->assertSame([409, 'LOYALTY_TICKET_DISCOUNT_CONFLICT'], $this->error($pay(['loyalty' => ['redeemPoints' => 500], 'ticketDiscount' => ['type' => 'amount', 'value' => 100, 'reasonCode' => 'autre'], 'paymentMethods' => [['type' => 2, 'amount' => 33.35]]])));
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'ticketDiscount' => ['type' => 'amount', 'value' => 500, 'reasonText' => 'fidélité'], 'cashRegisterId' => $this->register]));
        $sale3 = $this->json($pay(['loyalty' => ['redeemPoints' => 500], 'paymentMethods' => [['type' => 2, 'amount' => $q['cashTotal'] / 100]]]), 201);
        $this->assertEquals([5.0, 500, 500, 24, 124], [$sale3['discounts']['ticket']['amount'], $sale3['customer']['loyalty']['redeemed'], $sale3['customer']['loyalty']['redeemedAmount'], $sale3['customer']['loyalty']['earned'], $sale3['customer']['loyalty']['balance']], '500 points = 5,00 ; points gagnés sur le net remisé (24,00)');
        $this->assertStringContainsString('fidélité', (string) $this->db->fetchOne('SELECT discount_reason_text FROM "order" WHERE id = ?', [$sale3['orderId']]));
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_approval WHERE requested_by_id = ? AND action = 'discount'", [$cashier->getId()]), 'rachat : aucune validation de remise demandée');

        // Retour : points repris au prorata ; reçu par courriel à l'adresse du client consentant ; révocation → adresse exigée
        $line = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale1['orderId']))['lines'][0]['orderItemId'];
        $ret = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale1['orderId'], 'lines' => [['orderItemId' => $line, 'quantity' => 1]], 'reasonCode' => 'autre', 'cashRegisterId' => $this->register]), 201);
        $this->assertSame($id, $ret['customerId']);
        $this->assertSame(124 - 29, $this->json($this->request('GET', "/api/pos/customers/$id"))['loyalty']['points'], '29 points repris');
        $mail = $this->json($this->request('POST', '/api/pos/receipts/email', ['barcode' => $sale3['barcode']]));
        $this->assertSame([true, 'm***@example.invalid'], [$mail['sent'], $mail['to']], 'adresse du client, jamais saisie');
        $this->json($this->request('PATCH', "/api/pos/customers/$id", ['consents' => ['receipts' => false]]));
        $this->assertSame([422, 'EMAIL_INVALID'], $this->error($this->request('POST', '/api/pos/receipts/email', ['barcode' => $sale3['barcode']])), 'consentement retiré : comportement F3 inchangé');

        // Historique et rapport X
        $h = $this->json($this->request('GET', "/api/pos/customers/$id/history"));
        $this->assertSame([4, 1, 1, 1], [$h['totals']['salesCount'], count(array_filter($h['sales'], fn ($x) => $x['kind'] === 'gift_card')), count(array_filter($h['sales'], fn ($x) => $x['kind'] === 'return')), count($h['giftCards'])]);
        $this->assertSame(['return', 'earn', 'redeem', 'adjust', 'earn', 'earn'], array_slice(array_column($h['loyalty'], 'kind'), 0, 6), 'mouvements du plus récent au plus ancien');
        $this->assertSame(0, $h['web']['count'], 'compte web lié : aucune commande en ligne');
        $x = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $this->assertSame([4, 29 + 19 + 24, ['points' => 500, 'amount' => 500, 'count' => 1], 29], [$x['customers']['salesWithCustomer']['count'], $x['customers']['loyalty']['earned'], $x['customers']['loyalty']['redeemed'], $x['customers']['loyalty']['returned']]);

        // Droits : fusion, export et effacement réservés au responsable ; effacement validé C3 ; anonymisation, ventes conservées
        $this->assertSame([403, 'FORBIDDEN'], $this->error($this->request('POST', "/api/pos/customers/{$other['id']}/merge", ['intoId' => $id])));
        $this->assertSame([403, 'FORBIDDEN'], $this->error($this->request('GET', "/api/pos/customers/$id/export")));
        $this->assertSame([403, 'FORBIDDEN'], $this->error($this->request('POST', "/api/pos/customers/{$other['id']}/erase")));
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $full = $this->json($this->request('GET', "/api/pos/customers/$id"));
        $this->assertSame(['+15145551234', 'marie.t@example.invalid', $web->getId()], [$full['phone'], $full['email'], $full['userId']], 'responsable : en clair, E.164');
        // Points déjà dépensés : solde ramené à 0, manque indiqué, retour jamais bloqué (solde 95 → 0, puis retour de la vente à 19 points)
        $this->json($this->request('POST', "/api/pos/customers/$id/loyalty/adjust", ['points' => -95, 'reason' => 'test du manque', 'cashRegisterId' => $this->register]));
        $line2 = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale2['orderId']))['lines'][0]['orderItemId'];
        $ret2 = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale2['orderId'], 'lines' => [['orderItemId' => $line2, 'quantity' => 1]], 'reasonCode' => 'autre', 'cashRegisterId' => $this->register]), 201);
        $this->assertSame(['returned' => 0, 'shortfall' => 19, 'balance' => 0], $ret2['loyalty'], '19 points dus, déjà dépensés : solde à 0, manque indiqué');
        $this->assertStringContainsString('"shortfall":19', (string) $this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'LOYALTY_RETURNED' ORDER BY id DESC LIMIT 1"));
        $this->assertSame(['returned' => 29, 'shortfall' => 0, 'balance' => 95], $ret['loyalty'], 'premier retour : repris en entier');
        $export = $this->json($this->request('GET', "/api/pos/customers/$id/export"));
        $this->assertSame(['marie.t@example.invalid', true], [$export['customer']['email'], count($export['consentHistory']) >= 4]);
        $merged = $this->json($this->request('POST', "/api/pos/customers/{$other['id']}/merge", ['intoId' => $id]));
        $this->assertSame($id, $merged['id']);
        $this->assertSame([409, 'CUSTOMER_MERGED'], $this->error($this->request('PATCH', "/api/pos/customers/{$other['id']}", ['firstName' => 'X'])));
        // Effacement : réservé au responsable (un responsable qui agit lui-même n'a pas besoin de validation C3, règle C3 ; l'action customer_erase existe pour les validations groupées)
        $erased = $this->json($this->request('POST', "/api/pos/customers/$id/erase", null, ['HTTP_IDEMPOTENCY_KEY' => self::uuid()]));
        $this->assertSame([true, 'Client effacé', null, null, 0, false, false], [$erased['erased'], $erased['displayName'], $erased['phone'], $erased['email'], $erased['loyalty']['points'], $erased['linkedAccount'], $erased['consents']['loyalty']['granted']]);
        $this->assertSame($id, (int) $this->db->fetchOne('SELECT customer_id FROM "order" WHERE id = ?', [$sale1['orderId']]), 'ventes conservées, rattachées à la fiche anonymisée');
        $this->assertSame([410, 'CUSTOMER_ERASED'], $this->error($this->request('PATCH', "/api/pos/customers/$id", ['firstName' => 'X'])));
        $this->assertSame(200, $this->request('GET', "/api/pos/customers/$id/history")->getStatusCode(), 'historique d\'une fiche effacée lisible (comptabilité)');

        // Hors ligne : client connu mis en file avec la vente ; fiche inconnue → customerUnknown ; aucune création
        $this->db->executeStatement('UPDATE entreprise SET pos_offline_enabled = true');
        $alice = $this->json($this->request('POST', '/api/pos/customers', ['firstName' => 'Alice', 'lastName' => 'Horsligne', 'phone' => '5145550001', 'consents' => ['loyalty' => true]]), 201);
        $pack = $this->json($this->request('GET', "/api/pos/offline-pack?cashRegisterId=$this->register"));
        $this->assertSame(['queued', true], [$pack['settings']['customers']['offline'], $pack['settings']['loyalty']['enabled']]);
        $seller = $this->user(['ROLE_USER_POS']);
        $r = PosOfflineCalculator::compute([['unitPrice' => 2900, 'quantity' => 1]], null, $pack['taxes'], $pack['site']['cashRoundingStep'], null);
        $mk = function (string $prov, array $extra) use ($seller, $items, $r) {
            $sale = ['idempotencyKey' => self::uuid(), 'provisionalNumber' => $prov, 'cashRegisterId' => $this->register, 'sellerId' => $seller->getId(), 'soldAt' => (new \DateTimeImmutable('-30 seconds'))->format(\DateTimeInterface::ATOM),
                'items' => $items, 'ticketDiscount' => null, 'payments' => [['type' => 2, 'amount' => $r['cashTotal']]], 'cashReceived' => $r['cashTotal'],
                'totals' => ['subtotal' => $r['subtotal'], 'taxes' => array_map(fn ($t) => ['label' => $t['label'], 'amount' => $t['amount']], $r['taxes']), 'total' => $r['total'], 'cashTotal' => $r['cashTotal'], 'cashRounding' => $r['cashRounding']]] + $extra;
            $sale['chain'] = ['seq' => (int) substr($prov, strrpos($prov, '-') + 1), 'prevHash' => '', 'hash' => PosOfflineSyncService::hashOf($sale, '')];

            return $sale;
        };
        $batch = ['packVersion' => $pack['packVersion'], 'deviceTime' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), 'clockOffsetSeconds' => 0];
        $sync = $this->json($this->request('POST', '/api/pos/offline-sales', $batch + ['sales' => [$mk("P-$this->terminalId-1", ['loyaltyCode' => $alice['loyalty']['code']]), $mk("P-$this->terminalId-2", ['customerId' => 999999])]]));
        $this->assertSame(['accepted', $alice['id'], 29, false], [$sync['results'][0]['status'], $sync['results'][0]['customer']['id'], $sync['results'][0]['customer']['loyaltyEarned'], $sync['results'][0]['customerUnknown']]);
        $this->assertSame(['accepted', null, true], [$sync['results'][1]['status'], $sync['results'][1]['customer'], $sync['results'][1]['customerUnknown']]);
        $this->db->executeStatement('UPDATE entreprise SET pos_offline_enabled = false, pos_loyalty_enabled = false, pos_gift_card_enabled = false');
    }

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
        $key = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette clients', 'sendEmail' => false]), 201)['key'];
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
        $email = 'client-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Client')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
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
