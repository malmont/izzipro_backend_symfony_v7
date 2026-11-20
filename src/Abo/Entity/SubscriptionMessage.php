<?php

namespace App\Abo\Entity;

use App\Entity\Subscription;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fil de l'abonné (lot ABO-3b, 20/11/2026, `docs/boutique.md` § « Intervenant et fil de l'abonné ») : messages entre l'abonné et l'équipe,
 * rattachés à l'abonnement, sans dépendre du module Assistance. Texte brut ; écrit par `AboThreadService` seulement. Effacé 24 mois après
 * la fin de l'abonnement (`app:abo:periodes`). Jamais rendu à l'intervenant ; au modèle, masqué et sans nom.
 */
#[ORM\Entity]
#[ORM\Table(name: 'subscription_message')]
#[ORM\Index(name: 'idx_subscription_message_thread', columns: ['subscription_id', 'id'])]
#[ORM\Index(name: 'idx_subscription_message_unread', columns: ['author', 'read_at'])]
class SubscriptionMessage
{
    public const AUTHOR_CUSTOMER = 'customer';
    public const AUTHOR_TEAM = 'team';
    public const BODY_MAX = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(name: 'subscription_id', nullable: false, onDelete: 'CASCADE')]
    private ?Subscription $subscription = null;

    /** `customer` ou `team` */
    #[ORM\Column(length: 10)]
    private string $author;

    #[ORM\Column(name: 'author_user_id', nullable: true)]
    private ?int $authorUserId = null;

    /** Le prénom affiché (l'abonné voit le prénom de la personne de l'équipe ; l'équipe voit celui de l'abonné) */
    #[ORM\Column(name: 'author_name', length: 100, nullable: true)]
    private ?string $authorName = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    /** Lot ABO-3b : la contestation d'un constat (ABO-3a) écrite dans le fil ; null pour un message ordinaire */
    #[ORM\Column(name: 'occurrence_id', nullable: true)]
    private ?int $occurrenceId = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Lu par l'autre partie (l'équipe pour un message de l'abonné, l'abonné pour un message de l'équipe) */
    #[ORM\Column(name: 'read_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(Subscription $subscription, string $author, string $body, ?int $authorUserId, ?string $authorName, ?int $occurrenceId = null)
    {
        $this->subscription = $subscription;
        $this->author = $author;
        $this->body = $body;
        $this->authorUserId = $authorUserId;
        $this->authorName = $authorName;
        $this->occurrenceId = $occurrenceId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSubscription(): ?Subscription { return $this->subscription; }
    public function getAuthor(): string { return $this->author; }
    public function getAuthorUserId(): ?int { return $this->authorUserId; }
    public function getAuthorName(): ?string { return $this->authorName; }
    public function getBody(): string { return $this->body; }
    public function getOccurrenceId(): ?int { return $this->occurrenceId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }
}
