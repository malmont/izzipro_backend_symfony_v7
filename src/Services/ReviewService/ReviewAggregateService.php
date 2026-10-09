<?php

namespace App\Services\ReviewService;

use App\Entity\Product;
use App\Entity\ReviewsProduct;
use App\Services\TenantCacheService;
use App\Services\TenantEntityManagerProvider;

/**
 * Moyenne et nombre d'avis publiés gardés sur le produit (product.rating_average, rating_count), recalculés à chaque
 * publication, retrait, modification ou suppression : les listes de produits les lisent sans requête de plus.
 */
final class ReviewAggregateService
{
    /** Caches des listes de produits qui exposent rating et reviewCount */
    private const PRODUCT_TAGS = ['products_all', 'products_by_category', 'products_by_offer', 'products_command'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantCacheService $cache
    ) {
    }

    public function refresh(Product $product): void
    {
        $em = $this->emProvider->getEntityManager();
        $summary = $em->getRepository(ReviewsProduct::class)->summary($product);
        $product->setRatingAverage($summary['average'])->setRatingCount($summary['count']);
        $em->flush();
        $this->cache->invalidateTags(self::PRODUCT_TAGS);
    }
}
