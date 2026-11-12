<?php

namespace App\Services\ProductService;

use App\Entity\CheckoutSession;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Gestion\Services\GestionConflictException;
use Doctrine\DBAL\Connection;

/**
 * Article ou variante qui a une histoire (ART-SUPPR, 13/11/2026) : ventes, lignes d'achat, mouvements de stock, réservations, formules
 * d'abonnement, paiements en cours. La suppression est refusée, 409 `PRODUCT_IN_USE { productId, variantId?, uses }` : on retire l'article
 * de la vente au lieu de le supprimer. Avant, DELETE /api/products/{id} supprimait en silence les lignes d'achat (commande_line, ON DELETE
 * CASCADE) et l'historique de stock (cascade Doctrine), ou sortait en 500 avec l'erreur SQL.
 */
final class ProductUsageGuard
{
    public const MESSAGE = 'Cet article a des ventes, des achats ou des mouvements de stock : retirez-le de la vente au lieu de le supprimer.';
    public const VARIANT_MESSAGE = 'Cette variante a des ventes, des achats ou des mouvements de stock : mettez son stock à 0 au lieu de la supprimer.';

    /** @return array{sales: int, purchaseLines: int, stockMovements: int, bookings: int, subscriptionPlans: int, openCheckouts: int} */
    public static function productUses(Connection $conn, int $productId): array
    {
        $count = fn (string $sql) => (int) $conn->fetchOne($sql, [$productId]);
        $variants = array_map('intval', $conn->fetchFirstColumn('SELECT id FROM product_variant WHERE product_id = ?', [$productId]));

        return [
            'sales' => $count('SELECT COUNT(*) FROM order_items oi JOIN product_variant v ON v.id = oi.product_variant_id WHERE v.product_id = ?'),
            'purchaseLines' => $count('SELECT COUNT(*) FROM commande_line WHERE product_id = ?'),
            'stockMovements' => $count('SELECT COUNT(*) FROM inventory_movements m JOIN product_variant v ON v.id = m.product_variant_id WHERE v.product_id = ?'),
            'bookings' => $count('SELECT COUNT(*) FROM booking WHERE product_id = ?'),
            'subscriptionPlans' => $count('SELECT COUNT(*) FROM subscription_plan WHERE product_id = ?'),
            'openCheckouts' => self::openCheckouts($conn, $variants),
        ];
    }

    /** @return array{sales: int, purchaseLines: int, stockMovements: int, bookings: int, subscriptionPlans: int, openCheckouts: int} */
    public static function variantUses(Connection $conn, int $variantId): array
    {
        $count = fn (string $sql) => (int) $conn->fetchOne($sql, [$variantId]);

        return [
            'sales' => $count('SELECT COUNT(*) FROM order_items WHERE product_variant_id = ?'),
            'purchaseLines' => $count('SELECT COUNT(*) FROM commande_line WHERE product_variant_id = ?'),
            'stockMovements' => $count('SELECT COUNT(*) FROM inventory_movements WHERE product_variant_id = ?'),
            'bookings' => 0, 'subscriptionPlans' => 0, // portés par l'article
            'openCheckouts' => self::openCheckouts($conn, [$variantId]),
        ];
    }

    public static function assertProductDeletable(Connection $conn, Product $product): void
    {
        $uses = self::productUses($conn, (int) $product->getId());
        if (array_sum($uses) > 0) {
            throw new GestionConflictException('PRODUCT_IN_USE', self::MESSAGE, 409, ['productId' => (int) $product->getId(), 'uses' => $uses]);
        }
    }

    public static function assertVariantDeletable(Connection $conn, ProductVariant $variant): void
    {
        $uses = self::variantUses($conn, (int) $variant->getId());
        if (array_sum($uses) > 0) {
            throw new GestionConflictException('PRODUCT_IN_USE', self::VARIANT_MESSAGE, 409, ['productId' => $variant->getProduct()?->getId(), 'variantId' => (int) $variant->getId(), 'uses' => $uses]);
        }
    }

    /** Paiements en cours (checkout_session ouverte ou en traitement) dont le panier contient une de ces variantes */
    private static function openCheckouts(Connection $conn, array $variantIds): int
    {
        if ($variantIds === []) {
            return 0;
        }

        return (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM checkout_session WHERE status IN (?, ?) AND cart::text ~ ?',
            [CheckoutSession::STATUS_OPEN, CheckoutSession::STATUS_PROCESSING, '"productVariantId"\s*:\s*"?(' . implode('|', array_map('intval', $variantIds)) . ')\y'] // \y : limite de mot de PostgreSQL
        );
    }
}
