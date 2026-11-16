<?php

namespace App\LossPrevention\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Fait d'un boîtier ou d'un de ses appareils (lot L3-a, `docs/prevention-pertes.md` § 16) : inscription, clé remise à zéro,
 * révocation, appareil vu pour la première fois, appareil en panne puis revenu, boîtier revenu après un silence. En ajout seul (la
 * base refuse toute modification ; seule une ligne d'exercice se supprime, par la porte d'exercice). Le signe de vie lui-même n'est
 * PAS un fait : seul un changement d'état en écrit un. `data` : nombres, codes et identifiants seulement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'loss_box_event')]
#[ORM\Index(name: 'idx_loss_box_event_box', columns: ['box_id', 'id'])]
#[ORM\Index(name: 'idx_loss_box_event_exercise', columns: ['id'], options: ['where' => 'exercise'])]
class LossBoxEvent
{
    public const BOX_CREATED = 'BOX_CREATED';
    public const BOX_ENROLLED = 'BOX_ENROLLED';
    public const BOX_KEY_RESET = 'BOX_KEY_RESET';
    public const BOX_REVOKED = 'BOX_REVOKED';
    /** Le boîtier redonne signe de vie après un silence : `data.silentSeconds` */
    public const BOX_BACK = 'BOX_BACK';
    public const DEVICE_SEEN = 'DEVICE_SEEN';
    public const SOURCE_DOWN = 'SOURCE_DOWN';
    public const SOURCE_UP = 'SOURCE_UP';
    /** Un tapis relié à un poste de caisse, ou un lecteur à une zone (lot L3-b1) : `data.cashRegisterId` ou `data.zone` */
    public const DEVICE_ASSIGNED = 'DEVICE_ASSIGNED';
    /** Un appareil remplacé, retiré par l'administrateur (lot L3-b1) */
    public const DEVICE_RETIRED = 'DEVICE_RETIRED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'box_id')]
    private int $boxId;

    #[ORM\Column(name: 'device_id', nullable: true)]
    private ?int $deviceId = null;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'user_id', nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $exercise = false;

    #[ORM\Column(type: 'text')]
    private string $data = '{}';

    public function getId(): ?int { return $this->id; }
    public function getType(): string { return $this->type; }
}
