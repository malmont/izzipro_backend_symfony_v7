<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Product;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\SqlRecorderMiddleware;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lectures du catalogue bornées (S8, incident PHP-FPM du 26/10/2026), reprises au retrait d'API Platform (lot APIP-RETRAIT,
 * 20/11/2026) : la liste complète des articles (`GET /api/products`) et les anciennes routes d'API Platform (catégories, véhicules,
 * formats `.json`) n'existent plus, refusées avant toute requête sur les produits ;
 * routes des fronts (/api/products/{id}, /api/category, /api/vehicle_products) bornées quel que soit le nombre de commandes.
 * La forme exacte de /api/vehicle_products est figée par RoutesGardeesApresApiPlatformTest.
 */
class CatalogueBoundedReadsTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private int $register;
    private int $cap;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-avoir@example.invalid', false, false);
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_return_mode = 'refund', pos_approval_return = NULL, pos_return_cash_for_card_sale = true, pos_return_max_days = NULL, pos_return_without_receipt = false, pos_gift_card_enabled = true");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), receipt_prefix = NULL');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement("UPDATE product SET price = 2900, special_price = NULL WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 100, price = NULL WHERE id = ?', [$this->cap]);
    }

    public function testTheFullProductListAndOldApiPlatformRoutesAreGone(): void
    {
        // Deux ventes payées ; une commande prend pour statut l'identifiant du statut de paiement (situation qui faisait planter la liste)
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $sale = $this->sale();
        $other = $this->sale();
        $statusPayment = (int) $this->db->fetchOne('SELECT statut_payment_id FROM payments WHERE order_payment_id = ? LIMIT 1', [$sale]);
        $this->db->executeStatement('UPDATE "order" SET status_id = ? WHERE id = ?', [$statusPayment, $other]);
        $productId = (int) $this->db->fetchOne('SELECT product_id FROM product_variant WHERE id = ?', [$this->cap]);
        $productQueries = fn (array $q) => array_values(array_filter($q, fn ($s) => preg_match('/\b(FROM|JOIN)\s+"?(product|categories|product_variant)"?\b/i', $s)));

        // Liste complète des articles (API Platform, puis `get_all_products` qu'il masquait) : retirée au lot APIP-RETRAIT, plus de
        // route pour personne, refusée avant toute requête sur les produits
        foreach ([null, 'client', 'admin'] as $who) {
            $this->token = null;
            if ($who !== null) {
                $who === 'client' ? $this->loginAs(['ROLE_USER_INTERNET'], 'mobile') : $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
            }
            SqlRecorderMiddleware::start();
            $this->assertContains($this->request('GET', '/api/products')->getStatusCode(), [404, 405], '/api/products (' . ($who ?? 'anonyme') . ')');
            $this->assertSame([], $productQueries(SqlRecorderMiddleware::stop()), 'aucune requête sur les produits');
        }

        // Anciennes routes d'API Platform : plus de route, pour tous
        foreach ([null, 'admin'] as $who) {
            $this->token = null;
            if ($who === 'admin') {
                $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
            }
            foreach (['/api/products.json', "/api/products/$productId.json", '/api/categories', '/api/categories/1', '/api/vehicles', '/api/vehicles/1'] as $path) {
                $this->assertSame(404, $this->request('GET', $path)->getStatusCode(), "$path (" . ($who ?? 'anonyme') . ')');
            }
        }

        // Routes des fronts : toujours publiques
        $this->token = null;
        $this->assertSame(200, $this->request('GET', "/api/products/$productId?locale=fr")->getStatusCode(), 'fiche produit (site Next.js)');
        $this->assertSame(200, $this->request('GET', '/api/category?locale=fr')->getStatusCode(), 'catégories de la boutique');
        $this->assertSame(200, $this->request('GET', '/api/vehicle_products?page=1&locale=fr')->getStatusCode(), 'véhicules de la boutique');

    }

    public function testFrontReadsStayBoundedWhateverTheNumberOfOrders(): void
    {
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $this->sale();
        $productId = (int) $this->db->fetchOne('SELECT product_id FROM product_variant WHERE id = ?', [$this->cap]);
        $this->token = null;
        $heavy = '/\b(FROM|JOIN)\s+"?(order|order_items|payments|booking|reviews_product)"?\b/i';
        $measure = function (string $path) use ($heavy) {
            $this->request('GET', $path); // chauffe (caches de métadonnées)
            SqlRecorderMiddleware::start();
            $r = $this->request('GET', $path);
            $q = SqlRecorderMiddleware::stop();
            $this->assertSame(200, $r->getStatusCode(), $path);

            return ['count' => count($q), 'heavy' => array_values(array_filter($q, fn ($s) => preg_match($heavy, $s)))];
        };
        $item = $measure("/api/products/$productId?locale=fr");
        $vehicles = $measure('/api/vehicle_products?page=1&pageSize=50&locale=fr');
        $this->assertSame([], $item['heavy'], 'fiche produit : aucune lecture des commandes, paiements ou réservations');

        // Quinze ventes de plus du même produit : mêmes nombres de requêtes
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        for ($i = 0; $i < 15; $i++) {
            $this->sale();
        }
        $this->token = null;
        $this->assertSame($item['count'], $measure("/api/products/$productId?locale=fr")['count'], 'fiche produit bornée');
        $this->assertSame($vehicles['count'], $measure('/api/vehicle_products?page=1&pageSize=50&locale=fr')['count'], 'produits véhicules bornés');
        $vp = $this->json($this->request('GET', '/api/vehicle_products'));
        foreach ($this->members($vp) as $m) {
            $this->assertArrayNotHasKey('bookings', $m, 'liste des réservations non exposée (une entrée par réservation, sans limite)');
            $this->assertArrayNotHasKey('variants', $m);
            $this->assertSame([], array_values(array_diff(array_keys($m['bookingConfiguration'] ?? []), ['@id', '@type', 'id', 'granularity', 'stockQuantity', 'minDuration', 'bufferTime'])), 'configuration de réservation : champs simples');
        }
    }

    /** Éléments d'une collection, en JSON simple (application/json) ou JSON-LD */
    private function members(array $body): array
    {
        return array_is_list($body) ? $body : ($body['hydra:member'] ?? $body['member'] ?? []);
    }

    private function sale(): int
    {
        return (int) $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 33.35]], 'cashRegisterId' => $this->register]), 201)['orderId'];
    }

    private function json(Response $response, int $expected = 200): array
    {
        $this->assertSame($expected, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter(['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles, string $platform = 'pos'): User
    {
        $email = 'lectures-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Avoir')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $email, 'password' => self::PASSWORD, 'platform' => $platform]);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];

        return $user;
    }
}
