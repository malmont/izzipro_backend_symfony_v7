<?php

namespace App\Services\ReviewService;

use App\Entity\ReviewsProduct;
use App\Services\TenantEntityManagerProvider;

/**
 * Modération par l'administrateur (EasyAdmin « Avis clients ») : publier, refuser (motif facultatif, montré au seul
 * auteur), répondre publiquement. On modère le contenu (insulte, données personnelles, hors sujet), jamais la note.
 */
final class ReviewModerationService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ReviewAggregateService $aggregates
    ) {
    }

    public function approve(ReviewsProduct $review): void
    {
        $review->setStatus(ReviewsProduct::STATUS_APPROVED)->setRejectionReason(null)->setPublishedAt($review->getPublishedAt() ?? new \DateTimeImmutable());
        $this->save($review);
    }

    public function reject(ReviewsProduct $review, ?string $reason = null): void
    {
        $review->setStatus(ReviewsProduct::STATUS_REJECTED)->setRejectionReason($reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : $review->getRejectionReason());
        $this->save($review);
    }

    public function reply(ReviewsProduct $review, ?string $reply): void
    {
        $this->applyReply($review, $reply);
        $this->save($review);
    }

    /**
     * Après une modification dans le formulaire d'administration : dates de publication et de réponse tenues à jour
     * (une réponse inchangée garde sa date), texte de la réponse nettoyé, moyenne du produit recalculée.
     */
    public function afterAdminEdit(ReviewsProduct $review, ?string $previousReply, ?\DateTimeImmutable $previousRepliedAt): void
    {
        if ($review->isApproved()) {
            $review->setRejectionReason(null)->setPublishedAt($review->getPublishedAt() ?? new \DateTimeImmutable());
        }
        $clean = self::cleanReply($review->getReply());
        $review->setReply($clean)->setRepliedAt(match (true) {
            $clean === null => null,
            $clean === $previousReply && $previousRepliedAt !== null => $previousRepliedAt,
            default => new \DateTimeImmutable(),
        });
        $this->save($review);
    }

    /** Après la suppression d'un avis depuis l'administration */
    public function refreshProduct(\App\Entity\Product $product): void
    {
        $this->aggregates->refresh($product);
    }

    private function applyReply(ReviewsProduct $review, ?string $reply): void
    {
        $clean = self::cleanReply($reply);
        if ($clean !== $review->getReply() || ($clean !== null && $review->getRepliedAt() === null)) {
            $review->setReply($clean)->setRepliedAt($clean !== null ? new \DateTimeImmutable() : null);
        }
    }

    /** Réponse en clair (balises retirées), 2 000 caractères au plus ; vide = pas de réponse */
    private static function cleanReply(?string $reply): ?string
    {
        $clean = $reply !== null ? trim(strip_tags($reply)) : '';

        return $clean === '' ? null : mb_substr($clean, 0, 2000);
    }

    private function save(ReviewsProduct $review): void
    {
        // updated_at reste la date de la dernière modification par l'auteur (« modifié » côté site) : la modération n'y touche pas
        $this->emProvider->getEntityManager()->flush();
        if ($review->getProductReviews() !== null) {
            $this->aggregates->refresh($review->getProductReviews());
        }
    }
}
