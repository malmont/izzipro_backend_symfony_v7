<?php

namespace App\Gestion\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Lot de promotions datées posé par la gestion (lot P5, 11/11/2026, `docs/promotions-gestion.md`) : remise, jours et instants UTC, conseil
 * d'origine, fin anticipée. En SQL par `GestionPromotionService`, jamais en cache applicatif ; le prix promotionnel lui-même passe par le chemin
 * d'écriture d'un article.
 */
#[ORM\Entity]
#[ORM\Table(name: 'gestion_promotion_batch')]
class GestionPromotionBatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'json')]
    private array $body = [];

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2)]
    private string $percent = '0';

    #[ORM\Column(length: 10)]
    private string $rounding = 'none';

    #[ORM\Column(name: 'start_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(name: 'end_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(name: 'starts_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(name: 'ends_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(name: 'advice_id', nullable: true)]
    private ?int $adviceId = null;

    #[ORM\Column(name: 'advice_marked', options: ['default' => false])]
    private bool $adviceMarked = false;

    #[ORM\Column]
    private int $changed = 0;

    #[ORM\Column(name: 'created_by', length: 255, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'ended_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(name: 'ended_by', length: 255, nullable: true)]
    private ?string $endedBy = null;

    #[ORM\Column(name: 'end_skipped', type: 'json', nullable: true)]
    private ?array $endSkipped = null;

    public function getId(): ?int { return $this->id; }
}
