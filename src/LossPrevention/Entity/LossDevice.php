<?php

namespace App\LossPrevention\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Appareil déclaré par un boîtier (lot L3-a, `docs/prevention-pertes.md` § 16) : tapis de comptoir, lecteur, imprimante ou caméra,
 * reconnu par son numéro de série (unique par boîtier). C'est le boîtier qui le déclare, dans son signe de vie ; la plateforme note
 * son état (`ok`, `down`) et la dernière fois qu'il a été vu. `zone` et `cash_register_id` (le poste d'un tapis) sont réservés au
 * lot L3-b. Lu et écrit en SQL par `LossBoxRepository`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'loss_device')]
#[ORM\UniqueConstraint(name: 'uniq_loss_device_box_serial', columns: ['box_id', 'serial'])]
class LossDevice
{
    public const COUNTER_MAT = 'counter_mat';
    public const READER = 'reader';
    public const PRINTER = 'printer';
    public const CAMERA = 'camera';
    public const KINDS = [self::COUNTER_MAT, self::READER, self::PRINTER, self::CAMERA];
    public const OK = 'ok';
    public const DOWN = 'down';
    /** Retiré par l'administrateur (appareil remplacé) : il ne compte plus ; s'il est annoncé de nouveau, il revient (lot L3-b1) */
    public const RETIRED = 'retired';
    /** Zones de la boutique (§ 11) */
    public const ZONES = ['stockroom', 'stockroom_door', 'floor', 'counter', 'exit'];
    /** Appareils qu'un boîtier peut annoncer dans un signe de vie, au plus */
    public const MAX_PER_BOX = 16;
    /** Appareils qu'un boîtier peut avoir annoncés au fil du temps (les remplacés comptent : rien ne se supprime) ; au-delà, un nouveau est refusé */
    public const MAX_KNOWN_PER_BOX = 64;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'box_id')]
    private int $boxId;

    #[ORM\Column(length: 64)]
    private string $serial;

    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(length: 8, options: ['default' => 'ok'])]
    private string $status = self::OK;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $zone = null;

    #[ORM\Column(name: 'cash_register_id', nullable: true)]
    private ?int $cashRegisterId = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $exercise = false;

    #[ORM\Column(name: 'first_seen_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(name: 'last_seen_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $lastSeenAt;

    public function getId(): ?int { return $this->id; }
    public function getKind(): string { return $this->kind; }
}
