<?php

namespace App\LossPrevention\Entity;

use App\LossPrevention\Repository\LossAlertRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une alerte de sortie (lot L5-d, `docs/prevention-pertes.md` § 18.2 et § 18.11) : une ou plusieurs pièces lues à la sortie ou à la porte
 * arrière, sans vente probable, dans la même zone et la même minute. Elle dit l'article, la zone et l'heure : rien d'une personne. Ce
 * n'est pas un constat (le constat « sortie sans vente » est jugé après le rattrapage). Lue et écrite en SQL par `LossAlertRepository`.
 */
#[ORM\Entity(repositoryClass: LossAlertRepository::class)]
#[ORM\Table(name: 'loss_alert')]
// index unique `uniq_loss_alert_minute` (exercise, zone, minute, COALESCE(requested_by, 0)) : posé par la migration (une expression)
#[ORM\Index(name: 'idx_loss_alert_day', columns: ['store_day', 'exercise'])]
class LossAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $exercise = false;

    #[ORM\Column(length: 20)]
    private string $zone;

    /** La minute de la lecture (UTC) : les pièces d'une même minute et d'une même zone font une seule alerte */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $minute;

    #[ORM\Column(name: 'store_day', type: 'date_immutable')]
    private \DateTimeImmutable $storeDay;

    #[ORM\Column(name: 'out_of_hours', options: ['default' => false])]
    private bool $outOfHours = false;

    /** @var list<array{variantId: int, count: int}> */
    #[ORM\Column(type: 'json')]
    private array $articles = [];

    /** @var list<int> pièces de l'alerte (identifiants internes, jamais servis tels quels) */
    #[ORM\Column(type: 'json')]
    private array $pieces = [];

    /** Nombre de gérants à qui la notification est partie */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $pushed = 0;

    /** Exercice : l'administrateur qui l'a lancé, seul destinataire */
    #[ORM\Column(name: 'requested_by', nullable: true)]
    private ?int $requestedBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function getId(): ?int { return $this->id; }
    public function isExercise(): bool { return $this->exercise; }
    public function getZone(): string { return $this->zone; }
    public function getMinute(): \DateTimeImmutable { return $this->minute; }
    public function getStoreDay(): \DateTimeImmutable { return $this->storeDay; }
    public function isOutOfHours(): bool { return $this->outOfHours; }
    /** @return list<array{variantId: int, count: int}> */
    public function getArticles(): array { return $this->articles; }
    /** @return list<int> */
    public function getPieces(): array { return $this->pieces; }
    public function getPushed(): int { return $this->pushed; }
    public function getRequestedBy(): ?int { return $this->requestedBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
