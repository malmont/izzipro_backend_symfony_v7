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
#[ORM\Index(name: 'idx_loss_piece_event_box', columns: ['box_id', 'id'], options: ['where' => 'box_id IS NOT NULL'])]
#[ORM\Index(name: 'idx_loss_piece_event_back_door', columns: ['id'], options: ['where' => "type = 'ZONE_SEEN' AND NOT exercise AND ((data)::jsonb ->> 'zone') = 'back_door'"])]
#[ORM\Index(name: 'idx_loss_piece_event_moves', columns: ['id'], options: ['where' => "type IN ('ZONE_SEEN', 'ZONE_LEFT') AND NOT exercise"])]
#[ORM\Index(name: 'idx_loss_piece_event_exit', columns: ['id'], options: ['where' => "type = 'ZONE_SEEN' AND NOT exercise AND ((data)::jsonb ->> 'zone') = 'exit'"])]
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
    /** Étiquette d'essai demandée pendant l'installation (lot L2-b) : le serveur ne voit pas l'imprimante, il sait qu'elle a été demandée */
    public const TEST_LABEL_PRINTED = 'TEST_LABEL_PRINTED';

    /** Puce lue par le lecteur à main d'un boîtier (lot L3-a) : la pièce a été vue, son état ne change pas */
    public const SPOT_CHECK = 'SPOT_CHECK';
    /** Puce lue dans une zone par un appareil d'un boîtier (lot L3-a) : gardé tel quel, aucun état ne change avant les lots L3-b et L4 */
    public const ZONE_SEEN = 'ZONE_SEEN';
    /** Une puce arrive sur un tapis de comptoir, puis le quitte (lot L3-b1) : la présence sur le tapis en est la projection */
    public const COUNTER_SEEN = 'COUNTER_SEEN';
    public const COUNTER_LEFT = 'COUNTER_LEFT';
    /** La pièce est reliée à une vente par l'analyse, ou revient (lot L3-b2a) : écrits par le module, jamais par un boîtier */
    public const PIECE_SOLD = 'PIECE_SOLD';
    public const PIECE_RETURNED = 'PIECE_RETURNED';
    /** Lot L4-a : une antenne ne voit plus la pièce dans sa zone (le pendant de `ZONE_SEEN`, qui dit son arrivée) */
    public const ZONE_LEFT = 'ZONE_LEFT';
    /** Faits qu'un LECTEUR de boîtier peut envoyer par le canal */
    public const FROM_BOX = [self::SPOT_CHECK, self::ZONE_SEEN, self::ZONE_LEFT];
    /** Arrivée et départ d'une zone : ils disent leur zone, et tiennent la présence par zone */
    public const FROM_ZONE = [self::ZONE_SEEN, self::ZONE_LEFT];
    /** Faits qu'un TAPIS de comptoir peut envoyer par le canal */
    public const FROM_MAT = [self::COUNTER_SEEN, self::COUNTER_LEFT];
    /** Lectures de puces par un appareil : nombreuses, la fiche d'une pièce n'en montre que les dernières */
    /** Lot L5-d : le portique a sonné pour cette puce (fait d'un avertisseur) ; jamais une preuve, il n'ouvre rien */
    public const EXIT_SIGNAL = 'EXIT_SIGNAL';
    public const READS = [self::SPOT_CHECK, self::ZONE_SEEN, self::COUNTER_SEEN, self::COUNTER_LEFT, self::ZONE_LEFT];
    /**
     * Résultats d'une imprimante à puce (lot L3-c), envoyés par le boîtier avec ses autres faits : puce écrite et RELUE conforme, ou
     * écriture en échec. Ce ne sont pas des faits de pièce : ils closent une ligne de travail d'impression (`LossPrintService::result`) ;
     * une écriture réussie pose la puce sur la pièce, ce qui écrit le fait `TAG_ASSOCIATED`.
     */
    public const TAG_WRITTEN = 'TAG_WRITTEN';
    public const TAG_WRITE_FAILED = 'TAG_WRITE_FAILED';
    public const PRINTS = [self::TAG_WRITTEN, self::TAG_WRITE_FAILED];

    public const SOURCE_APP = 'app';
    public const SOURCE_EXERCISE = 'exercise';
    /** Fait envoyé par un boîtier (lot L3-a) : `box_id`, `device_id`, `received_at` et `event_key` sont remplis */
    public const SOURCE_BOX = 'box';

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

    #[ORM\Column(name: 'box_id', nullable: true)]
    private ?int $boxId = null;

    #[ORM\Column(name: 'device_id', nullable: true)]
    private ?int $deviceId = null;

    /** Heure de réception par le serveur (un fait de boîtier garde aussi l'heure du boîtier, dans `occurred_at`) */
    #[ORM\Column(name: 'received_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $receivedAt = null;

    public function getId(): ?int { return $this->id; }
    public function getType(): string { return $this->type; }
}
