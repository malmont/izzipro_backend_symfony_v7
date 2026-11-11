<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Order;
use App\Entity\OrderSource;
use App\Entity\OrderType;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\StatusCommande;
use App\Services\StockEvolutionService\StockValueService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use App\UseCase\StatistiqueUseCase\PaymentUseCase\GetPaymentsStatisticsUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Correctifs des statistiques demandés par l'application de gestion (11/11/2026, `docs/gestion-mise-a-niveau.md`) : B1 valeur du stock en cents
 * par variante, mois en numéro au fuseau du magasin (champs additifs) ; B6 paiements réussis seulement. Écarts avant / après (base partagée).
 */
class StatisticsFixesTest extends KernelTestCase
{
    use CacheTestEntityFactory;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement("DELETE FROM payments WHERE order_payment_id IN (SELECT id FROM \"order\" WHERE reference = 'STAT-B6')");
        $conn->executeStatement("DELETE FROM \"order\" WHERE reference = 'STAT-B6'");
        $conn->executeStatement("DELETE FROM product_variant WHERE product_id IN (SELECT id FROM product WHERE slug LIKE 'stat-b1-%')");
        $conn->executeStatement("DELETE FROM product WHERE slug LIKE 'stat-b1-%'");
        parent::tearDown();
    }

    public function testStockValueCentsByVariant(): void
    {
        $service = static::getContainer()->get(StockValueService::class);
        $before = $service->getStockValueCurrentMonth()['stock_value_current_month'][0];
        $conn = $this->em()->getConnection();
        // A : 10,00 $, une variante qui hérite (stock 3) et une à 25,00 $ (stock 2), quantité de l'article recalculée par ses variantes ; B : sans variante, 4 × 5,00 $
        $a = $this->product(1000, 99);
        foreach ([[null, 3], [2500, 2], [null, 0]] as [$price, $stock]) {
            $v = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $a)])->getId();
            $conn->executeStatement('UPDATE product_variant SET price = ?, stock_quantity = ? WHERE id = ?', [$price, $stock, $v]);
        }
        $this->product(500, 4);
        $after = $service->getStockValueCurrentMonth()['stock_value_current_month'][0];
        $this->assertSame(3 * 1000 + 2 * 2500 + 4 * 500, $after['stock_value_cents'] - $before['stock_value_cents']);
        $this->assertIsInt($after['stock_value_cents']);
        // ancien champ inchangé : dollars, quantité de l'article (recalculée par ses variantes : 5) × prix de l'article, prix propre de la variante ignoré
        $this->assertEqualsWithDelta((5 * 1000 + 4 * 500) / 100, $after['stock_value'] - $before['stock_value'], 0.001);
        $this->assertSame([true, true], [$after['month_number'] >= 1 && $after['month_number'] <= 12, $after['year'] >= 2026]);
        $this->assertArrayHasKey('month', $after);
    }

    public function testPaymentStatisticsCountCompletedOnly(): void
    {
        $useCase = static::getContainer()->get(GetPaymentsStatisticsUseCase::class);
        $total = fn () => (float) ($useCase->execute(2)['current_week']['PaiementClient']['total'] ?? 0);
        $before = $total();
        $order = $this->create(Order::class, ['totalAmount' => 6000.0, 'reference' => 'STAT-B6'], function (Order $o, EntityManagerInterface $em): void {
            $o->setOrderDate(new \DateTime())->setStatus($em->find(StatusCommande::class, 3))->setOrderType($em->find(OrderType::class, 1))->setOrderSource($em->find(OrderSource::class, 2));
        });
        $conn = $this->em()->getConnection();
        foreach ([[2, 1000], [3, 5000], [1, 700]] as [$status, $amount]) { // réussi, échoué, en cours
            $conn->executeStatement("INSERT INTO payments (id, order_payment_id, payment_method_id, statut_payment_id, payment_type_id, amount, payment_date) VALUES (nextval('payments_id_seq'), ?, 2, ?, 2, ?, NOW())", [$order->getId(), $status, $amount]);
        }
        $this->assertEqualsWithDelta(1000.0, $total() - $before, 0.001, 'seul le paiement réussi compte');
    }

    private function product(int $price, int $quantity): int
    {
        return (int) $this->em()->getConnection()->fetchOne("INSERT INTO product (id, name, price, isbestseller, isfeatured, isnewarrival, isspecialoffer, is_accessory, is_landing_page, quantity, created_at, slug, product_type, sale_enabled, is_web, is_pos) VALUES (nextval('product_id_seq'), 'Stat B1', ?, false, false, false, false, false, false, ?, NOW(), ?, 'standard', true, true, true) RETURNING id",
            [$price, $quantity, 'stat-b1-' . bin2hex(random_bytes(4))]);
    }

    protected function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }
}
