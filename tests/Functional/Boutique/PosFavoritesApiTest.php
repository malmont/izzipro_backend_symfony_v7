<?php

namespace App\Tests\Functional\Boutique;

use App\Controller\Admin\PosFavoriteCrudController;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeTerminalGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Grille de favoris de la caisse (F10) : épinglés (site, ou poste qui remplace le site) puis plus vendus en caisse sur N jours, nets des
 * retours, hors ventes annulées ; réglages par site ; épinglage réservé au responsable (API et EasyAdmin, journal) ; ETag et 304 ; pack
 * hors ligne (clé ajoutée seulement si activé). Base de test partagée : classements comparés entre produits de l'essai (grandes quantités).
 */
class PosFavoritesApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
    private ?string $approval = null;
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
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-fidelite@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_header_required = false,
            pos_pay_cash = true, pos_pay_declared_card = true, pos_pay_paypal = true, pos_pay_multi = true, pos_declared_card_blocked_with_terminal = false, pos_return_max_days = NULL, pos_return_without_receipt = false, pos_return_cash_for_card_sale = true, pos_approval_return_mode = 'refund',
            pos_discount_enabled = true, pos_discount_ticket_enabled = true, pos_discount_reason_required = true, pos_discount_cashier_max_amount = 2000, pos_discount_cashier_max_percent_bp = 1000, pos_custom_item_enabled = false, pos_price_override_enabled = false, pos_offline_enabled = false,
            pos_gift_card_enabled = true, pos_store_credit_enabled = false, pos_receipt_email_enabled = true,
            pos_customers_enabled = true, pos_customer_link_enabled = true, pos_loyalty_enabled = true, pos_loyalty_points_per_dollar = 1, pos_loyalty_value_per100 = 100, pos_loyalty_min_redeem = 500, pos_loyalty_redeem_step = 100, pos_loyalty_expiry_months = NULL, pos_consent_text_version = '2026-10', pos_customer_search_per_minute = 30");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement("UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = NULL");
        $this->db->executeStatement('UPDATE product SET is_pos = true');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = $this->variant('DEMO-CASQUETTE', 2900);
        // Favoris (F10) : désactivés, aucun épinglé ; produits de l'essai vendables, stock large, sans prix promotionnel
        $this->db->executeStatement("UPDATE entreprise SET pos_favorites_enabled = false, pos_favorites_mode = 'both', pos_favorites_days = 30, pos_favorites_grid_size = 24, pos_offline_enabled = false");
        $this->db->executeStatement('DELETE FROM pos_favorite');
        $this->db->executeStatement("UPDATE product SET special_price = NULL, is_pos = true, sale_enabled = true, rental_enabled = false WHERE code IN ('DEMO-SAC', 'DEMO-GOURDE', 'DEMO-REMORQUE', 'DEMO-VESTE', 'DEMO-CAFE', 'DEMO-TSHIRT')");
        $this->db->executeStatement("UPDATE product_variant SET stock_quantity = 5000, price = NULL WHERE product_id IN (SELECT id FROM product WHERE code IN ('DEMO-SAC', 'DEMO-GOURDE', 'DEMO-REMORQUE', 'DEMO-VESTE', 'DEMO-CAFE', 'DEMO-TSHIRT'))");
    }

    public function testDisabledByDefaultThenPinsFirstAndNetBestSellersOfTheWindow(): void
    {
        $manager = $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 20000]);
        $p = fn (string $code) => (int) $this->db->fetchOne('SELECT id FROM product WHERE code = ?', [$code]);
        $v = fn (string $code, int $n = 0) => (int) $this->db->fetchOne('SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = ? ORDER BY v.id LIMIT 1 OFFSET ' . $n, [$code]);
        $grid = fn (string $query = '') => $this->json($this->request('GET', '/api/pos/favorites' . $query));
        $ids = fn (array $g) => array_column($g['items'], 'productId');
        $at = fn (array $g, string $code) => array_search($p($code), $ids($g), true);

        // Désactivés (défaut) : 200 {enabled: false, items: []} ; réglages exposés ; pack hors ligne sans clé favorites
        $this->assertSame(['enabled' => false, 'items' => []], $grid());
        $this->assertSame(['enabled' => false, 'mode' => 'both', 'days' => 30, 'gridSize' => 24], $this->json($this->request('GET', '/api/pos/settings'))['favorites']);
        $packOff = $this->json($this->request('GET', "/api/pos/offline-pack?cashRegisterId=$this->register"));
        $this->assertArrayNotHasKey('favorites', $packOff);

        // Ventes : sac 90 ; gourde 70 dont 10 retournées (net 60) ; veste 65 ; café 200 annulé ; remorque 150 il y a 40 jours + 30 récents
        $this->sell([['productVariantId' => $v('DEMO-SAC'), 'quantity' => 90]]);
        $gourde = $this->sell([['productVariantId' => $v('DEMO-GOURDE'), 'quantity' => 70]]);
        $line = $this->json($this->request('GET', "/api/pos/returns/lookup?orderId=$gourde"))['lines'][0]['orderItemId'];
        $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $gourde, 'lines' => [['orderItemId' => $line, 'quantity' => 10]], 'reasonCode' => 'autre', 'refund' => ['method' => 'cash'], 'cashRegisterId' => $this->register]), 201);
        $this->sell([['productVariantId' => $v('DEMO-VESTE'), 'quantity' => 65]]);
        $cafe = $this->sell([['productVariantId' => $v('DEMO-CAFE'), 'quantity' => 200]]);
        $this->json($this->request('POST', "/api/order/cancel/$cafe", ['paymentMethod' => 2, 'cashRegisterId' => $this->register]));
        $old = $this->sell([['productVariantId' => $v('DEMO-REMORQUE'), 'quantity' => 150]]);
        $this->db->executeStatement("UPDATE \"order\" SET order_date = order_date - INTERVAL '40 days' WHERE id = ?", [$old]);
        $this->sell([['productVariantId' => $v('DEMO-REMORQUE'), 'quantity' => 30]]);

        $this->db->executeStatement('UPDATE entreprise SET pos_favorites_enabled = true, pos_favorites_grid_size = 100');
        $g = $grid();
        $this->assertSame([true, 'both', 30, 100, 'site', null], [$g['enabled'], $g['mode'], $g['days'], $g['gridSize'], $g['scope'], $g['cashRegisterId']]);
        $this->assertNotFalse($at($g, 'DEMO-SAC'));
        $this->assertLessThan($at($g, 'DEMO-VESTE'), $at($g, 'DEMO-SAC'), 'sac (90) avant veste (65)');
        $this->assertLessThan($at($g, 'DEMO-GOURDE'), $at($g, 'DEMO-VESTE'), 'veste (65) avant gourde : 70 vendues − 10 retournées = 60');
        $this->assertLessThan($at($g, 'DEMO-REMORQUE'), $at($g, 'DEMO-GOURDE'), 'remorque : 30 seulement dans les 30 jours');
        $this->assertTrue($at($g, 'DEMO-CAFE') === false || $at($g, 'DEMO-CAFE') > $at($g, 'DEMO-SAC'), 'vente annulée : non comptée');
        $this->assertFalse($at($g, 'DEMO-KAYAK'), 'location : jamais');
        $sac = $g['items'][$at($g, 'DEMO-SAC')];
        // Base partagée : un autre test peut ajouter des variantes au sac ; attendu calculé sur le nombre réel de variantes
        $sacVariants = (int) $this->db->fetchOne('SELECT COUNT(*) FROM product_variant WHERE product_id = ?', [$p('DEMO-SAC')]);
        $sacDefault = $sacVariants === 1 ? $v('DEMO-SAC') : null;
        $this->assertSame([false, $sacVariants > 1, $sacDefault, null], [$sac['pinned'], $sac['hasVariants'], $sac['defaultVariantId'], $sac['variantId']]);
        $this->assertSame(range(1, count($g['items'])), array_column($g['items'], 'rank'));
        $this->db->executeStatement('UPDATE entreprise SET pos_favorites_days = 60');
        $g60 = $grid();
        $this->assertLessThan($at($g60, 'DEMO-SAC'), $at($g60, 'DEMO-REMORQUE'), '60 jours : remorque 180 en tête');
        $this->db->executeStatement('UPDATE entreprise SET pos_favorites_days = 30');

        // Épinglage : responsable seulement ; refus détaillés
        $this->loginAs(['ROLE_USER_POS']);
        $this->assertSame([403, 'FORBIDDEN'], $this->error($this->request('PUT', '/api/pos/favorites/pins', ['pins' => [['productId' => $p('DEMO-SAC')]]])));
        $this->assertSame([403, 'FORBIDDEN'], $this->error($this->request('GET', '/api/pos/favorites/pins')));
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $bad = $this->request('PUT', '/api/pos/favorites/pins', ['pins' => [['productId' => 999999], ['productId' => $p('DEMO-KAYAK')], ['productId' => $p('DEMO-SAC'), 'variantId' => $v('DEMO-GOURDE')], ['productId' => $p('DEMO-SAC')], ['productId' => $p('DEMO-SAC')]]]);
        $this->assertSame([422, 'VALIDATION_FAILED'], $this->error($bad));
        $this->assertSame([['pins[0].productId', 'PRODUCT_NOT_FOUND'], ['pins[1].productId', 'PRODUCT_NOT_SELLABLE'], ['pins[2].variantId', 'VARIANT_MISMATCH'], ['pins[4]', 'DUPLICATE']],
            array_map(fn ($e) => [$e['path'], $e['code']], json_decode($bad->getContent(), true)['errors']));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM pos_favorite'), 'rien d\'écrit');

        // Site : casquette puis une variante précise du t-shirt (prix propre) ; épinglés d'abord, dans l'ordre, puis plus vendus sans doublon
        $journal = (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'FAVORITES_UPDATED'");
        $tshirt = $v('DEMO-TSHIRT', 2);
        $this->db->executeStatement('UPDATE product_variant SET price = 3150 WHERE id = ?', [$tshirt]);
        $this->db->executeStatement("UPDATE product SET special_price = 2500, special_price_from = NULL, special_price_to = NULL WHERE code = 'DEMO-SAC'");
        $pins = $this->json($this->request('PUT', '/api/pos/favorites/pins', ['pins' => [['productId' => $p('DEMO-CASQUETTE')], ['productId' => $p('DEMO-TSHIRT'), 'variantId' => $tshirt]]]));
        $this->assertSame(['site', null, false, [$p('DEMO-CASQUETTE'), $p('DEMO-TSHIRT')], [null, $tshirt]], [$pins['scope'], $pins['cashRegisterId'], $pins['inheritsSite'], array_column($pins['pins'], 'productId'), array_column($pins['pins'], 'variantId')]);
        $this->assertSame($journal + 1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'FAVORITES_UPDATED'"));
        $g = $grid();
        $this->assertSame([$p('DEMO-CASQUETTE'), $p('DEMO-TSHIRT')], array_slice($ids($g), 0, 2));
        $this->assertSame([true, true, false], [$g['items'][0]['pinned'], $g['items'][1]['pinned'], $g['items'][2]['pinned']]);
        $this->assertSame([$tshirt, $tshirt, false, 3150], [$g['items'][1]['variantId'], $g['items'][1]['defaultVariantId'], $g['items'][1]['hasVariants'], $g['items'][1]['price']], 'variante épinglée : son prix');
        $this->assertSame(2900, $g['items'][0]['price']);
        $this->assertSame(1, count(array_keys($ids($g), $p('DEMO-CASQUETTE'))), 'épinglé : pas répété dans les plus vendus');
        $this->assertSame(2500, $g['items'][$at($g, 'DEMO-SAC')]['price'], 'prix promotionnel compris');
        $this->assertSame(['rank', 'productId', 'variantId', 'defaultVariantId', 'hasVariants', 'name', 'variantLabel', 'price', 'image', 'barcode', 'pinned'], array_keys($g['items'][0]));

        // Modes et taille de la grille
        $this->db->executeStatement("UPDATE entreprise SET pos_favorites_mode = 'pinned'");
        $this->assertSame([$p('DEMO-CASQUETTE'), $p('DEMO-TSHIRT')], $ids($grid()));
        $this->db->executeStatement("UPDATE entreprise SET pos_favorites_mode = 'bestSellers'");
        $this->assertSame([], array_filter(array_column($grid()['items'], 'pinned')), 'plus vendus seuls : aucun épinglé');
        $this->db->executeStatement("UPDATE entreprise SET pos_favorites_mode = 'both', pos_favorites_grid_size = 3");
        $this->assertCount(3, $grid()['items']);
        $this->db->executeStatement('UPDATE entreprise SET pos_favorites_grid_size = 100');

        // Poste : ses épinglés REMPLACENT ceux du site ; liste vide : le poste reprend ceux du site
        $own = $this->json($this->request('PUT', '/api/pos/favorites/pins', ['cashRegisterId' => $this->register, 'pins' => [['productId' => $p('DEMO-GOURDE')]]]));
        $this->assertSame(['register', $this->register, false], [$own['scope'], $own['cashRegisterId'], $own['inheritsSite']]);
        $gr = $grid("?cashRegisterId=$this->register");
        $this->assertSame(['register', $p('DEMO-GOURDE'), true], [$gr['scope'], $gr['items'][0]['productId'], $gr['items'][0]['pinned']]);
        $this->assertFalse($gr['items'][1]['pinned'], 'épinglés du site absents sur ce poste');
        $this->assertSame('site', $grid()['scope'], 'sans poste : le site');
        $this->json($this->request('PUT', '/api/pos/favorites/pins', ['cashRegisterId' => $this->register, 'pins' => []]));
        $inherit = $this->json($this->request('GET', "/api/pos/favorites/pins?cashRegisterId=$this->register"));
        $this->assertSame([true, [$p('DEMO-CASQUETTE'), $p('DEMO-TSHIRT')]], [$inherit['inheritsSite'], array_column($inherit['pins'], 'productId')]);
        $this->assertSame('site', $grid("?cashRegisterId=$this->register")['scope']);

        // Produit épinglé devenu non vendable en caisse : écarté de la grille, épinglage conservé
        $this->db->executeStatement("UPDATE product SET is_pos = false WHERE code = 'DEMO-CASQUETTE'");
        $this->assertFalse($at($grid(), 'DEMO-CASQUETTE'));
        $this->assertFalse($this->json($this->request('GET', '/api/pos/favorites/pins'))['pins'][0]['sellable']);
        $this->db->executeStatement("UPDATE product SET is_pos = true WHERE code = 'DEMO-CASQUETTE'");

        // ETag et 304 ; une modification change l'ETag
        $first = $this->request('GET', '/api/pos/favorites');
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertSame(304, $this->request('GET', '/api/pos/favorites', null, ['HTTP_IF_NONE_MATCH' => $etag])->getStatusCode());
        $this->assertSame(304, $this->request('GET', '/api/pos/favorites', null, ['HTTP_IF_NONE_MATCH' => 'W/' . $etag])->getStatusCode());
        $this->json($this->request('PUT', '/api/pos/favorites/pins', ['pins' => [['productId' => $p('DEMO-SAC')]]]));
        $this->assertSame(200, $this->request('GET', '/api/pos/favorites', null, ['HTTP_IF_NONE_MATCH' => $etag])->getStatusCode());

        // Pack hors ligne : les ÉPINGLÉS seulement (28/10/2026), même format d'entrée ; les plus vendus restent en ligne ; l'ETag du pack
        // ne bouge pas avec les ventes
        $packResponse = $this->request('GET', "/api/pos/offline-pack?cashRegisterId=$this->register");
        $pack = $this->json($packResponse);
        $this->assertSame([['productId' => $p('DEMO-SAC'), 'variantId' => null, 'defaultVariantId' => $sacDefault, 'pinned' => true, 'rank' => 1]], $pack['favorites']);
        $this->assertGreaterThan(1, count($grid("?cashRegisterId=$this->register")['items']), 'la grille en ligne, elle, complète avec les plus vendus');
        // Une vente qui bouleverse le classement des plus vendus ne change pas les favoris du pack (le stock, lui, y change comme avant)
        $this->assertSame($p('DEMO-VESTE'), $grid("?cashRegisterId=$this->register")['items'][1]['productId'], 'avant : la veste (65) est le premier plus vendu derrière l\'épinglé');
        $this->sell([['productVariantId' => $v('DEMO-GOURDE'), 'quantity' => 100]]);
        $this->assertSame($p('DEMO-GOURDE'), $grid("?cashRegisterId=$this->register")['items'][1]['productId'], 'en ligne : la gourde (160) passe devant la veste');
        $this->assertSame($pack['favorites'], $this->json($this->request('GET', "/api/pos/offline-pack?cashRegisterId=$this->register"))['favorites'], 'pack : favoris identiques');
        $this->assertSame([], array_values(array_filter($pack['favorites'], fn ($f) => !$f['pinned'])));
        $this->db->executeStatement("UPDATE product SET special_price = NULL WHERE code = 'DEMO-SAC'");
        $this->db->executeStatement('UPDATE product_variant SET price = NULL WHERE id = ?', [$tshirt]);
    }

    public function testEasyAdminPinsAreJournaledAndFollowTheVariant(): void
    {
        $admin = $this->user(['ROLE_ADMIN']);
        $this->client->loginUser($admin, 'main');
        $url = fn (string $action, array $extra = []) => 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => $action, 'crudControllerFqcn' => PosFavoriteCrudController::class] + $extra);
        $this->client->request('GET', $url('index'), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $crawler = $this->client->request('GET', $url('new'), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-VESTE' ORDER BY v.id LIMIT 1");
        $journal = (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'FAVORITES_UPDATED'");
        $form = $crawler->filter('form[name="PosFavorite"]')->form();
        $this->client->submit($form, ['PosFavorite[product]' => (string) $this->db->fetchOne("SELECT id FROM product WHERE code = 'DEMO-SAC'"), 'PosFavorite[productVariant]' => (string) $variant, 'PosFavorite[position]' => '0']);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 303], 'enregistré');
        $row = $this->db->fetchAssociative('SELECT product_id, product_variant_id, cash_register_id FROM pos_favorite ORDER BY id DESC LIMIT 1');
        $this->assertSame([(int) $this->db->fetchOne("SELECT id FROM product WHERE code = 'DEMO-VESTE'"), $variant, null], [(int) $row['product_id'], (int) $row['product_variant_id'], $row['cash_register_id']], 'la variante fixe le produit');
        $this->assertSame($journal + 1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'FAVORITES_UPDATED'"));
        $this->assertStringContainsString('"source":"easyadmin"', (string) $this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'FAVORITES_UPDATED' ORDER BY id DESC LIMIT 1"));
    }

    private function sell(array $items): int
    {
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register]));

        return (int) $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => $q['cashTotal'] / 100]], 'cashRegisterId' => $this->register]), 201)['orderId'];
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
