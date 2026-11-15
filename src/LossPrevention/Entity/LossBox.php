<?php

namespace App\LossPrevention\Entity;

use App\LossPrevention\Repository\LossBoxRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Boîtier de la boutique (lot L3-a, `docs/prevention-pertes.md` § 16) : une petite machine qui appelle la plateforme et lui envoie
 * des faits. Il n'est pas un utilisateur : il s'inscrit avec une clé (seule son empreinte est gardée), reçoit un jeton signé, et ne
 * lit rien du site. Cycle de vie : `pending` (clé créée, aucune machine) → `active` (une machine inscrite) → `revoked` (définitif).
 * `generation` périme les jetons : elle avance à chaque inscription et à chaque remise à zéro de la clé. Un boîtier d'exercice n'a
 * pas de clé : aucune machine ne peut s'inscrire à sa place. Lu et écrit en SQL par `LossBoxRepository`.
 */
#[ORM\Entity(repositoryClass: LossBoxRepository::class)]
#[ORM\Table(name: 'loss_box')]
#[ORM\UniqueConstraint(name: 'uniq_loss_box_key', columns: ['key_hash'], options: ['where' => 'key_hash IS NOT NULL'])]
#[ORM\Index(name: 'idx_loss_box_exercise', columns: ['id'], options: ['where' => 'exercise'])]
class LossBox
{
    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const REVOKED = 'revoked';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $place = null;

    #[ORM\Column(name: 'key_hash', length: 64, nullable: true)]
    private ?string $keyHash = null;

    #[ORM\Column(name: 'key_hint', length: 24, nullable: true)]
    private ?string $keyHint = null;

    #[ORM\Column(length: 12, options: ['default' => 'pending'])]
    private string $status = self::PENDING;

    #[ORM\Column(options: ['default' => 1])]
    private int $generation = 1;

    #[ORM\Column(name: 'machine_hash', length: 64, nullable: true)]
    private ?string $machineHash = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $version = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $arch = null;

    #[ORM\Column(name: 'enrolled_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $enrolledAt = null;

    #[ORM\Column(name: 'last_seen_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(name: 'clock_offset', nullable: true)]
    private ?int $clockOffset = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $exercise = false;

    #[ORM\Column(name: 'created_by', nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function getId(): ?int { return $this->id; }
    public function getStatus(): string { return $this->status; }
}
