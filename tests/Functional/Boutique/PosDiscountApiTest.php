<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\PosAuditService\PosPinService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeTerminalGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot F1 (24/10/2026) : remises de caisse. Devis POST /api/pos/quote (lignes, ticket au prorata, taxes sur le net, cashTotal),
 * vente remisée (ticket, journal, rapports), plafonds par rôle et validation C3 action discount, réglages, retours F2 au net,
 * le web ignore toute remise.
 */
class PosDiscountApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
    private ?string $approval = null;
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
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-remises@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_header_required = false,
            pos_pay_cash = true, pos_pay_declared_card = true, pos_pay_paypal = true, pos_pay_multi = true, pos_declared_card_blocked_with_terminal = false, pos_return_max_days = NULL, pos_return_cash_for_card_sale = true, pos_approval_return_mode = 'refund',
            pos_discount_enabled = true, pos_discount_ticket_enabled = true, pos_discount_cashier_max_percent_bp = 1000, pos_discount_cashier_max_amount = 2000, pos_discount_manager_max_percent_bp = 5000, pos_discount_manager_max_amount = 20000, pos_discount_reason_required = true, pos_discount_reasons = NULL");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement("UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = NULL");
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = $this->variant('DEMO-CASQUETTE', 2900);
        $this->tshirt = $this->variant('DEMO-TSHIRT', 1900);
    }

    public function testQuoteAppliesLineAndTicketDiscountsWithTaxesOnNetAndWebIgnoresThem(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $s = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertEquals(['enabled' => true, 'ticketEnabled' => true, 'cashier' => ['maxPercent' => 10, 'maxAmount' => 2000], 'manager' => ['maxPercent' => 50, 'maxAmount' => 20000], 'reasonRequired' => true], array_diff_key($s['discounts'], ['reasons' => 1]));
        $this->assertSame(['article_abime', 'fidelite', 'prix_affiche', 'geste_commercial', 'autre'], array_column($s['discounts']['reasons'], 'code'));
        $this->assertEquals(['required' => true, 'threshold' => 2000, 'maxPercent' => 10], $s['approvals']['discount']);

        // Sans remise : même résultat que cart/quote
        $items = [['productVariantId' => $this->cap, 'quantity' => 2], ['productVariantId' => $this->tshirt, 'quantity' => 1]];
        $web = $this->json($this->request('POST', '/api/cart/quote', ['items' => $items, 'cashRegisterId' => $this->register]));
        $pos = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register]));
        $this->assertSame([$web['total'], $web['subtotal'], $web['taxes']], [$pos['total'], $pos['subtotal'], $pos['taxes']]);
        $this->assertSame(['lines' => 0, 'ticket' => null, 'total' => 0, 'gross' => 7700], $pos['discounts']);
        $this->assertSame(0, $pos['cashTotal'] % 5);

        // Remise de ligne 10 % sur les casquettes + 2,00 sur le t-shirt, puis 5 % sur le ticket, taxes sur le net
        $discounted = [['productVariantId' => $this->cap, 'quantity' => 2, 'discounts' => [['type' => 'percent', 'value' => 10, 'reasonCode' => 'fidelite']]], ['productVariantId' => $this->tshirt, 'quantity' => 1, 'discounts' => [['type' => 'amount', 'value' => 200, 'reasonCode' => 'article_abime']]]];
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => $discounted, 'ticketDiscount' => ['type' => 'percent', 'value' => 5, 'reasonCode' => 'geste_commercial'], 'cashRegisterId' => $this->register]));
        // casquettes : 5800 − 580 = 5220 ; t-shirt : 1900 − 200 = 1700 ; ticket 5 % de 6920 = 346 répartie 261 + 85 (dernière ligne porte l'écart)
        $this->assertSame([5800, 580 + 261, 5220 - 261], [$q['lines'][0]['gross'], $q['lines'][0]['discountAmount'], $q['lines'][0]['total']]);
        $this->assertSame([1900, 200 + 85, 1700 - 85], [$q['lines'][1]['gross'], $q['lines'][1]['discountAmount'], $q['lines'][1]['total']]);
        $this->assertSame(['line', 'ticket'], array_column($q['lines'][0]['discounts'], 'scope'));
        $this->assertSame(['lines' => 780, 'total' => 1126, 'gross' => 7700], array_diff_key($q['discounts'], ['ticket' => 1]));
        $this->assertSame(346, $q['discounts']['ticket']['amount']);
        $net = 7700 - 1126;
        $this->assertSame($net, $q['subtotal']);
        // Taxes calculées ligne par ligne sur le net (moteur de taxes) : à 1 cent près du taux appliqué au net total
        $this->assertEqualsWithDelta((int) round($net * 0.05), $q['taxes'][0]['amount'], 1, 'TPS sur le net');
        $this->assertEqualsWithDelta((int) round($net * 0.09975), $q['taxes'][1]['amount'], 1, 'TVQ sur le net');
        $this->assertEqualsWithDelta($net + (int) array_sum(array_column($q['taxes'], 'amount')), $q['total'], 1, 'total = net + taxes (le moteur arrondit son total à part, comme pour le web)');
        $this->assertLessThan($web['total'] - 1126, $q['total'], 'moins que le brut moins la remise : les taxes ont baissé aussi');
        $this->assertSame(7700, $q['grossSubtotal']);

        // Le web ignore les remises : cart/quote et create-intent ne les honorent jamais
        $webIgnored = $this->json($this->request('POST', '/api/cart/quote', ['items' => $discounted, 'ticketDiscount' => ['type' => 'percent', 'value' => 50], 'cashRegisterId' => $this->register]));
        $this->assertSame([$web['total'], false], [$webIgnored['total'], array_key_exists('discounts', $webIgnored)], 'web : aucune remise, aucune clé ajoutée');

        // Erreurs
        $bad = fn (array $body) => json_decode($this->request('POST', '/api/pos/quote', $body + ['cashRegisterId' => $this->register])->getContent(), true)['error'];
        $this->assertSame('DISCOUNT_INVALID', $bad(['items' => [['productVariantId' => $this->cap, 'quantity' => 1, 'discounts' => [['type' => 'percent', 'value' => 150, 'reasonCode' => 'autre']]]]]));
        $this->assertSame('DISCOUNT_INVALID', $bad(['items' => [['productVariantId' => $this->cap, 'quantity' => 1, 'discounts' => [['type' => 'bonus', 'value' => 1]]]]]));
        $this->assertSame('DISCOUNT_REASON_REQUIRED', $bad(['items' => [['productVariantId' => $this->cap, 'quantity' => 1, 'discounts' => [['type' => 'percent', 'value' => 5]]]]]));
        $this->assertSame('DISCOUNT_REASON_INVALID', $bad(['items' => [['productVariantId' => $this->cap, 'quantity' => 1, 'discounts' => [['type' => 'percent', 'value' => 5, 'reasonCode' => 'inconnu']]]]]));
        $this->db->executeStatement('UPDATE entreprise SET pos_discount_ticket_enabled = false');
        $this->assertSame('DISCOUNT_NOT_ALLOWED', $bad(['items' => $items, 'ticketDiscount' => ['type' => 'amount', 'value' => 100, 'reasonCode' => 'autre']]));
        $this->db->executeStatement('UPDATE entreprise SET pos_discount_enabled = false');
        $this->assertSame('DISCOUNT_NOT_ALLOWED', $bad(['items' => $discounted]));
        $this->assertSame(200, $this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register])->getStatusCode(), 'sans remise : devis normal même désactivé');
    }

    public function testDiscountedSaleReceiptJournalReportsReturnsAndRoleLimits(): void
    {
        $manager = $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        static::getContainer()->get(PosPinService::class)->set($manager, '1122');
        $this->terminal = $this->posTerminal();
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $cashier = $this->loginAs(['ROLE_USER_POS']);
        $items = [['productVariantId' => $this->cap, 'quantity' => 2, 'discounts' => [['type' => 'percent', 'value' => 10, 'reasonCode' => 'fidelite']]]];
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register]));
        $this->assertSame([580, 5220], [$q['discounts']['total'], $q['subtotal']]);

        // Caissier dans son plafond (10 %, 5,80 ≤ 20,00) : vente acceptée, paiement = net
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => $q['cashTotal'] / 100]], 'cashRegisterId' => $this->register]), 201);
        $this->assertEquals([5.8, 58, 52.2], [$sale['discounts']['total'], $sale['grossSubTotal'], $sale['subTotal']], 'ticket : brut, remises, net');
        $this->assertEquals([['productVariantId' => $this->cap, 'quantity' => 2, 'gross' => 58, 'net' => 52.2, 'amount' => 5.8]], array_map(fn ($l) => array_diff_key($l, ['discounts' => 1]), $sale['discounts']['lines']));
        $this->assertSame(['percent', 10, 'line', 'fidelite'], [$sale['discounts']['lines'][0]['discounts'][0]['type'], $sale['discounts']['lines'][0]['discounts'][0]['value'], $sale['discounts']['lines'][0]['discounts'][0]['scope'], $sale['discounts']['lines'][0]['discounts'][0]['reason']['code']]);
        $this->assertNull($sale['discounts']['ticket']);
        $row = $this->db->fetchAssociative('SELECT unit_price, total_price, discount_amount, discounts FROM order_items WHERE order_associated_id = ?', [$sale['orderId']]);
        $this->assertSame([2900.0, 5220.0, 580], [(float) $row['unit_price'], (float) $row['total_price'], (int) $row['discount_amount']], 'ligne : brut unitaire, net total');
        $this->assertSame((float) $q['total'], (float) $this->db->fetchOne('SELECT total_amount FROM "order" WHERE id = ?', [$sale['orderId']]));
        $journal = $this->db->fetchAssociative("SELECT type, payload FROM pos_audit_log WHERE type = 'DISCOUNT_APPLIED' ORDER BY id DESC LIMIT 1");
        $payload = json_decode($journal['payload'], true);
        $this->assertSame([580, 5800], [($payload['data'] ?? $payload)['total'], ($payload['data'] ?? $payload)['gross']]);
        $x = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $this->assertSame(['count' => 1, 'total' => 580], $x['discounts']);

        // Paiements insuffisants (prix brut envoyé) : refus
        $short = $this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 40.00]], 'cashRegisterId' => $this->register]);
        $this->assertSame('PAYMENT_TOTAL_MISMATCH', json_decode($short->getContent(), true)['error']);

        // Retour F2 d'une casquette remisée : net payé (2610) + taxes sur le net ; unitGross = 2900
        $look = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale['orderId']))['lines'][0];
        $this->assertSame([2610, 2900, 580], [$look['unitPrice'], $look['unitGross'], $look['discountAmount']]);
        $ret = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $look['orderItemId'], 'quantity' => 1]], 'reasonCode' => 'autre', 'cashRegisterId' => $this->register]), 201);
        $this->assertSame($look['unitRefundable'], $ret['amount'], 'remboursé au net payé');
        $this->assertSame(2610, $ret['subTotal']);

        // Caissier au-delà de son plafond (20 %) : validation du responsable, contexte {amount, percent, scope}
        $big = [['productVariantId' => $this->cap, 'quantity' => 1, 'discounts' => [['type' => 'percent', 'value' => 20, 'reasonCode' => 'geste_commercial']]]];
        $qb = $this->json($this->request('POST', '/api/pos/quote', ['items' => $big, 'cashRegisterId' => $this->register]));
        $need = $this->request('POST', '/api/order/create-multi-payment', ['items' => $big, 'paymentMethods' => [['type' => 2, 'amount' => $qb['cashTotal'] / 100]], 'cashRegisterId' => $this->register]);
        $body = json_decode($need->getContent(), true);
        $this->assertSame([403, 'APPROVAL_REQUIRED', 'discount', ['amount' => 580, 'percent' => 2000, 'scope' => 'line']], [$need->getStatusCode(), $body['error'], $body['action'], $body['context']]);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order" WHERE total_amount = ?', [(float) $qb['total']]), 'rien créé');
        $this->approval = $this->json($this->request('POST', '/api/pos/approvals', ['action' => 'discount', 'managerId' => $manager->getId(), 'pin' => '1122', 'context' => $body['context']]), 201)['approvalToken'];
        $ok = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $big, 'paymentMethods' => [['type' => 2, 'amount' => $qb['cashTotal'] / 100]], 'cashRegisterId' => $this->register]), 201);
        $this->approval = null;
        $journal = json_decode($this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'DISCOUNT_APPLIED' ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame($manager->getId(), (($journal['data'] ?? $journal)['approvedBy'] ?? [])['id'] ?? null, 'journal : approvedBy');

        // Au-delà du plafond responsable (60 %) : refusé même validé ; responsable lui-même : 40 % ok, 60 % refusé ; admin : sans plafond
        $huge = [['productVariantId' => $this->cap, 'quantity' => 1, 'discounts' => [['type' => 'percent', 'value' => 60, 'reasonCode' => 'autre']]]];
        $qh = $this->json($this->request('POST', '/api/pos/quote', ['items' => $huge, 'cashRegisterId' => $this->register]));
        $this->approval = $this->json($this->request('POST', '/api/pos/approvals', ['action' => 'discount', 'managerId' => $manager->getId(), 'pin' => '1122', 'context' => ['amount' => 1740, 'percent' => 6000, 'scope' => 'line']]), 201)['approvalToken'];
        $limit = $this->request('POST', '/api/order/create-multi-payment', ['items' => $huge, 'paymentMethods' => [['type' => 2, 'amount' => $qh['cashTotal'] / 100]], 'cashRegisterId' => $this->register]);
        $this->approval = null;
        $this->assertSame([403, 'DISCOUNT_EXCEEDS_LIMIT'], [$limit->getStatusCode(), json_decode($limit->getContent(), true)['error']]);
        $this->token = null;
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $forty = [['productVariantId' => $this->cap, 'quantity' => 1, 'discounts' => [['type' => 'percent', 'value' => 40, 'reasonCode' => 'autre']]]];
        $qf = $this->json($this->request('POST', '/api/pos/quote', ['items' => $forty, 'cashRegisterId' => $this->register]));
        $this->assertSame(201, $this->request('POST', '/api/order/create-multi-payment', ['items' => $forty, 'paymentMethods' => [['type' => 2, 'amount' => $qf['cashTotal'] / 100]], 'cashRegisterId' => $this->register])->getStatusCode(), 'responsable : 40 % sans validation');
        $this->assertSame('DISCOUNT_EXCEEDS_LIMIT', json_decode($this->request('POST', '/api/order/create-multi-payment', ['items' => $huge, 'paymentMethods' => [['type' => 2, 'amount' => $qh['cashTotal'] / 100]], 'cashRegisterId' => $this->register])->getContent(), true)['error']);
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $this->assertSame(201, $this->request('POST', '/api/order/create-multi-payment', ['items' => $huge, 'paymentMethods' => [['type' => 2, 'amount' => $qh['cashTotal'] / 100]], 'cashRegisterId' => $this->register])->getStatusCode(), 'administrateur : sans plafond');

        // Échange avec remise de ticket sur la nouvelle vente
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $base = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->tshirt, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 21.85]], 'cashRegisterId' => $this->register]), 201);
        $line = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $base['orderId']))['lines'][0]['orderItemId'];
        $saleItems = [['productVariantId' => $this->cap, 'quantity' => 1]];
        $qe = $this->json($this->request('POST', '/api/pos/quote', ['items' => $saleItems, 'ticketDiscount' => ['type' => 'amount', 'value' => 500, 'reasonCode' => 'fidelite'], 'cashRegisterId' => $this->register]));
        $this->assertSame([2400, 500], [$qe['subtotal'], $qe['discounts']['ticket']['amount']]);
        $due = $qe['total'] - 2185; // casquette remisée (27,60 taxes comprises) − t-shirt rendu
        $ex = $this->json($this->request('POST', '/api/pos/exchanges', ['return' => ['orderId' => $base['orderId'], 'lines' => [['orderItemId' => $line, 'quantity' => 1]], 'reasonCode' => 'taille_ou_modele'],
            'sale' => ['items' => $saleItems, 'ticketDiscount' => ['type' => 'amount', 'value' => 500, 'reasonCode' => 'fidelite'], 'paymentMethods' => [['type' => 2, 'amount' => round($due / 5) * 5 / 100]], 'cashRegisterId' => $this->register]]), 201);
        $this->assertEquals([5, 'fidelite', 21.85], [$ex['sale']['discounts']['ticket']['amount'], $ex['sale']['discounts']['ticket']['reason']['code'], $ex['sale']['creditApplied']]);
        $this->assertSame(500, (int) $this->db->fetchOne('SELECT discount_amount FROM "order" WHERE id = ?', [$ex['sale']['orderId']]));
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
        $key = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette remises', 'sendEmail' => false]), 201)['key'];
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
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
            'HTTP_X_POS_TERMINAL' => $this->terminal, 'HTTP_X_POS_APPROVAL' => $this->approval,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): User
    {
        $email = 'remise-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Remise')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
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
