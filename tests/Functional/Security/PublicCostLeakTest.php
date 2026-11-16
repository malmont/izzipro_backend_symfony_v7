<?php

namespace App\Tests\Functional\Security;

use App\Dto\ProductDetailedOutputDTO;
use App\Dto\ProductOutputCategoryDto;
use App\Dto\ProductPurchaseOutputDTO;
use App\Entity\Categories;
use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\Fournisseur;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot COST-1 (16/11/2026) : LE COÛT NE SORT PAS DES ROUTES OUVERTES. Avant ce lot, les fiches produit publiques rendaient le prix
 * d'achat et le coefficient à un visiteur sans connexion (`purchasePrice`, `coefficientMultiplier`).
 *
 * Un article est créé avec un coût, un coefficient, un coût de commande et un fournisseur aux valeurs RECONNAISSABLES. Puis chaque
 * route en lecture de l'application est appelée sans connexion, et de nouveau avec un simple compte client (n'importe qui peut en
 * ouvrir un) : aucune réponse ne doit porter ni ces valeurs, sous quelque nom que ce soit, ni le nom d'un champ de coût.
 * Une route nouvelle est balayée d'office : elle est lue dans la table des routes.
 */
class PublicCostLeakTest extends WebTestCase
{
    /** Valeurs posées sur l'article : leur écriture en cents et en unités, le coefficient, le coût de commande, le fournisseur, la commande */
    private const MARKERS = ['791913', '7919.13', '3.14159', '654321', '6543.21', 'Fournisseur-Secret-COST1', 'Commande-Secrete-COST1'];
    /** Noms de champs qui disent un coût ou une marge, quelle que soit leur valeur */
    private const KEYS = '/"(purchase_?price|coefficient_?multiplier|commande_?cost\w*|cost_?price|prix_?achat|marge\w*|margin\w*)"\s*:/i';
    /** Chemins qui ne rendent pas une réponse de l'application : outils du cadre, fichiers, pages d'administration (fermées par leur garde), sorties vers un tiers */
    private const SKIPPED = '#^/(_|admin(/|$)|assets/|uploads/|media/|bucket-simulator/|logout|stripe/|setup/|create-checkout-session/|stripe-payment)#';

    private KernelBrowser $client;
    private string $tag;
    private int $productId = 0;
    private int $variantId = 0;
    private int $categoryId = 0;
    private string $slug = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tag = bin2hex(random_bytes(3));
        $em = $this->em();
        $this->slug = 'cost1-' . $this->tag;
        $category = (new Categories())->setName('Cost1 ' . $this->tag);
        $supplier = (new Fournisseur())->setName('Fournisseur-Secret-COST1');
        $period = (new Collections())->setNomCollection('Cost1 ' . $this->tag)->setBudgetCollection(0)->setStartDateCollection(new \DateTime('2026-01-01'))->setEndDateCollection(new \DateTime('2026-12-31'))->setDel(false)->setIsClosed(false);
        $order = (new Commande())->setName('Commande-Secrete-COST1')->setDate(new \DateTime('2026-11-05 10:00:00'))->setBudget(0)->setFournisseur($supplier)->setCollections($period);
        $product = (new Product())->setName('Article Cost1 ' . $this->tag)->setSlug($this->slug)->setIsWeb(true)->setIsPos(true)->setSaleEnabled(true)->setImage('photo-cost1.jpg')
            ->setPurchasePrice(791913.0)->setCoefficientMultiplier(3.14159)->setCommande($order)->setCommandeCostCents(654321)->setBarcode('COST1' . strtoupper($this->tag));
        $variant = (new ProductVariant())->setStockQuantity(4)->setPrice(2500);
        $product->addVariant($variant);
        $product->addCategory($category);
        foreach ([$category, $supplier, $period, $order, $product] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        [$this->productId, $this->variantId, $this->categoryId] = [(int) $product->getId(), (int) $variant->getId(), (int) $category->getId()];
        // l'essai n'a de sens que si le coût est bien en base
        $stored = $em->getConnection()->fetchNumeric('SELECT purchase_price, coefficient_multiplier, commande_cost_cents FROM product WHERE id = ?', [$this->productId]);
        $this->assertSame([791913.0, 3.14159, 654321], [(float) $stored[0], (float) $stored[1], (int) $stored[2]]);
    }

