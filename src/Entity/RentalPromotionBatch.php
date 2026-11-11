<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Lot de promotions de location appliqué en une fois depuis la gestion (lot LOC3, 11/11/2026) : réglages communs, conseil du guide d'origine,
 * auteur, fin anticipée. Écrit et lu en SQL par `GestionRentalPromotionService`, jamais en cache applicatif (`CacheTags::NO_CACHE_READ`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'rental_promotion_batch')]
class RentalPromotionBatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'smallint')]
    private int $percent = 0;

    #[ORM\Column(name: 'start_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(name: 'end_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $endDate = null;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $weekdays = [];

    #[ORM\Column(name: 'advice_id', nullable: true)]
    private ?int $adviceId = null;

    #[ORM\Column(name: 'created_by', length: 255, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'ended_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(name: 'ended_by', length: 255, nullable: true)]
    private ?string $endedBy = null;

    public function getId(): ?int { return $this->id; }
}
