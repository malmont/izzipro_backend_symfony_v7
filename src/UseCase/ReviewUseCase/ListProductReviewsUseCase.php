<?php

namespace App\UseCase\ReviewUseCase;

use App\Dto\ReviewOutputDto;
use App\Entity\ReviewsProduct;
use App\Repository\ReviewsProductRepository;
use App\Services\ReviewService\ReviewService;
use App\Services\ReviewService\ReviewSettingsProvider;
use App\Services\TenantEntityManagerProvider;

/**
 * Avis publiés d'un produit (GET /api/products/{id}/reviews) : résumé (moyenne, nombre, répartition 5 → 1), politique
 * des avis du site, page d'avis. Avis désactivés : liste vide, enabled = false.
 */
final class ListProductReviewsUseCase
{
    public const PER_PAGE_MAX = 50;

    public function __construct(
        private readonly ReviewService $reviews,
        private readonly ReviewSettingsProvider $settings,
        private readonly TenantEntityManagerProvider $emProvider
    ) {
    }

    /** @param array<string, mixed> $query page, perPage, sort (recent | highest | lowest), rating (1-5) */
    public function execute(int $productId, array $query, string $locale): array
    {
        $product = $this->reviews->product($productId);
        $settings = $this->settings->get();
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(self::PER_PAGE_MAX, (int) ($query['perPage'] ?? 10)));
        $sort = in_array($query['sort'] ?? null, ReviewsProductRepository::SORTS, true) ? $query['sort'] : 'recent';
        $rating = isset($query['rating']) && in_array((int) $query['rating'], [1, 2, 3, 4, 5], true) ? (int) $query['rating'] : null;

        /** @var ReviewsProductRepository $repository */
        $repository = $this->emProvider->getEntityManager()->getRepository(ReviewsProduct::class);
        $summary = $settings->isEnabled() ? $repository->summary($product) : ['average' => null, 'count' => 0, 'distribution' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0]];
        $result = $settings->isEnabled() ? $repository->findPublished($product, $page, $perPage, $sort, $rating) : ['items' => [], 'total' => 0];

        return [
            'enabled' => $settings->isEnabled(),
            'summary' => ['average' => $summary['average'] !== null ? round($summary['average'], 1) : null, 'count' => $summary['count'], 'distribution' => array_map('intval', $summary['distribution'])],
            'policy' => $settings->policyFor($locale),
            'verifiedOnly' => $settings->isVerifiedOnly(),
            'items' => array_map(fn (ReviewsProduct $r) => ReviewOutputDto::fromEntity($r), $result['items']),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $result['total'],
            'pages' => (int) ceil($result['total'] / $perPage),
            'sort' => $sort,
            'rating' => $rating,
        ];
    }
}
