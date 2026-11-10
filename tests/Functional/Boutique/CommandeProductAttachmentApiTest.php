<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rattachement de produits existants à une commande fournisseur (09/11/2026, application de gestion) : liste des produits à classer
 * (recherche, pagination, `scope=all`), rattachement tout ou rien sans toucher au prix de vente ni au stock, détachement, refus ; le tableau de
 * bord d'une commande existante (produit créé autrement, sans coût retenu) rend exactement les mêmes chiffres avant et après : zéro régression.
 * Depuis le lot L0 (10/11/2026) : lignes d'achat, réassort dans une autre commande accepté, rattachement répété idempotent (ligne mise à jour).
 */
class CommandeProductAttachmentApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private string $token;
    private int $cap;
    private int $tshirt;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-rattachement@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM commande_line');
        $this->db->executeStatement('UPDATE product SET commande_id = NULL, commande_cost_cents = NULL, commande_quantity = NULL, commande_attached_at = NULL');
        $this->db->executeStatement("DELETE FROM content_audit_log WHERE resource = 'commande-products'");
        $this->cap = (int) $this->db->fetchOne("SELECT id FROM product WHERE code = 'DEMO-CASQUETTE'");
        $this->tshirt = (int) $this->db->fetchOne("SELECT id FROM product WHERE code = 'DEMO-TSHIRT'");
        $this->db->executeStatement('UPDATE product SET price = 2900, purchase_price = 1000, coefficient_multiplier = 2.9, quantity = 3 WHERE id = ?', [$this->cap]);
        $this->db->executeStatement('UPDATE product SET price = 1900, purchase_price = 700, coefficient_multiplier = 2.5, quantity = 2 WHERE id = ?', [$this->tshirt]);
        $email = 'rattachement-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Test')->setRoles(['ROLE_ADMIN', 'ROLE_USER_INTERNET'])->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
    }

    protected function tearDown(): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $provider->getEntityManager()->getConnection()->executeStatement('DELETE FROM commande_line');
        $provider->getEntityManager()->getConnection()->executeStatement('UPDATE product SET commande_id = NULL, commande_cost_cents = NULL, commande_quantity = NULL, commande_attached_at = NULL');
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testExistingProductsAreAttachedWithoutTouchingPricesAndExistingDashboardsAreUnchanged(): void
    {
        $collection = $this->create(Collections::class);
        $a = $this->create(Commande::class, ['budget' => 100000, 'isClosed' => false], fn (Commande $c, EntityManagerInterface $em) => $c->setCollections($em->find(Collections::class, $collection->getId())));
        $b = $this->create(Commande::class, ['budget' => 50000, 'isClosed' => false], fn (Commande $c, EntityManagerInterface $em) => $c->setCollections($em->find(Collections::class, $collection->getId())));
        // Commande A : produit « d'avant » (créé dans la commande, sans coût retenu) : son tableau de bord ne doit pas bouger
        $this->db->executeStatement('UPDATE product SET commande_id = ? WHERE id = ?', [$a->getId(), $this->cap]);
        static::getContainer()->get(\App\Services\CommandeService\CommandeLineService::class)->backfill(); // rattachement d'avant le lot L0 : repris en ligne d'achat, comme la migration
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        $dashboardABefore = $this->json($this->request('GET', '/api/dashboard/commande/' . $a->getId()));
        // Règle du 09/11/2026 : prix d'achat × quantité (10,00 × 3), valeur de stock = coût × quantité × coefficient (× 2,9), plus de coefficient réappliqué
        $this->assertSame(1000.0 * 3, (float) $dashboardABefore['BudgetGeneral']['usedBudget'], 'budget utilisé = prix d\'achat × quantité');
        $this->assertSame(1000.0 * 3 * 2.9, (float) $dashboardABefore['ValeurStock']['stockValue'], 'valeur de stock = coût × quantité × coefficient');
        $priceBefore = $this->db->fetchAssociative('SELECT price, purchase_price, coefficient_multiplier FROM product WHERE id = ?', [$this->tshirt]);

        // Produits disponibles : la casquette (dans A) n'y est plus, le t-shirt oui ; recherche ; limite plafonnée
        $available = $this->json($this->request('GET', '/api/commandes/products/disponibles?q=shirt&limit=500'));
        $this->assertSame([100, 1], [$available['limit'], $available['page']]);
        $ids = array_column($available['items'], 'id');
        $this->assertContains($this->tshirt, $ids);
        $this->assertNotContains($this->cap, $ids, 'déjà acheté (une ligne d\'achat) : plus « à classer »');
        $this->assertContains($this->cap, array_column($this->json($this->request('GET', '/api/commandes/products/disponibles?q=casquette&scope=all'))['items'], 'id'), 'scope=all : tout le catalogue, pour un réassort');
        $this->assertSame(['id', 'name', 'code', 'barcode', 'image', 'price', 'purchasePrice', 'coefficientMultiplier', 'quantity', 'categories'], array_keys($available['items'][0]));
        $all = $this->json($this->request('GET', '/api/commandes/products/disponibles?limit=1'));
        $this->assertSame([1, 1], [count($all['items']), $all['limit']]);
        $this->assertGreaterThan(1, $all['total']);

        // Refus : corps vide, produit inconnu, prix invalide (rien d'écrit)
        $error = fn (Response $r) => [$r->getStatusCode(), json_decode($r->getContent(), true)['error'] ?? null];
        $this->assertSame([422, 'VALIDATION_FAILED'], $error($this->request('POST', '/api/commandes/' . $b->getId() . '/products/rattacher', ['products' => []])));
        $this->assertSame([404, 'PRODUCT_NOT_FOUND'], $error($this->request('POST', '/api/commandes/' . $b->getId() . '/products/rattacher', ['products' => [['productId' => 999999]]])));
        $this->assertSame([422, 'VALIDATION_FAILED'], $error($this->request('POST', '/api/commandes/' . $b->getId() . '/products/rattacher', ['products' => [['productId' => $this->tshirt, 'purchasePrice' => -5]]])));
        $this->assertSame([404, 'COMMANDE_NOT_FOUND'], $error($this->request('POST', '/api/commandes/999999/products/rattacher', ['products' => [['productId' => $this->tshirt]]])));
        $this->assertNull($this->db->fetchOne('SELECT commande_id FROM product WHERE id = ?', [$this->tshirt]), 'rien d\'écrit');
        // Tout ou rien : un produit inconnu en seconde position, rien n'est écrit pour le premier
        $this->assertSame([404, 'PRODUCT_NOT_FOUND'], $error($this->request('POST', '/api/commandes/' . $b->getId() . '/products/rattacher', ['products' => [['productId' => $this->tshirt, 'purchasePrice' => 1200], ['productId' => 999999]]])));
        $this->assertNull($this->db->fetchOne('SELECT commande_id FROM product WHERE id = ?', [$this->tshirt]), 'tout ou rien');

        // Rattachement du t-shirt à B : coût retenu 12,00, quantité de la ligne 5 ; prix de vente, purchasePrice, coefficient et STOCK intacts (lot L0)
        $attached = $this->json($this->request('POST', '/api/commandes/' . $b->getId() . '/products/rattacher', ['products' => [['productId' => $this->tshirt, 'purchasePrice' => 1200, 'quantity' => 5]]]));
        $this->assertSame([$this->tshirt, 1200, 5], [$attached['attached'][0]['productId'], $attached['attached'][0]['commandeCostCents'], $attached['attached'][0]['quantity']]);
        $this->assertSame([$b->getId(), 1200.0 * 5, 50000.0 - 1200.0 * 5], [$attached['commande']['id'], (float) $attached['commande']['usedBudget'], (float) $attached['commande']['remainingBudget']]);
        $row = $this->db->fetchAssociative('SELECT commande_id, commande_cost_cents, commande_quantity, quantity, price, purchase_price, coefficient_multiplier FROM product WHERE id = ?', [$this->tshirt]);
        $this->assertSame([$b->getId(), 1200, 5, 2], [(int) $row['commande_id'], (int) $row['commande_cost_cents'], (int) $row['commande_quantity'], (int) $row['quantity']], 'raccourcis de la ligne ; stock de l\'article inchangé (2)');
        $this->assertSame([[], 5, 1200, 5], [$attached['updated'], $attached['lines'][0]['quantityOrdered'], $attached['lines'][0]['unitCost'], $attached['lines'][0]['quantityReceived']], 'ligne créée, réputée reçue');
        $this->assertSame([(float) $priceBefore['price'], (float) $priceBefore['purchase_price'], (float) $priceBefore['coefficient_multiplier']], [(float) $row['price'], (float) $row['purchase_price'], (float) $row['coefficient_multiplier']], 'prix de vente, prix d\'achat et coefficient jamais touchés');
        $dashboardB = $this->json($this->request('GET', '/api/dashboard/commande/' . $b->getId()));
        $this->assertSame(1200.0 * 5, (float) $dashboardB['BudgetGeneral']['usedBudget'], 'produit rattaché : coût retenu × quantité');
        $this->assertSame([], $this->json($this->request('GET', '/api/commandes/products/disponibles?q=' . urlencode('DEMO-TSHIRT')))['items'], 'plus disponible');

        // Rejeu idempotent dans la même commande (coupure réseau) : la ligne est mise à jour, jamais doublée
        $again = $this->json($this->request('POST', '/api/commandes/' . $b->getId() . '/products/rattacher', ['products' => [['productId' => $this->tshirt, 'quantity' => 6]]]));
        $this->assertSame([[$attached['lines'][0]['id']], 6, 1200], [$again['updated'], $again['lines'][0]['quantityOrdered'], $again['lines'][0]['unitCost']], 'même ligne, coût gardé');
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM commande_line WHERE commande_id = ? AND product_id = ?', [$b->getId(), $this->tshirt]));
        // Réassort : la casquette, déjà achetée dans A, est achetée aussi dans B (plus de PRODUCT_ALREADY_ATTACHED)
        $restock = $this->json($this->request('POST', '/api/commandes/' . $b->getId() . '/products/rattacher', ['products' => [['productId' => $this->cap, 'purchasePrice' => 1100, 'quantity' => 4]]]));
        $this->assertSame([[], 1100], [$restock['updated'], $restock['attached'][0]['commandeCostCents']]);
        $this->assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM commande_line WHERE product_id = ?', [$this->cap]), 'deux achats de la casquette');
        $this->assertSame($b->getId(), (int) $this->db->fetchOne('SELECT commande_id FROM product WHERE id = ?', [$this->cap]), 'raccourci : dernière commande');

        // Zéro régression : tableau de bord de A identique, avant et après (sa ligne de casquette est intacte)
        $this->assertSame($dashboardABefore, $this->json($this->request('GET', '/api/dashboard/commande/' . $a->getId())));

        // Commande close : refus ; détachement : lien et coût retirés, prix intact ; second détachement 404
        $this->db->executeStatement('UPDATE commande SET is_closed = true WHERE id = ?', [$a->getId()]);
        $this->assertSame([409, 'COMMANDE_CLOSED'], $error($this->request('POST', '/api/commandes/' . $a->getId() . '/products/rattacher', ['products' => [['productId' => $this->tshirt]]])));
        $this->assertSame(204, $this->request('DELETE', '/api/commandes/' . $b->getId() . '/products/' . $this->tshirt)->getStatusCode());
        $row = $this->db->fetchAssociative('SELECT commande_id, commande_cost_cents, price FROM product WHERE id = ?', [$this->tshirt]);
        $this->assertSame([null, null, (float) $priceBefore['price']], [$row['commande_id'], $row['commande_cost_cents'], (float) $row['price']], 'plus aucune ligne : raccourcis remis à NULL');
        $this->assertSame([404, 'PRODUCT_NOT_IN_COMMANDE'], $error($this->request('DELETE', '/api/commandes/' . $b->getId() . '/products/' . $this->tshirt)));
        $this->assertSame(204, $this->request('DELETE', '/api/commandes/' . $b->getId() . '/products/' . $this->cap)->getStatusCode());
        $this->assertSame($a->getId(), (int) $this->db->fetchOne('SELECT commande_id FROM product WHERE id = ?', [$this->cap]), 'raccourci revenu sur l\'achat restant (A)');
        $this->assertSame(5, (int) $this->db->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'commande-products'"), 'journal : trois rattachements, deux détachements');

        // Sans jeton : refusé
        $this->token = '';
        $this->assertSame(401, $this->request('GET', '/api/commandes/products/disponibles')->getStatusCode());
    }

    protected function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function json(Response $response, int $expected = 200): array
    {
        $this->assertSame($expected, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token !== '' ? 'Bearer ' . $this->token : null,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }
}
