<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\ProductVariant;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Écriture du stock à la vente, à l'annulation et au retour (10/11/2026) : le stock de départ est relu en base sous verrou, et non plus
 * pris dans la valeur chargée en mémoire. Une vente concurrente validée entre le chargement et l'écriture n'est plus effacée ; deux lignes
 * de la même variante sur un ticket s'additionnent ; un stock final égal à la valeur chargée est bien écrit ; le stock de l'article suit.
 */
class StockConcurrentWriteTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;
    private UpdateStockAndInventoryUseCase $stock;
    private int $variantId;
    private int $productId;

    protected function setUp(): void
    {
        self::bootKernel();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->em = $provider->getEntityManager();
        $this->db = $this->em->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-stock@example.invalid', false, false);
        $this->productId = (int) $this->db->fetchOne("SELECT id FROM product WHERE code = 'DEMO-CASQUETTE'");
        $this->variantId = (int) $this->db->fetchOne('SELECT id FROM product_variant WHERE product_id = ? ORDER BY id LIMIT 1', [$this->productId]);
        $this->assertGreaterThan(0, $this->variantId, 'variante de démonstration attendue');
        $this->stock = static::getContainer()->get(UpdateStockAndInventoryUseCase::class);
    }

    /** Variante chargée avec un stock de 10, puis une vente concurrente validée la fait passer à $concurrent en base */
    private function loadedThenSoldElsewhere(int $concurrent): ProductVariant
    {
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 10 WHERE id = ?', [$this->variantId]);
        $this->em->clear();
        $variant = $this->em->find(ProductVariant::class, $this->variantId);
        $this->assertSame(10, $variant->getStockQuantity());
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = ? WHERE id = ?', [$concurrent, $this->variantId]);

        return $variant;
    }

    private function inTransaction(callable $fn): void
    {
        $this->db->beginTransaction();
        $fn();
        $this->em->flush();
        $this->db->commit();
    }

    /** @return list<array{before: int, after: int, quantity: int}> derniers mouvements de la variante, du plus ancien au plus récent */
    private function lastMovements(int $count): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT stock_before_movement, stock_after_movement, quantity FROM inventory_movements WHERE product_variant_id = ? ORDER BY id DESC LIMIT ' . $count, [$this->variantId]);

        return array_reverse(array_map(fn ($r) => ['before' => (int) $r['stock_before_movement'], 'after' => (int) $r['stock_after_movement'], 'quantity' => (int) $r['quantity']], $rows));
    }

    private function dbStock(): int
    {
        return (int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$this->variantId]);
    }

    public function testASaleNoLongerErasesAConcurrentSale(): void
    {
        $variant = $this->loadedThenSoldElsewhere(8);
        $this->inTransaction(fn () => $this->stock->execute($variant, 1));

        $this->assertSame(7, $this->dbStock(), 'la vente concurrente (10 → 8) est conservée : 8 − 1 = 7, et non 10 − 1 = 9');
        $this->assertSame([['before' => 8, 'after' => 7, 'quantity' => 1]], $this->lastMovements(1));
    }

    public function testAFinalStockEqualToTheLoadedValueIsStillWritten(): void
    {
        $variant = $this->loadedThenSoldElsewhere(8);
        $this->inTransaction(fn () => $this->stock->execute($variant, 2, true)); // annulation : + 2

        $this->assertSame(10, $this->dbStock(), '8 + 2 = 10, égal à la valeur chargée : écrit quand même');
        $this->assertSame([['before' => 8, 'after' => 10, 'quantity' => 2]], $this->lastMovements(1));
    }

    public function testTwoLinesOfTheSameVariantAddUp(): void
    {
        $variant = $this->loadedThenSoldElsewhere(9);
        $this->inTransaction(function () use ($variant) {
            $this->stock->execute($variant, 1);
            $this->stock->execute($variant, 2);
        });

        $this->assertSame(6, $this->dbStock(), '9 − 1 − 2');
        $this->assertSame([['before' => 9, 'after' => 8, 'quantity' => 1], ['before' => 8, 'after' => 6, 'quantity' => 2]], $this->lastMovements(2));
        $sum = (int) $this->db->fetchOne('SELECT COALESCE(SUM(stock_quantity), 0) FROM product_variant WHERE product_id = ?', [$this->productId]);
        $this->assertSame($sum, (int) $this->db->fetchOne('SELECT quantity FROM product WHERE id = ?', [$this->productId]), 'stock de l\'article recalculé sur ses variantes');
    }

    public function testWithoutConcurrencyNothingChanges(): void
    {
        $variant = $this->loadedThenSoldElsewhere(10);
        $this->inTransaction(fn () => $this->stock->execute($variant, 3));

        $this->assertSame(7, $this->dbStock());
        $this->assertSame([['before' => 10, 'after' => 7, 'quantity' => 3]], $this->lastMovements(1));
    }
}
