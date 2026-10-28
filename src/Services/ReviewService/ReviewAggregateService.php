<?php

namespace App\Services\ReviewService;

use App\Entity\Product;
use App\Entity\ReviewsProduct;
use App\Services\TenantEntityManagerProvider;

/**
 * Moyenne et nombre d'avis publiés gardés sur le produit (product.rating_average, rating_count), recalculés à chaque
 * publication, retrait, modification ou suppression : les listes de produits les lisent sans requête de plus, et
 * l'écriture du produit vide leur cache (registre CacheTags, 28/10/2026).
 */
final class ReviewAggregateService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    public function refresh(Product $product): void
    {
        $em = $this->emProvider->getEntityManager();
        $summary = $em->getRepository(ReviewsProduct::class)->summary($product);
        $product->setRatingAverage($summary['average'])->setRatingCount($summary['count']);
        $em->flush();
    }
}
