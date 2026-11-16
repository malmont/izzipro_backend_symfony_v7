<?php

namespace App\LossPrevention\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Une ligne d'un travail d'impression (lot L3-c, § 16.20) : une pièce, l'identifiant à écrire dans sa puce, et ce qu'il en est
 * advenu. Elle ne quitte l'attente qu'une fois : écrite (la puce relue conforme est alors posée sur la pièce), en échec (avec son
 * motif : la pièce reste sans puce) ou annulée. La base le garde elle-même. Lue et écrite en SQL par `LossPrintRepository`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'loss_print_job_line')]
#[ORM\UniqueConstraint(name: 'uniq_loss_print_line_position', columns: ['job_id', 'position'])]
#[ORM\UniqueConstraint(name: 'uniq_loss_print_line_open_piece', columns: ['piece_id'], options: ['where' => "status = 'waiting'"])]
#[ORM\UniqueConstraint(name: 'uniq_loss_print_line_identifier', columns: ['identifier'])]
#[ORM\Index(name: 'idx_loss_print_line_piece', columns: ['piece_id'])]
class LossPrintJobLine
{
    public const WAITING = 'waiting';
    public const WRITTEN = 'written';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    /** Motifs d'échec dits par le boîtier */
    public const BOX_REASONS = ['tag_unreadable', 'write_refused', 'verify_failed', 'printer_stopped', 'out_of_labels'];
    /** Motifs d'échec dits par la plateforme, quand une écriture réussie ne peut pas être associée */
    public const PIECE_RETIRED = 'piece_retired';
    public const TAG_LIMIT = 'tag_limit';
    public const IDENTIFIER_TAKEN = 'identifier_taken';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LossPrintJob::class)]
    #[ORM\JoinColumn(name: 'job_id', nullable: false, onDelete: 'CASCADE')]
    private LossPrintJob $job;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(name: 'piece_id')]
    private int $pieceId;

    #[ORM\Column(length: 64)]
    private string $identifier;

    #[ORM\Column(length: 10, options: ['default' => self::WAITING])]
    private string $status = self::WAITING;

    #[ORM\Column(length: 24, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(name: 'tag_id', nullable: true)]
    private ?int $tagId = null;

    #[ORM\Column(name: 'done_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $doneAt = null;

    public function getId(): ?int { return $this->id; }
}
