<?php

namespace App\UseCase\ReviewUseCase;

use App\Dto\ReviewInputDto;
use App\Dto\ReviewOutputDto;
use App\Entity\User;
use App\Services\ReviewService\ReviewService;

/** Avis du client connecté : liste (« Mes avis »), modification, suppression. L'avis d'un autre client répond 404. */
final class ManageOwnReviewUseCase
{
    public function __construct(private readonly ReviewService $reviews)
    {
    }

    /** @return list<ReviewOutputDto> */
    public function mine(User $user, string $locale): array
    {
        return array_map(fn ($r) => ReviewOutputDto::fromEntity($r, true, $locale), $this->reviews->mine($user));
    }

    /** @param array<string, mixed> $body */
    public function update(User $user, int $id, array $body, string $locale): array
    {
        $review = $this->reviews->update($this->reviews->ownedBy($id, $user), ReviewInputDto::fromArray($body, true));

        return ['status' => $review->getStatus(), 'review' => ReviewOutputDto::fromEntity($review, true, $locale)];
    }

    public function delete(User $user, int $id): void
    {
        $this->reviews->delete($this->reviews->ownedBy($id, $user));
    }
}
