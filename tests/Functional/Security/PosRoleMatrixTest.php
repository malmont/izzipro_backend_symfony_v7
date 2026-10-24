<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\PosAuditService\PosPinService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeTerminalGateway;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot S2 (30/10/2026) : « caissier refusé » pour chaque action réservée au responsable, rattachement du terminal à un poste (opérations
 * de caisse seulement), limite d'essais sur les codes de cartes-cadeaux, réponse uniforme pour un code refusé.
 */
class PosRoleMatrixTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
    private int $terminalId = 0;
    private int $register;
    private int $cap;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeTerminalGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-roles-caisse@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_header_required = false,
            pos_pay_cash = true, pos_pay_declared_card = true, pos_pay_paypal = true, pos_pay_multi = true, pos_return_max_days = NULL, pos_return_without_receipt = false, pos_discount_enabled = true, pos_discount_cashier_max_amount = 2000, pos_discount_cashier_max_percent_bp = 1000,
            pos_custom_item_enabled = false, pos_price_override_enabled = false, pos_offline_enabled = false, pos_gift_card_enabled = true, pos_store_credit_enabled = false, pos_customers_enabled = true, pos_loyalty_enabled = true, pos_gift_card_code_attempts = 10, data_retention_years = NULL, retention_write_mode = 'observe'");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement("UPDATE cash_register SET is_active = true, terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = NULL");
        $this->db->executeStatement('UPDATE product SET is_pos = true');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = $this->variant('DEMO-CASQUETTE', 2900);
    }

    public function testACashierIsRefusedEveryManagerActionAndBoundToItsRegister(): void
    {
        $manager = $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        static::getContainer()->get(PosPinService::class)->set($manager, '9876');
        $this->terminal = $this->posTerminal();
        $other = $this->json($this->request('POST', '/api/cash-registers', ['name' => 'Poste B ' . bin2hex(random_bytes(2))]), 201)['id'];
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $other, 'cashFund' => 5000]);
        $saleOnOther = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 33.35]], 'cashRegisterId' => $other]), 201);
        $card = $this->json($this->request('POST', '/api/pos/gift-cards', ['amount' => 2500, 'paymentMethods' => [['type' => 2, 'amount' => 25]], 'cashRegisterId' => $this->register]), 201)['giftCard'];
        $customer = $this->json($this->request('POST', '/api/pos/customers', ['firstName' => 'Rôle', 'lastName' => 'Test', 'phone' => '5145550099', 'consents' => ['loyalty' => true]]), 201);
        $customer2 = $this->json($this->request('POST', '/api/pos/customers', ['firstName' => 'Rôle', 'lastName' => 'Doublon', 'phone' => '5145550098']), 201);

        $cashier = $this->loginAs(['ROLE_USER_POS']);
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 33.35]], 'cashRegisterId' => $this->register]), 201);
        $refused = fn (string $method, string $path, ?array $body, string $error, int $status = 403, string $why = '') => $this->assertSame([$status, $error], $this->error($this->request($method, $path, $body)), "caissier refusé : $method $path $why");

        // Actions réservées au responsable (ou validation C3 quand le site l'exige)
        $this->db->executeStatement('UPDATE entreprise SET pos_approval_close_without_count = true, pos_approval_refund = 0, pos_approval_drawer_open = true');
        $refused('POST', '/api/caisse/close', ['cashRegisterId' => $this->register], 'APPROVAL_REQUIRED', 403, 'clôture sans comptage');
        $refused('POST', '/api/order/cancel/' . $sale['orderId'], ['paymentMethod' => 2, 'cashRegisterId' => $this->register], 'APPROVAL_REQUIRED', 403, 'annulation');
        $refused('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'test'], 'APPROVAL_REQUIRED', 403, 'tiroir sans vente');
        $this->db->executeStatement('UPDATE entreprise SET pos_approval_close_without_count = false, pos_approval_refund = NULL, pos_approval_drawer_open = false');
        // remboursement d'un paiement terminal par un caissier : validation C3 action refund, prouvé par PosTerminalApiTest (C4)
        $big = [['productVariantId' => $this->cap, 'quantity' => 1, 'discounts' => [['type' => 'percent', 'value' => 30, 'reasonCode' => 'autre']]]];
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => $big, 'cashRegisterId' => $this->register]));
        $refused('POST', '/api/order/create-multi-payment', ['items' => $big, 'paymentMethods' => [['type' => 2, 'amount' => $q['cashTotal'] / 100]], 'cashRegisterId' => $this->register], 'APPROVAL_REQUIRED', 403, 'remise au-delà du plafond');
        // Routes partagées avec le web (audit S2.7) : notes de frais (administrateur), étiquettes (sa commande), commande web d'un caissier (vente web seulement)
        $noteId = (int) $this->db->fetchOne("INSERT INTO note_de_frais (id, name, montant, date) VALUES (nextval('note_de_frais_id_seq'), 'Rôle test', 1, CURRENT_DATE) RETURNING id");
        $this->assertSame([403, 'FORBIDDEN'], $this->error($this->request('PUT', "/api/notes-de-frais/$noteId", ['description' => 'x'])), 'note de frais d\'autrui : refusée');
        $this->assertSame([403, 'FORBIDDEN'], $this->error($this->request('DELETE', "/api/notes-de-frais/$noteId")), 'note de frais d\'autrui : refusée');
        $collectionId = (int) $this->db->fetchOne("INSERT INTO collections (id, user_collections_id, budget_collection, start_date_collection, end_date_collection, del, nom_collection) VALUES (nextval('collections_id_seq'), ?, 0, CURRENT_DATE, CURRENT_DATE, false, 'Rôle test') RETURNING id", [$cashier->getId()]);
        $this->db->executeStatement('UPDATE note_de_frais SET collection_id = ? WHERE id = ?', [$collectionId, $noteId]);
        $typeId = (int) $this->db->fetchOne("INSERT INTO type_note_de_frais (id, name) VALUES (nextval('type_note_de_frais_id_seq'), 'Rôle test') RETURNING id");
        $this->assertSame(200, $this->request('PUT', "/api/notes-de-frais/$noteId", ['description' => 'la mienne', 'montant' => 2, 'date' => date('Y-m-d'), 'typeNoteDeFraisId' => $typeId])->getStatusCode(), 'sa propre note (collection à lui) : modifiable');
        $this->assertSame(204, $this->request('DELETE', "/api/notes-de-frais/$noteId")->getStatusCode(), 'sa propre note : supprimable');
        $this->db->executeStatement('DELETE FROM collections WHERE id = ?', [$collectionId]);
        $this->db->executeStatement('DELETE FROM type_note_de_frais WHERE id = ?', [$typeId]);
        $refused('POST', '/api/shipping/buy', ['orderId' => $card['issuedOrderId'], 'cartItems' => []], 'FORBIDDEN', 403, 'étiquette pour une commande qui n\'est pas la sienne (vente du responsable)');
        $this->assertSame([404, 'ORDER_NOT_FOUND'], $this->error($this->request('POST', '/api/shipping/buy', ['orderId' => 999999999, 'cartItems' => []])));
        $refused('POST', '/api/order/create', ['addressId' => 1, 'items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'orderSource' => 2], 'POS_ROUTE_REQUIRED', 403, 'vente de caisse par la route web');
        $refused('POST', '/api/order/create', ['addressId' => 1, 'items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'typeOrder' => 2], 'POS_ROUTE_REQUIRED', 403, 'retour par la route web');
        $today = date('Y-m-d');
        $refused('GET', "/api/pos/exports/accounting?from=$today&to=$today", null, 'FORBIDDEN', 403, 'export comptable');
        $refused('GET', "/api/pos/exports/accounting/summary.csv?from=$today&to=$today", null, 'FORBIDDEN');
        $refused('GET', '/api/pos/archive/' . date('Y'), null, 'FORBIDDEN', 403, 'archive');
        $stats = $this->json($this->request('GET', "/api/pos/statistics/cashiers?from=$today&to=$today&cashierId=" . $manager->getId()));
        $this->assertSame(['self', $cashier->getId()], [$stats['period']['scope'], $stats['period']['cashierId']], 'statistiques des autres : ramenées à soi-même');
        $refused('POST', "/api/pos/customers/{$customer2['id']}/merge", ['intoId' => $customer['id']], 'FORBIDDEN', 403, 'fusion');
        $refused('POST', "/api/pos/customers/{$customer['id']}/erase", null, 'FORBIDDEN', 403, 'effacement');
        $refused('GET', "/api/pos/customers/{$customer['id']}/export", null, 'FORBIDDEN', 403, 'export client');
        $refused('POST', "/api/pos/customers/{$customer['id']}/loyalty/adjust", ['points' => 100, 'reason' => 'test', 'cashRegisterId' => $this->register], 'APPROVAL_REQUIRED', 403, 'ajustement de points');
        $this->assertSame(403, $this->request('POST', '/api/pos/receipts/revoke-link', ['barcode' => $sale['barcode']])->getStatusCode(), 'révocation de lien : responsable (route et service)');
        $refused('POST', '/api/pos/gift-cards/' . $card['code'] . '/cancel', ['cashRegisterId' => $this->register], 'APPROVAL_REQUIRED', 403, 'annulation de carte');
        $refused('POST', '/api/pos/gift-cards/' . $card['code'] . '/adjust', ['amount' => -100, 'reason' => 'test', 'cashRegisterId' => $this->register], 'APPROVAL_REQUIRED', 403, 'ajustement de carte');
        $refused('POST', '/api/pos/gift-cards/replace', ['orderId' => $card['issuedOrderId'], 'last4' => $card['last4'], 'cashRegisterId' => $this->register], 'APPROVAL_REQUIRED', 403, 'remplacement de carte');
        $this->assertSame(403, $this->request('POST', '/api/cash-registers', ['name' => 'X'])->getStatusCode(), 'création de poste : responsable');
        $this->assertSame(403, $this->request('PUT', "/api/cash-registers/$this->register", ['name' => 'X'])->getStatusCode(), 'réglage de poste : responsable');
        $this->assertSame(403, $this->request('GET', '/api/app-keys')->getStatusCode(), 'clés d\'application : administrateur');
        $this->assertSame(403, $this->request('POST', '/api/pos/terminal/payments/1/simulate', [])->getStatusCode(), 'carte simulée : responsable');

        // Codes devinables : réponse uniforme (inconnu, annulé, remplacé → 404), limite d'essais par utilisateur et par terminal, en consultation ET en paiement
        $this->db->executeStatement('UPDATE entreprise SET pos_gift_card_code_attempts = 3');
        foreach (['GC-ZZZZ-ZZZZ-ZZZ2', 'GC-ZZZZ-ZZZZ-ZZZ3', 'GC-ZZZZ-ZZZZ-ZZZ4'] as $code) {
            $this->assertSame([404, 'GIFT_CARD_NOT_FOUND'], $this->error($this->request('GET', "/api/pos/gift-cards/$code")));
        }
        $limited = $this->request('GET', '/api/pos/gift-cards/GC-ZZZZ-ZZZZ-ZZZ5');
        $this->assertSame([429, 'GIFT_CARD_LOOKUP_LIMIT'], $this->error($limited), '4e code refusé en 10 minutes');
        $this->assertSame(600, json_decode($limited->getContent(), true)['retryAfterSeconds']);
        $this->assertSame([429, 'GIFT_CARD_LOOKUP_LIMIT'], $this->error($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'paymentMethods' => [['type' => 4, 'amount' => 33.34, 'code' => $card['code']]], 'cashRegisterId' => $this->register])), 'même limite en paiement, même pour une carte valide tant que la limite court');
        $this->assertSame([429, 'GIFT_CARD_LOOKUP_LIMIT'], $this->error($this->request('GET', '/api/pos/gift-cards/' . $card['code'])), 'consultation bloquée aussi');
        $this->assertGreaterThanOrEqual(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'GIFT_CARD_LOOKUP' AND payload LIKE '%\"result\":\"code_attempts_limited\"%'"), 'journalisé');
        $this->db->executeStatement('UPDATE entreprise SET pos_gift_card_code_attempts = 0');
        $this->assertSame(200, $this->request('GET', '/api/pos/gift-cards/' . $card['code'])->getStatusCode(), '0 = sans limite');
        $this->db->executeStatement('UPDATE entreprise SET pos_gift_card_code_attempts = 10');

        // Rattachement du terminal au poste principal : le caissier n'opère que sur ce poste ; retours et réimpressions : toute vente du site ; responsable : tous les postes
        $params = $this->db->getParams();
        unset($params['url']);
        $master = DriverManager::getConnection(['dbname' => MV_TEST_MASTER_DB] + $params);
        $master->executeStatement('UPDATE tenant_app_keys SET cash_register_id = ? WHERE id = ?', [$this->register, $this->terminalId]);
        try {
            $refused('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 33.35]], 'cashRegisterId' => $other], 'CASH_REGISTER_FORBIDDEN', 403, 'vente sur un autre poste');
            $refused('POST', '/api/caisse/deposit', ['amount' => 100, 'cashRegisterId' => $other], 'CASH_REGISTER_FORBIDDEN', 403, 'dépôt sur un autre poste');
            $refused('POST', '/api/caisse/close', ['cashRegisterId' => $other, 'countedCash' => 0], 'CASH_REGISTER_FORBIDDEN', 403, 'clôture d\'un autre poste');
            $this->assertSame(403, $this->request('GET', "/api/pos/terminal?cashRegisterId=$other")->getStatusCode(), 'terminal d\'un autre poste');
            $implicit = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 33.35]]]), 201);
            $this->assertSame($this->register, $implicit['cashRegister']['id'], 'sans cashRegisterId : le poste du terminal');
            $look = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $saleOnOther['orderId']));
            $ret = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $saleOnOther['orderId'], 'lines' => [['orderItemId' => $look['lines'][0]['orderItemId'], 'quantity' => 1]], 'reasonCode' => 'autre']), 201);
            $this->assertSame($this->register, $ret['cashRegister']['id'], 'retour d\'une vente d\'un autre poste : accepté, sur le poste du caissier');
            $this->assertSame(200, $this->request('GET', '/api/pos/receipts?barcode=' . $saleOnOther['barcode'])->getStatusCode(), 'réimpression d\'un ticket d\'un autre poste : acceptée');
            $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
            $this->assertSame(201, $this->request('POST', '/api/caisse/deposit', ['amount' => 100, 'cashRegisterId' => $other])->getStatusCode(), 'responsable : tous les postes');
        } finally {
            $master->executeStatement('UPDATE tenant_app_keys SET cash_register_id = NULL WHERE id = ?', [$this->terminalId]);
        }
        $this->db->executeStatement('UPDATE entreprise SET pos_gift_card_enabled = false, pos_loyalty_enabled = false');
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
        $key = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette rôles', 'sendEmail' => false]), 201)['key'];
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
        $email = 'roles-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Rôle')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
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
