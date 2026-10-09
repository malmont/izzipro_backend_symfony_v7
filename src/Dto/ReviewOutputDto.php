<?php

namespace App\Dto;

use App\Entity\ReviewsProduct;

/**
 * Avis tel que le site l'affiche : note, titre, texte, auteur (« Marie D. »), achat vérifié, date de publication,
 * réponse du commerçant. Pour son auteur (« Mes avis ») : en plus le statut, le motif d'un refus et le produit.
 */
final class ReviewOutputDto
{
    /**
     * @param ?array{body: string, date: ?string} $reply
     * @param ?array{id: int, name: ?string} $product
     */
    public function __construct(
        public readonly int $id,
        public readonly int $rating,
        public readonly ?string $title,
        public readonly string $body,
        public readonly string $author,
        public readonly bool $verifiedPurchase,
        public readonly string $date,
        public readonly ?string $updatedAt,
        public readonly string $locale,
        public readonly ?array $reply,
        public readonly ?string $status = null,
        public readonly ?string $rejectionReason = null,
        public readonly ?array $product = null
    ) {
    }

    public static function fromEntity(ReviewsProduct $review, bool $forAuthor = false, string $locale = 'fr'): self
    {
        $product = $review->getProductReviews();
        $edited = $review->getUpdatedAt() > ($review->getPublishedAt() ?? $review->getCreatedAt())->modify('+1 minute');

        return new self(
            (int) $review->getId(),
            $review->getRating(),
            $review->getTitle(),
            (string) $review->getBody(),
            $review->getAuthorName(),
            $review->isVerifiedPurchase(),
            ($review->getPublishedAt() ?? $review->getCreatedAt())->format(\DateTimeInterface::ATOM),
            $edited ? $review->getUpdatedAt()->format(\DateTimeInterface::ATOM) : null,
            $review->getLocale(),
            $review->getReply() !== null ? ['body' => $review->getReply(), 'date' => $review->getRepliedAt()?->format(\DateTimeInterface::ATOM)] : null,
            $forAuthor ? $review->getStatus() : null,
            $forAuthor ? $review->getRejectionReason() : null,
            $forAuthor && $product !== null ? ['id' => (int) $product->getId(), 'name' => $product->getTranslation($locale)?->getName() ?? $product->getName()] : null
        );
    }
}
