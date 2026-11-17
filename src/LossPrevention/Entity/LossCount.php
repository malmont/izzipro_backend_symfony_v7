<?php

namespace App\LossPrevention\Entity;

use App\LossPrevention\Repository\LossCountRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un comptage (lot L4-d, `docs/prevention-pertes.md` § 17.16) : le gérant l'ouvre, passe le lecteur à main, le ferme. Ses lectures sont
 * celles du lecteur à main entre l'ouverture et la fermeture : un seul comptage ouvert à la fois par genre (vrai ou exercice), garanti
 * par la base. Resté ouvert plus de 12 heures, il se ferme seul sans conclusion (`expired`). Fermé, il est figé (migration
 * `Version20270125100000`). Lu et écrit en SQL par `LossCountRepository`.
 */
#[ORM\Entity(repositoryClass: LossCountRepository::class)]
#[ORM\Table(name: 'loss_count')]
#[ORM\UniqueConstraint(name: 'uniq_loss_count_open', columns: ['exercise'], options: ['where' => "status = 'open'"])]
class LossCount
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';
    /** Fermé seul, sans conclusion, après `LossCountService::EXPIRE_HOURS` heures */
    public const EXPIRED = 'expired';
    public const ALL = 'all';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** `all`, ou une zone (`LossDevice::ZONES`) : les pièces vues en dernier dans cette zone */
    #[ORM\Column(length: 20)]
    private string $scope = self::ALL;

    #[ORM\Column(length: 10, options: ['default' => 'open'])]
    private string $status = self::OPEN;

    #[ORM\Column(options: ['default' => false])]
    private bool $exercise = false;

    #[ORM\Column(name: 'opened_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $openedAt;

    #[ORM\Column(name: 'opened_by', nullable: true)]
    private ?int $openedBy = null;

    #[ORM\Column(name: 'closed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(name: 'closed_by', nullable: true)]
    private ?int $closedBy = null;

    /** Pièces attendues, lues, non lues, et dites absentes (non lues au comptage précédent qui les couvrait aussi) */
    #[ORM\Column(nullable: true)]
    private ?int $expected = null;

    #[ORM\Column(nullable: true)]
    private ?int $found = null;

    #[ORM\Column(nullable: true)]
    private ?int $absent = null;

    #[ORM\Column(nullable: true)]
    private ?int $missing = null;
}
