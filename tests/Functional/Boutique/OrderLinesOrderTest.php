<?php

namespace App\Tests\Functional\Boutique;

use App\Dto\PosReceiptDto;
use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\OrderSource;
use App\Entity\OrderType;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\StatusCommande;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot REÇU-ORDRE (13/11/2026, `docs/caisse.md`) : les lignes d'une commande se lisent toujours dans l'ordre de saisie (identifiant
 * croissant de la ligne). Avant, aucun ordre n'était demandé à la base : après la mise à jour d'une ligne, le reçu, la fiche de commande
 * et les exports pouvaient lister les lignes dans un autre ordre (constaté dans la suite de tests le 13/11/2026 : deux lignes d'un reçu
 * inversées). Le test provoque ce désordre exprès : il retire la première ligne et la remet avec le même identifiant, que la base range
 * alors après les autres, et vérifie d'abord que le désordre est bien là.
 */
class OrderLinesOrderTest extends WebTestCase
{
    use CacheTestEntityFactory;

    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        static::createClient();
    }

    protected function tearDown(): void
    {
        $conn = $this->em()->getConnection();
        foreach (array_reverse($this->cleanup) as [$sql, $params]) {
            $conn->executeStatement($sql, $params);
        }
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
        parent::tearDown();
    }

    public function testTheLinesOfAnOrderAreAlwaysReadInTheOrderTheyWereEntered(): void
    {
        $tag = bin2hex(random_bytes(3));
        $conn = $this->em()->getConnection();
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type) VALUES (nextval('product_id_seq'), :n, 1000, false, 5, NOW(), :s, 'standard')", ['n' => "Ordre $tag", 's' => "ordre-$tag"]);
        $productId = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', ["ordre-$tag"]);
        $this->cleanup[] = ['DELETE FROM product WHERE id = ?', [$productId]];
        $variants = [];
        foreach ([1, 2, 3, 4] as $n) {
            $variants[] = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $productId)])->getId();
            $this->cleanup[] = ['DELETE FROM product_variant WHERE id = ?', [end($variants)]];
        }
        $order = $this->create(Order::class, ['totalAmount' => 100.0], function (Order $o, EntityManagerInterface $em): void {
            $o->setOrderDate(new \DateTime())->setStatus($em->find(StatusCommande::class, 3))->setOrderType($em->find(OrderType::class, 1))->setOrderSource($em->find(OrderSource::class, 2));
        });
        $orderId = (int) $order->getId();
        $this->cleanup[] = ['DELETE FROM "order" WHERE id = ?', [$orderId]];
        $this->cleanup[] = ['DELETE FROM order_items WHERE order_associated_id = ?', [$orderId]];
        $lineIds = [];
        foreach ($variants as $i => $variantId) {
            $lineIds[] = (int) $this->create(OrderItems::class, ['quantity' => $i + 1, 'unitPrice' => 1000.0, 'totalPrice' => 1000.0 * ($i + 1)], function (OrderItems $item, EntityManagerInterface $em) use ($orderId, $variantId): void {
                $item->setOrderAssociated($em->find(Order::class, $orderId))->setProductVariant($em->find(ProductVariant::class, $variantId));
            })->getId();
        }
        $this->assertSame($lineIds, array_values(array_unique($lineIds)));

        // Le désordre, exprès : la première ligne est retirée puis remise avec le même identifiant, la base la range après les autres
        $conn = $this->em()->getConnection();
        $first = $conn->fetchAssociative('SELECT * FROM order_items WHERE id = ?', [$lineIds[0]]);
        $conn->executeStatement('DELETE FROM order_items WHERE id = ?', [$lineIds[0]]);
        $conn->insert('order_items', array_map(fn ($v) => is_bool($v) ? ($v ? 't' : 'f') : $v, $first));
        $physical = array_map('intval', $conn->fetchFirstColumn('SELECT id FROM order_items WHERE order_associated_id = ?', [$orderId]));
        $this->assertNotSame($lineIds, $physical, 'sans ordre demandé, la base ne rend plus les lignes dans l\'ordre de saisie');
        $this->assertEqualsCanonicalizing($lineIds, $physical);

        // La commande relue : ses lignes sont dans l'ordre de saisie, sur l'entité comme sur le reçu
        $reloaded = $this->em()->find(Order::class, $orderId);
        $this->assertSame($lineIds, array_values(array_map(fn (OrderItems $i) => (int) $i->getId(), $reloaded->getOrderItems()->toArray())));
        $receipt = PosReceiptDto::fromOrder($reloaded, null, null, 'fr');
        $this->assertSame($variants, array_column($receipt['lines'], 'productVariantId'), 'le reçu liste les lignes dans l\'ordre de saisie');
        $this->assertSame([1, 2, 3, 4], array_map('intval', array_column($receipt['lines'], 'quantity')));
    }

    protected function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }
}
