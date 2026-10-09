<?php

namespace App\Entity;

use App\Repository\ReviewsProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Avis d'un client sur un produit (09/10/2026) : une note de 1 à 5, un titre facultatif, un texte en clair. Un seul avis
 * par client et par produit. Publié après modération (ou aussitôt si le site l'a réglé), avec le badge « Achat vérifié »
 * quand une commande payée du client contient le produit. Colonnes historiques
 * gardées : note (= rating), comment (= body).
 */
#[ORM\Entity(repositoryClass: ReviewsProductRepository::class)]
#[ORM\Table(name: 'reviews_product')]
#[ORM\UniqueConstraint(name: 'uniq_review_product_user', columns: ['product_reviews_id', 'user_review_id'])]
#[ORM\Index(name: 'idx_review_product_status', columns: ['product_reviews_id', 'status'])]
class ReviewsProduct
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Note de 1 à 5 (colonne historique « note ») */
    #[ORM\Column(name: 'note')]
    private int $rating = 5;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $title = null;

    /** Texte de l'avis, en clair (colonne historique « comment ») */
    #[ORM\Column(name: 'comment', type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    #[ORM\ManyToOne(inversedBy: 'reviewsProducts')]
    #[ORM\JoinColumn(name: 'user_review_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $userReview = null;

    #[ORM\ManyToOne(inversedBy: 'reviewsProducts')]
    #[ORM\JoinColumn(name: 'product_reviews_id', nullable: false, onDelete: 'CASCADE')]
    private ?Product $productReviews = null;

    /** Commande qui prouve l'achat (null : avis sans achat vérifié) */
    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', nullable: true, onDelete: 'SET NULL')]
    private ?Order $order = null;

    #[ORM\Column(length: 10, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'verified_purchase', options: ['default' => false])]
    private bool $verifiedPurchase = false;

    /** Nom affiché, figé au dépôt : prénom et initiale du nom (« Marie D. ») */
    #[ORM\Column(name: 'author_name', length: 60, options: ['default' => ''])]
    private string $authorName = '';

    #[ORM\Column(length: 5, options: ['default' => 'fr'])]
    private string $locale = 'fr';

    /** Motif d'un refus (interne, montré au seul auteur dans « Mes avis ») */
    #[ORM\Column(name: 'rejection_reason', length: 255, nullable: true)]
    private ?string $rejectionReason = null;

    /** Réponse publique du commerçant */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reply = null;

    #[ORM\Column(name: 'replied_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $repliedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function __toString(): string
    {
        return sprintf('%s, %d/5 — %s', $this->productReviews?->getName() ?? 'Produit', $this->rating, $this->authorName);
    }

    public function getId(): ?int { return $this->id; }

    public function getRating(): int { return $this->rating; }
    public function setRating(int $rating): static { $this->rating = $rating; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): static { $this->title = $title; return $this; }

    public function getBody(): ?string { return $this->body; }
    public function setBody(?string $body): static { $this->body = $body; return $this; }

    public function getUserReview(): ?User { return $this->userReview; }
    public function setUserReview(?User $user): static { $this->userReview = $user; return $this; }

    public function getProductReviews(): ?Product { return $this->productReviews; }
    public function setProductReviews(?Product $product): static { $this->productReviews = $product; return $this; }

    public function getOrder(): ?Order { return $this->order; }
    public function setOrder(?Order $order): static { $this->order = $order; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function isApproved(): bool { return $this->status === self::STATUS_APPROVED; }

    public function isVerifiedPurchase(): bool { return $this->verifiedPurchase; }
    public function setVerifiedPurchase(bool $verified): static { $this->verifiedPurchase = $verified; return $this; }

    public function getAuthorName(): string { return $this->authorName; }
    public function setAuthorName(string $name): static { $this->authorName = $name; return $this; }

    public function getLocale(): string { return $this->locale; }
    public function setLocale(string $locale): static { $this->locale = $locale; return $this; }

    public function getRejectionReason(): ?string { return $this->rejectionReason; }
    public function setRejectionReason(?string $reason): static { $this->rejectionReason = $reason; return $this; }

    public function getReply(): ?string { return $this->reply; }
    public function setReply(?string $reply): static { $this->reply = $reply; return $this; }

    public function getRepliedAt(): ?\DateTimeImmutable { return $this->repliedAt; }
    public function setRepliedAt(?\DateTimeImmutable $at): static { $this->repliedAt = $at; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $at): static { $this->createdAt = $at; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(?\DateTimeImmutable $at): static { $this->publishedAt = $at; return $this; }

}
