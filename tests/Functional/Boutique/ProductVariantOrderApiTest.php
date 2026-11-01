<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Product;
use App\Entity\ProductOption;
use App\Entity\ProductOptionValue;
use App\Entity\ProductVariant;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ordre des variantes d'un produit (31/10/2026) : la fiche par slug les rendait dans l'ordre physique des lignes
 * (aucun ORDER BY) alors que la liste par catégorie les rendait par identifiant ; les clients affichaient
 * « L, XL, M, S ». Désormais : variantes par identifiant croissant, options de chaque variante par type d'option
 * puis valeur, identiques sur toutes les routes.
 */
class ProductVariantOrderApiTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testVariantsAndTheirOptionsComeInTheSameStableOrderOnEveryRoute(): void
    {
        $em = $this->em();
        $suffix = bin2hex(random_bytes(3));
        $slug = 'tri-variantes-' . $suffix;

        $taille = (new ProductOption())->setName('Taille ' . $suffix)->setCode('taille-' . $suffix);
        $couleur = (new ProductOption())->setName('Couleur ' . $suffix)->setCode('couleur-' . $suffix);
        $em->persist($taille);
        $em->persist($couleur);
        $noir = (new ProductOptionValue())->setValue('Noir')->setProductOption($couleur);
        $em->persist($noir);

        $product = (new Product())->setName('Tri variantes ' . $suffix)->setSlug($slug)->setPrice(1990)->setIsWeb(true);
        $sizes = [];
        foreach (['S', 'M', 'L', 'XL'] as $label) {
            $size = (new ProductOptionValue())->setValue($label)->setProductOption($taille);
            $em->persist($size);
            // la couleur est attachée avant la taille : la table de jointure garde cet ordre d'insertion
            $variant = (new ProductVariant())->setStockQuantity(5)->addOptionValue($noir)->addOptionValue($size);
            $product->addVariant($variant);
            $sizes[$label] = $variant;
        }
        $em->persist($product);
        $em->flush();

        $ids = array_map(fn (ProductVariant $v) => $v->getId(), $product->getVariants()->toArray());
        sort($ids);
        // Une mise à jour déplace la première ligne en fin de table (nouvelle version MVCC) : sans tri, une lecture
        // séquentielle la rend en dernier.
        $em->getConnection()->executeStatement('UPDATE product_variant SET stock_quantity = 6 WHERE id = ?', [$ids[0]]);
        $em->clear();

        $bySlug = $this->get("/api/products/by-slug/$slug?locale=fr")['variants'];
        $byId = $this->get('/api/products/' . $product->getId() . '?locale=fr')['variants'];
        $list = $this->get('/api/products/by-category?locale=fr&pageSize=50&keyword=' . urlencode('Tri variantes ' . $suffix))['data'];
        $this->assertCount(1, $list);
        $byCategory = $list[0]['variants'];
        $variantsRoute = $this->get('/api/products/' . $product->getId() . '/variants?locale=fr');

        $expectedOptions = ['taille-' . $suffix, 'couleur-' . $suffix];
        foreach (['by-slug' => $bySlug, 'products/{id}' => $byId, 'by-category' => $byCategory, '{id}/variants' => $variantsRoute] as $route => $variants) {
            $this->assertSame($ids, array_column($variants, 'id'), "$route : variantes par identifiant croissant");
            foreach ($variants as $variant) {
                $this->assertSame($expectedOptions, array_column($variant['options'], 'option_code'), "$route : options par type d'option");
            }
        }
        $this->assertSame(array_column($bySlug, 'id'), array_column($byCategory, 'id'), 'même ordre dans la fiche et la liste');
        $this->assertSame(
            ['S', 'M', 'L', 'XL'],
            array_map(fn (array $v) => $v['options'][0]['value'], $bySlug),
            'la fiche montre S, M, L, XL'
        );
    }

    private function get(string $path): array
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function em(): \Doctrine\ORM\EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }
}
