<?php

namespace App\UseCase\ReviewUseCase;

use App\Dto\ReviewOutputDto;
use App\Entity\User;
use App\Services\ReviewService\ReviewService;
use App\Services\ReviewService\ReviewSettingsProvider;

/**
 * Le client connecté peut-il donner son avis sur ce produit ? (GET /api/products/{id}/reviews/eligibility)
 * { canReview, reason: null | disabled | already_reviewed | not_purchased, verifiedPurchase, minLength, review }.
 */
final class GetReviewEligibilityUseCase
{
    public function __construct(private readonly ReviewService $reviews, private readonly ReviewSettingsProvider $settings)
    {
    }

    public function execute(User $user, int $productId, string $locale): array
    {
        $eligibility = $this->reviews->eligibility($user, $this->reviews->product($productId));

        return [
            'canReview' => $eligibility['canReview'],
            'reason' => $eligibility['reason'],
            'verifiedPurchase' => $eligibility['purchase'] !== null,
            'minLength' => $this->settings->get()->getMinLength(),
            'review' => $eligibility['review'] ? ReviewOutputDto::fromEntity($eligibility['review'], true, $locale) : null,
        ];
    }
}
