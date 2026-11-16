<?php

namespace App\LossPrevention\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Présence d'une pièce dans une zone (lot L4-a, `docs/prevention-pertes.md` § 17.12) : une antenne a vu la puce arriver (`since`),
 * elle l'a vue partir ou non (`until`). C'est une PROJECTION des faits `ZONE_SEEN` et `ZONE_LEFT` que le boîtier envoie : le
 * serveur la tient à jour pour dire OÙ une pièce a été vue en dernier ; elle n'est la preuve de rien (les faits le sont) et ne
 * dit jamais qu'une pièce « est » quelque part : une présence sans départ connu est une dernière lecture, pas une certitude.
 * Les heures sont celles du boîtier (une arrivée à l'heure douteuse ne pose rien). Lue et écrite en SQL par `LossBoxRepository`
 * et `LossPieceRepository`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'loss_zone_presence')]
#[ORM\UniqueConstraint(name: 'uniq_loss_zone_presence_open', columns: ['device_id', 'tag_id'], options: ['where' => 'until IS NULL'])]
#[ORM\Index(name: 'idx_loss_zone_presence_piece', columns: ['piece_id', 'since'])]
#[ORM\Index(name: 'idx_loss_zone_presence_tag', columns: ['tag_id'], options: ['where' => 'until IS NULL'])]
#[ORM\Index(name: 'idx_loss_zone_presence_device', columns: ['device_id', 'until'])]
#[ORM\Index(name: 'idx_loss_zone_presence_exercise', columns: ['id'], options: ['where' => 'exercise'])]
class LossZonePresence
{
    /** Durée de lecture qui fait « arriver » une pièce dans une zone, silence qui la fait « partir » (secondes) : servies au boîtier */
    public const ARRIVE_SECONDS = 3;
    public const LEAVE_SECONDS = 30;
    public const ARRIVE_BOUNDS = ['min' => 1, 'max' => 60];
    public const LEAVE_BOUNDS = ['min' => 5, 'max' => 600];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'device_id')]
    private int $deviceId;

    #[ORM\Column(name: 'tag_id')]
    private int $tagId;

    #[ORM\Column(name: 'piece_id', nullable: true)]
    private ?int $pieceId = null;

    #[ORM\Column(length: 20)]
    private string $zone;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $since;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $until = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $exercise = false;

    public function getId(): ?int
    {
        return $this->id;
    }
}