    protected function tearDown(): void
    {
        $db = $this->em()->getConnection();
        $db->executeStatement('DELETE FROM product_variant WHERE product_id = ?', [$this->productId]);
        $db->executeStatement('DELETE FROM product_categories WHERE product_id = ?', [$this->productId]);
        $db->executeStatement('DELETE FROM product WHERE id = ?', [$this->productId]);
        $db->executeStatement("DELETE FROM commande WHERE name = 'Commande-Secrete-COST1'");
        $db->executeStatement("DELETE FROM fournisseur WHERE name = 'Fournisseur-Secret-COST1'");
        $db->executeStatement('DELETE FROM collections WHERE nom_collection = ?', ['Cost1 ' . $this->tag]);
        $db->executeStatement('DELETE FROM categories WHERE id = ?', [$this->categoryId]);
        $db->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'cost1-client-%@example.invalid'");
        parent::tearDown();
    }

    public function testThePublicProductSheetsShowTheArticleButNeverItsCost(): void
    {
        $name = 'Article Cost1 ' . $this->tag;
        $reads = [
            "/api/products/{$this->productId}?locale=fr", "/api/productsid/{$this->productId}", "/api/products/by-slug/{$this->slug}?locale=fr",
            '/api/products/by-category?categories=' . urlencode(json_encode([$this->categoryId])),
            '/api/products/by-category?barcode=COST1' . strtoupper($this->tag), '/api/products/by-category?keyword=' . urlencode('Cost1 ' . $this->tag),
        ];
        foreach ($reads as $path) {
            $body = $this->read($path, null);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), "$path : $body");
            $this->assertStringContainsString($name, $body, "$path : l'article est bien dans la réponse");
            $this->assertSame([], self::leaks($body), "$path rend un coût");
            $decoded = json_decode($body, true);
            array_walk_recursive($decoded, function (mixed $value, int|string $key) use ($path): void {
                $this->assertNotContains($key, ['purchasePrice', 'coefficientMultiplier'], "$path : le champ $key n'existe plus, même vide");
            });
        }
        // ses variantes, lues par l'application mobile
        $variants = $this->read("/api/products/{$this->productId}/variants", null);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $variants);
        $this->assertStringContainsString((string) $this->variantId, $variants);
        $this->assertSame([], self::leaks($variants));
        // le devis du panier, sans connexion
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/cart/quote', [], [], $this->server(null) + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['items' => [['productId' => $this->productId, 'variantId' => $this->variantId, 'quantity' => 1]]]));
        $this->assertSame([], self::leaks((string) $this->client->getResponse()->getContent()), 'devis du panier');
    }

    public function testNoReadRouteGivesACostToAVisitorOrToACustomerAccount(): void
    {
        $customer = $this->customerToken();
        $leaking = [];
        $answered = ['visiteur' => 0, 'client' => 0];
        foreach ($this->readPaths() as $path) {
            foreach (['visiteur' => null, 'client' => $customer] as $who => $token) {
                $body = $this->read($path, $token);
                $answered[$who] += $this->client->getResponse()->getStatusCode() === 200 ? 1 : 0;
                foreach (self::leaks($body) as $leak) {
                    $leaking[] = "$who GET $path : $leak";
                }
            }
        }
        $this->assertSame([], $leaking, 'Réponse(s) ouverte(s) qui rendent un coût, un coefficient, un coût de commande ou un fournisseur.');
        // la documentation d'API Platform nomme tous les champs des entités, coût compris : elle n'est plus lue que par l'administrateur
        foreach (['/api/docs.json', '/api/docs', '/api/contexts/Product.jsonld'] as $path) {
            $this->read($path, null);
            $this->assertSame(401, $this->client->getResponse()->getStatusCode(), $path);
            $this->read($path, $customer);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), $path);
        }
        // le balayage a bien lu des réponses : sans cela il ne prouverait rien
        $this->assertGreaterThan(40, $answered['visiteur'], 'réponses 200 lues sans connexion');
        $this->assertGreaterThan($answered['visiteur'], $answered['client'], 'un compte client lit davantage de routes qu\'un visiteur');
    }

    public function testACacheEntryWrittenBeforeTheLotLosesItsCostWhenItIsReadAgain(): void
    {
        $product = $this->em()->find(Product::class, $this->productId);
        foreach ([new ProductDetailedOutputDTO($product, 'https://exemple.test'), new ProductOutputCategoryDto($product, 'https://exemple.test')] as $dto) {
            // une entrée d'avant le lot : le même objet, avec ses deux champs de coût en plus
            $old = serialize($dto);
            $this->assertSame(1, preg_match('/^O:\d+:"[^"]+":(\d+):\{/', $old, $m));
            $old = preg_replace('/^(O:\d+:"[^"]+":)\d+:\{/', '${1}' . ((int) $m[1] + 2) . ':{s:13:"purchasePrice";d:791913;s:21:"coefficientMultiplier";d:3.14159;', $old);
            $read = @unserialize($old);
            $this->assertInstanceOf($dto::class, $read);
            $this->assertSame([], self::leaks((string) json_encode($read)), $dto::class);
            $this->assertStringContainsString('Article Cost1', (string) json_encode($read), 'le reste de la fiche est intact');
        }
        // la réponse de la gestion, elle, garde son coût d'un passage en cache à l'autre
        $purchase = unserialize(serialize(new ProductPurchaseOutputDTO($product, 'https://exemple.test')));
        $this->assertSame([791913.0, 3.14159], [$purchase->purchasePrice, $purchase->coefficientMultiplier]);
    }

    /** @return list<string> ce qui, dans une réponse, dit un coût : valeurs reconnaissables et noms de champs */
    private static function leaks(string $body): array
    {
        $found = array_values(array_filter(self::MARKERS, static fn (string $marker): bool => str_contains($body, $marker)));
        if (preg_match_all(self::KEYS, $body, $m)) {
            $found = array_merge($found, array_values(array_unique($m[1])));
        }

        return $found;
    }

    /** @return list<string> toutes les routes de lecture, leurs paramètres remplis par l'article de l'essai */
    private function readPaths(): array
    {
        $values = ['id' => $this->productId, 'productId' => $this->productId, 'variantId' => $this->variantId, 'slug' => $this->slug, 'offer' => 'bestsellers', '_format' => 'json'];
        $paths = [];
        foreach (static::getContainer()->get('router')->getRouteCollection() as $route) {
            if (preg_match(self::SKIPPED, $route->getPath()) || ($route->getMethods() !== [] && !in_array('GET', $route->getMethods(), true))) {
                continue;
            }
            $paths[] = preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) ($values[$m[1]] ?? 1), $route->getPath());
        }
        // les mêmes lectures par leurs filtres, et les listes qui rendent des articles
        array_push($paths, '/api/products/by-category?categories=' . urlencode(json_encode([$this->categoryId])), '/api/products/by-category?barcode=COST1' . strtoupper($this->tag),
            '/api/products/newArrivals', '/api/products/specialOffers', '/api/products/landingpage', "/api/products/{$this->productId}?locale=en", '/api/category?locale=fr',
            "/api/categories/{$this->categoryId}.json", '/api/vehicle_products.json', '/api/products.json', '/api/products.jsonld');

        return array_values(array_unique($paths));
    }

    private function read(string $path, ?string $token): string
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $this->server($token));

        return (string) $this->client->getResponse()->getContent();
    }

    /** @return array<string, string> */
    private function server(?string $token): array
    {
        return ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.86.' . random_int(1, 254) . '.' . random_int(1, 254)]
            + ($token !== null ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $token] : []);
    }

    private function customerToken(): string
    {
        $email = 'cost1-client-' . $this->tag . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Client')->setLastname('Cost1')->setRoles(['ROLE_USER_INTERNET'])->setPassword('inutilisable')->setIsVerified(true);
        $em = $this->em(); // une seule fois : chaque appel revient au site de test et vide le gestionnaire
        $em->persist($user);
        $em->flush();

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    private function em(): \Doctrine\ORM\EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }
}
