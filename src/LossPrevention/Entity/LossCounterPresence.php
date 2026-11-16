<?php

namespace App\LossPrevention\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Présence d'une pièce sur un tapis de comptoir (lot L3-b1, `docs/prevention-pertes.md` § 16.15) : la puce est arrivée sur le
 * tapis (`since`), elle l'a quitté ou non (`until`). C'est une PROJECTION des faits `COUNTER_SEEN` et `COUNTER_LEFT` que le boîtier
 * envoie : le serveur la tient à jour, elle se recalcule depuis les faits, elle n'est la preuve de rien. Les heures sont celles du
 * boîtier (un fait à l'heure douteuse ne l'alimente pas). Lue et écrite en SQL par `LossBoxRepository`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'loss_counter_presence')]
#[ORM\UniqueConstraint(name: 'uniq_loss_counter_presence_open', columns: ['device_id', 'tag_id'], options: ['where' => 'until IS NULL'])]
#[ORM\Index(name: 'idx_loss_counter_presence_device', columns: ['device_id', 'until'])]
#[ORM\Index(name: 'idx_loss_counter_presence_exercise', columns: ['id'], options: ['where' => 'exercise'])]
class LossCounterPresence
{
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

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $since;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $until = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $exercise = false;

    public function getId(): ?int { return $this->id; }
}
