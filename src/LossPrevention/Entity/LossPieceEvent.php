<?php

namespace App\LossPrevention\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Fait d'une pièce ou d'une puce (lot L2-a, `docs/prevention-pertes.md` § 13) : ce qui s'est passé, quand, par qui, d'où. En ajout
 * seul (la base refuse toute modification ; seule une ligne d'exercice se supprime, par la porte d'exercice). Un état ne change que
 * par un fait : `loss_piece.status` et `loss_tag.status` en sont la projection. `data` : nombres, codes et identifiants seulement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'loss_piece_event')]
#[ORM\Index(name: 'idx_loss_piece_event_piece', columns: ['piece_id', 'id'])]
#[ORM\Index(name: 'idx_loss_piece_event_tag', columns: ['tag_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_loss_piece_event_key', columns: ['event_key'], options: ['where' => 'event_key IS NOT NULL'])]
#[ORM\Index(name: 'idx_loss_piece_event_exercise', columns: ['id'], options: ['where' => 'exercise'])]
class LossPieceEvent
{
    public const PIECE_CREATED = 'PIECE_CREATED';
    public const TAG_ASSOCIATED = 'TAG_ASSOCIATED';
    /** La pièce porte désormais plusieurs puces actives (une paire) */
    public const PAIR_SET = 'PAIR_SET';
    public const TAG_RETIRED = 'TAG_RETIRED';
    public const PIECE_RETIRED = 'PIECE_RETIRED';
    /** Puce lue pendant l'essai de l'assistant d'installation (lot L2-b) */
    public const TEST_TAG_READ = 'TEST_TAG_READ';

    public const SOURCE_APP = 'app';
    public const SOURCE_EXERCISE = 'exercise';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'user_id', nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(name: 'piece_id', nullable: true)]
    private ?int $pieceId = null;

    #[ORM\Column(name: 'tag_id', nullable: true)]
    private ?int $tagId = null;

    #[ORM\Column(length: 12, options: ['default' => 'app'])]
    private string $source = self::SOURCE_APP;

    #[ORM\Column(name: 'event_key', length: 64, nullable: true)]
    private ?string $eventKey = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $exercise = false;

    #[ORM\Column(type: 'text')]
    private string $data = '{}';

    public function getId(): ?int { return $this->id; }
    public function getType(): string { return $this->type; }
}
