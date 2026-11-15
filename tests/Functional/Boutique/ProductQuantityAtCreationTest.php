<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\ProductVariant;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\ProductAiService\ProductSheetCreator;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La quantité d'un article est la somme du stock de ses variantes, dès sa création. Avant le 15/11/2026, un article créé par la fiche
 * produit (IA, photo) était écrit avec une quantité de 0 alors que ses variantes avaient du stock, jusqu'à la prochaine écriture
 * d'une variante : il paraissait épuisé et ne comptait pas dans la valeur du stock.
 */
class ProductQuantityAtCreationTest extends WebTestCase
{
    private string $tag;

    protected function setUp(): void
    {
        static::createClient();
        $this->tag = strtoupper(bin2hex(random_bytes(3)));
    }

    protected function tearDown(): void
    {
        $conn = $this->em()->getConnection();
        $ids = array_map('intval', $conn->fetchFirstColumn('SELECT id FROM product WHERE name LIKE ?', ['% ' . $this->tag]));
        if ($ids !== []) {
            $in = implode(',', $ids);
            $conn->executeStatement("DELETE FROM product_variant WHERE product_id IN ($in)");
            foreach (['product_translation', 'product_picture', 'product_categories'] as $table) {
                $conn->executeStatement("DELETE FROM $table WHERE product_id IN ($in)");
            }
            $conn->executeStatement("DELETE FROM product WHERE id IN ($in)");
        }
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
        parent::tearDown();
    }

    public function testAnArticleCarriesTheStockOfItsVariantsFromItsCreation(): void
    {
        $conn = $this->em()->getConnection();
        $quantity = fn (int $id): array => [(int) $conn->fetchOne('SELECT quantity FROM product WHERE id = ?', [$id]), (int) $conn->fetchOne('SELECT COALESCE(SUM(stock_quantity), 0) FROM product_variant WHERE product_id = ?', [$id])];
        $creator = static::getContainer()->get(ProductSheetCreator::class);

        $single = (int) $creator->create(['name' => "Sans variante {$this->tag}", 'priceCents' => 500, 'stockQuantity' => 3], 'test')['body']['productId'];
        $this->assertSame([3, 3], $quantity($single), 'article sans option : sa seule variante porte le stock');

        $sized = (int) $creator->create(['name' => "Deux tailles {$this->tag}", 'priceCents' => 500, 'stockQuantity' => 3, 'variants' => ['sizes' => ['S', 'M']]], 'test')['body']['productId'];
        $this->assertSame([6, 6], $quantity($sized), 'deux variantes de 3 : 6');

        // Une variante modifiée ensuite : la quantité de l'article suit, et l'article reste le même (aucune copie)
        $em = $this->em();
        $em->clear();
        $variant = $em->getRepository(ProductVariant::class)->findOneBy(['product' => $sized], ['id' => 'ASC']);
        $variant->setStockQuantity(10);
        $em->flush();
        $this->assertSame([13, 13], $quantity($sized));
        $this->assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM product WHERE name = ?', ["Deux tailles {$this->tag}"]));
    }

    private function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }
}
