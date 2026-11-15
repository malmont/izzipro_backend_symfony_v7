<?php

namespace App\LossPrevention\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Puce (lot L2-a, `docs/prevention-pertes.md` § 13) : un identifiant lu par un lecteur, unique sur le site (index unique), posé sur une
 * pièce. Cycle de vie : `known` (lue à l'essai, pas encore posée) → `associated` → `retired`. La base garantit qu'une puce associée ne
 * change jamais de pièce, qu'une puce retirée ne revient pas, et qu'une puce simulée est une puce d'exercice. Lue et écrite en SQL par
 * `LossPieceRepository`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'loss_tag')]
#[ORM\UniqueConstraint(name: 'uniq_loss_tag_identifier', columns: ['identifier'])]
#[ORM\Index(name: 'idx_loss_tag_piece', columns: ['piece_id'])]
#[ORM\Index(name: 'idx_loss_tag_exercise', columns: ['id'], options: ['where' => 'exercise'])]
class LossTag
{
    public const KNOWN = 'known';
    public const ASSOCIATED = 'associated';
    public const RETIRED = 'retired';
    /** Motifs du retrait d'une puce */
    public const RETIRE_REASONS = ['detached', 'destroyed', 'lost', 'error'];
    /** Puces actives qu'une pièce peut porter au plus (une paire, un lot) */
    public const MAX_PER_PIECE = 4;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $identifier;

    #[ORM\Column(length: 128)]
    private string $raw;

    #[ORM\Column(length: 12)]
    private string $technology;

    #[ORM\Column(length: 12)]
    private string $status;

    #[ORM\Column(name: 'piece_id', nullable: true)]
    private ?int $pieceId = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $exercise = false;

    #[ORM\Column(name: 'retired_reason', length: 20, nullable: true)]
    private ?string $retiredReason = null;

    #[ORM\Column(name: 'associated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $associatedAt = null;

    #[ORM\Column(name: 'retired_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $retiredAt = null;

    #[ORM\Column(name: 'created_by', nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function getId(): ?int { return $this->id; }
    public function getIdentifier(): string { return $this->identifier; }
    public function getTechnology(): string { return $this->technology; }
    public function getStatus(): string { return $this->status; }
    public function getPieceId(): ?int { return $this->pieceId; }
    public function isExercise(): bool { return $this->exercise; }
}
