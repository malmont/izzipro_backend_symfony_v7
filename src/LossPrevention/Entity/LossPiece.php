<?php

namespace App\LossPrevention\Entity;

use App\LossPrevention\Repository\LossPieceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pièce (lot L2-a, `docs/prevention-pertes.md` § 13) : un exemplaire physique d'une variante, à numéro unique sur le site (les pièces
 * d'exercice ont leur propre suite de numéros), qui porte
 * une ou plusieurs puces. Elle ne remplace pas le stock et n'a aucune clé étrangère vers le catalogue. Son état ne change que par un
 * fait (`loss_piece_event`). Lue et écrite en SQL par son dépôt ; la base fige son identité et refuse sa suppression (sauf exercice).
 */
#[ORM\Entity(repositoryClass: LossPieceRepository::class)]
#[ORM\Table(name: 'loss_piece')]
#[ORM\UniqueConstraint(name: 'uniq_loss_piece_kind_number', columns: ['exercise', 'number'])]
#[ORM\Index(name: 'idx_loss_piece_variant', columns: ['variant_id', 'status'])]
#[ORM\Index(name: 'idx_loss_piece_exercise', columns: ['id'], options: ['where' => 'exercise'])]
class LossPiece
{
    public const CREATED = 'created';
    public const RETIRED = 'retired';
    /** Reliée à une vente par l'analyse (lot L3-b2a) ; revenue (retour, vente annulée) : de nouveau vendable */
    public const SOLD = 'sold';
    public const RETURNED = 'returned';
    /** États de la pièce ; `sold` et `returned` viennent avec la vente reliée aux pièces (L3-b2a), les autres avec les lots suivants (L4) */
    public const STATUSES = [self::CREATED, 'in_stockroom', 'on_floor', 'at_counter', self::SOLD, self::RETURNED, 'missing', self::RETIRED];
    /** Motifs du retrait d'une pièce */
    public const RETIRE_REASONS = ['damaged', 'lost', 'sold', 'error'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(name: 'variant_id')]
    private int $variantId;

    #[ORM\Column(name: 'product_id')]
    private int $productId;

    #[ORM\Column(length: 16, options: ['default' => 'created'])]
    private string $status = self::CREATED;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $zone = null;

    #[ORM\Column(name: 'commande_id', nullable: true)]
    private ?int $commandeId = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $exercise = false;

    #[ORM\Column(name: 'retired_reason', length: 20, nullable: true)]
    private ?string $retiredReason = null;

    #[ORM\Column(name: 'retired_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $retiredAt = null;

    #[ORM\Column(name: 'created_by', nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function getId(): ?int { return $this->id; }
    public function getNumber(): int { return $this->number; }
    public function getVariantId(): int { return $this->variantId; }
    public function getProductId(): int { return $this->productId; }
    public function getStatus(): string { return $this->status; }
    public function isExercise(): bool { return $this->exercise; }
}
