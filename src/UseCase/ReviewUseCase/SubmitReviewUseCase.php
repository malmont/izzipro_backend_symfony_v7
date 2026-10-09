<?php

namespace App\UseCase\ReviewUseCase;

use App\Dto\ReviewInputDto;
use App\Dto\ReviewOutputDto;
use App\Entity\User;
use App\Services\ReviewService\ReviewService;

/** Dépôt d'un avis par le client connecté (POST /api/products/{id}/reviews) : 201 { review, status } */
final class SubmitReviewUseCase
{
    public function __construct(private readonly ReviewService $reviews)
    {
    }

    /** @param array<string, mixed> $body */
    public function execute(User $user, int $productId, array $body, string $locale): array
    {
        $product = $this->reviews->product($productId);
        $review = $this->reviews->submit($user, $product, ReviewInputDto::fromArray($body), $locale);

        return ['status' => $review->getStatus(), 'review' => ReviewOutputDto::fromEntity($review, true, $locale)];
    }
}
