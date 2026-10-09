<?php

namespace App\Services\ReviewService;

use App\Dto\ReviewInputDto;
use App\Entity\Order;
use App\Entity\Product;
use App\Entity\ReviewsProduct;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;

/**
 * Avis d'un client : droit d'écrire (avis activés, achat vérifié si le site l'exige, un seul avis par produit),
 * dépôt, modification et suppression de son propre avis. Le texte est gardé en clair (balises retirées).
 */
final class ReviewService
{
    public const REASON_DISABLED = 'disabled';
    public const REASON_ALREADY_REVIEWED = 'already_reviewed';
    public const REASON_NOT_PURCHASED = 'not_purchased';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ReviewSettingsProvider $settings,
        private readonly ReviewAggregateService $aggregates
    ) {
    }

    /** @throws ReviewException 404 */
    public function product(int $id): Product
    {
        $product = $this->emProvider->getEntityManager()->getRepository(Product::class)->find($id);
        if ($product === null) {
            throw new ReviewException(404, 'Produit introuvable.');
        }

        return $product;
    }

    /**
     * @return array{canReview: bool, reason: ?string, review: ?ReviewsProduct, purchase: ?Order}
     */
    public function eligibility(User $user, Product $product): array
    {
        $em = $this->emProvider->getEntityManager();
        $settings = $this->settings->get();
        $review = $em->getRepository(ReviewsProduct::class)->findOneByProductAndUser($product, $user);
        $purchase = $em->getRepository(Order::class)->findLatestPurchaseOf($user, $product);
        $reason = match (true) {
            !$settings->isEnabled() => self::REASON_DISABLED,
            $review !== null => self::REASON_ALREADY_REVIEWED,
            $settings->isVerifiedOnly() && $purchase === null => self::REASON_NOT_PURCHASED,
            default => null,
        };

        return ['canReview' => $reason === null, 'reason' => $reason, 'review' => $review, 'purchase' => $purchase];
    }

    /** @throws ReviewException 403 avis désactivés ou achat exigé, 409 avis déjà déposé, 422 contenu */
    public function submit(User $user, Product $product, ReviewInputDto $input, string $locale): ReviewsProduct
    {
        $eligibility = $this->eligibility($user, $product);
        if (!$eligibility['canReview']) {
            throw match ($eligibility['reason']) {
                self::REASON_ALREADY_REVIEWED => new ReviewException(409, 'Vous avez déjà donné votre avis sur ce produit : modifiez-le.', [], self::REASON_ALREADY_REVIEWED),
                self::REASON_NOT_PURCHASED => new ReviewException(403, 'Seuls les clients qui ont acheté ce produit peuvent donner leur avis.', [], self::REASON_NOT_PURCHASED),
                default => new ReviewException(403, 'Les avis sont désactivés sur ce site.', [], self::REASON_DISABLED),
            };
        }
        $this->checkLength($input);
        $settings = $this->settings->get();
        $review = (new ReviewsProduct())
            ->setProductReviews($product)->setUserReview($user)->setOrder($eligibility['purchase'])
            ->setVerifiedPurchase($eligibility['purchase'] !== null)
            ->setRating((int) $input->rating)->setTitle($input->title)->setBody($input->body)
            ->setAuthorName(self::authorName($user))->setLocale($locale);
        $this->moderate($review, $settings->isAutoPublish());
        $em = $this->emProvider->getEntityManager();
        $em->persist($review);
        $em->flush();
        if ($review->isApproved()) {
            $this->aggregates->refresh($product);
        }

        return $review;
    }

    /** @throws ReviewException 404 (avis d'un autre client), 422 contenu */
    public function ownedBy(int $id, User $user): ReviewsProduct
    {
        $review = $this->emProvider->getEntityManager()->getRepository(ReviewsProduct::class)->find($id);
        if ($review === null || $review->getUserReview()?->getId() !== $user->getId()) {
            throw new ReviewException(404, 'Avis introuvable.');
        }

        return $review;
    }

    /** Modification par son auteur : champs envoyés seulement ; repasse en modération si le site modère */
    public function update(ReviewsProduct $review, ReviewInputDto $input): ReviewsProduct
    {
        if (!$this->settings->get()->isEnabled()) {
            throw new ReviewException(403, 'Les avis sont désactivés sur ce site.', [], self::REASON_DISABLED);
        }
        $merged = new ReviewInputDto($input->rating ?? $review->getRating(), $input->hasTitle ? $input->title : $review->getTitle(), $input->body ?? (string) $review->getBody());
        $this->checkLength($merged);
        $wasApproved = $review->isApproved();
        $review->setRating((int) $merged->rating)->setTitle($merged->title)->setBody($merged->body)->touch();
        $this->moderate($review, $this->settings->get()->isAutoPublish());
        $this->emProvider->getEntityManager()->flush();
        if ($wasApproved || $review->isApproved()) {
            $this->aggregates->refresh($review->getProductReviews());
        }

        return $review;
    }

    public function delete(ReviewsProduct $review): void
    {
        $product = $review->getProductReviews();
        $wasApproved = $review->isApproved();
        $em = $this->emProvider->getEntityManager();
        $em->remove($review);
        $em->flush();
        if ($wasApproved && $product !== null) {
            $this->aggregates->refresh($product);
        }
    }

    /** @return list<ReviewsProduct> */
    public function mine(User $user): array
    {
        return $this->emProvider->getEntityManager()->getRepository(ReviewsProduct::class)->findByUser($user);
    }

    /** Nom public : prénom et initiale du nom (« Marie D. »), sinon « Client » ; jamais l'adresse électronique */
    public static function authorName(User $user): string
    {
        $first = trim((string) $user->getFirstname());
        $last = trim((string) $user->getLastname());
        if ($first === '') {
            return 'Client';
        }

        return mb_substr($first, 0, 40) . ($last !== '' ? ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.' : '');
    }

    private function moderate(ReviewsProduct $review, bool $autoPublish): void
    {
        $review->setRejectionReason(null);
        if ($autoPublish) {
            $review->setStatus(ReviewsProduct::STATUS_APPROVED)->setPublishedAt($review->getPublishedAt() ?? new \DateTimeImmutable());
        } else {
            $review->setStatus(ReviewsProduct::STATUS_PENDING);
        }
    }

    private function checkLength(ReviewInputDto $input): void
    {
        $min = $this->settings->get()->getMinLength();
        if (mb_strlen((string) $input->body) < $min) {
            throw new ReviewException(422, sprintf('body : %d caractères au moins', $min), [['path' => 'body', 'message' => sprintf('%d caractères au moins', $min)]]);
        }
    }
}
