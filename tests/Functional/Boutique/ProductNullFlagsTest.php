<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Categories;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Colonnes product.isnewarrival, isfeatured, isspecialoffer nullables sans défaut (12/11/2026) : un NULL faisait planter
 * les routes publiques du catalogue (TypeError dans les DTO typés bool, 500). NULL se lit « non », sans toucher aux bases.
 */
class ProductNullFlagsTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testProductWithNullFlagsIsListedAndNotMarked(): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $slug = 'drapeaux-nuls-' . bin2hex(random_bytes(3));
        $category = (new Categories())->setName('Drapeaux nuls ' . $slug);
        $product = (new Product())->setName('Produit aux drapeaux nuls')->setSlug($slug)->setPrice(1500)->setIsWeb(true)->setSaleEnabled(true);
        $product->addVariant((new ProductVariant())->setStockQuantity(2)->setPrice(1500));
        $product->addCategory($category);
        $em->persist($category);
        $em->persist($product);
        $em->flush();
        $em->getConnection()->executeStatement('UPDATE product SET isnewarrival = NULL, isfeatured = NULL, isspecialoffer = NULL WHERE id = ?', [$product->getId()]);
        $em->clear();
        static::getContainer()->get('cache.app')->clear();
        $id = $product->getId();
        $flags = ['isnewarrival' => false, 'isfeatured' => false, 'isspecialoffer' => false]; // isbestseller est NOT NULL dans toutes les bases

        $list = $this->get('/api/products/by-category?categories=' . urlencode(json_encode([$category->getId()])));
        $this->assertSame($flags, array_intersect_key($this->find($list, $id), $flags), 'liste d\'une catégorie : NULL = non');
        $this->assertSame($flags, array_intersect_key($this->get("/api/productsid/$id"), $flags), 'fiche par identifiant');
        $this->assertSame($flags, array_intersect_key($this->get("/api/products/by-slug/$slug"), $flags), 'fiche par slug');
        $this->assertNull($this->find($this->get('/api/products/newarrivals'), $id, false), 'NULL n\'est pas une nouveauté');
    }

    private function get(string $path): array
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $path . ' : ' . substr((string) $response->getContent(), 0, 300));

        return json_decode((string) $response->getContent(), true);
    }

    /** Fiche du produit $id dans une réponse de liste, quelle que soit son enveloppe */
    private function find(array $data, int $id, bool $required = true): ?array
    {
        $stack = [$data];
        while ($stack) {
            $node = array_pop($stack);
            if (($node['id'] ?? null) === $id && array_key_exists('isnewarrival', $node)) {
                return $node;
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $stack[] = $child;
                }
            }
        }
        if ($required) {
            $this->fail("produit $id absent de la réponse");
        }

        return null;
    }
}
