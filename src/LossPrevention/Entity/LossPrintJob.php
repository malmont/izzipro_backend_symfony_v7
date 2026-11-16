<?php

namespace App\LossPrevention\Entity;

use App\LossPrevention\Repository\LossPrintRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un travail d'impression de puces (lot L3-c, `docs/prevention-pertes.md` § 16.20) : des pièces à étiqueter sur l'imprimante à puce
 * d'un boîtier. Le boîtier vient le chercher (la plateforme ne joint jamais la boutique), imprime, écrit et RELIT chaque puce,
 * puis renvoie un fait par étiquette. Un vrai travail dit le rang du mot de passe d'accès à employer ; un travail d'exercice n'en
 * porte jamais. La base garde elle-même ces règles (migration du lot). Lu et écrit en SQL par `LossPrintRepository`.
 */
#[ORM\Entity(repositoryClass: LossPrintRepository::class)]
#[ORM\Table(name: 'loss_print_job')]
#[ORM\Index(name: 'idx_loss_print_job_box', columns: ['box_id', 'status'])]
class LossPrintJob
{
    public const WAITING = 'waiting';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const CANCELLED = 'cancelled';
    /** Un travail que le boîtier peut encore servir */
    public const OPEN = [self::WAITING, self::RUNNING];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'box_id')]
    private int $boxId;

    #[ORM\Column(name: 'device_id')]
    private int $deviceId;

    #[ORM\Column(length: 10, options: ['default' => self::WAITING])]
    private string $status = self::WAITING;

    #[ORM\Column(name: 'with_price', options: ['default' => false])]
    private bool $withPrice = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $exercise = false;

    #[ORM\Column(name: 'secret_rank', nullable: true)]
    private ?int $secretRank = null;

    #[ORM\Column(name: 'created_by', nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'cancelled_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    public function getId(): ?int { return $this->id; }
}
