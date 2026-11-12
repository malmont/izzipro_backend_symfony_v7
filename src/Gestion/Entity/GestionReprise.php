<?php

namespace App\Gestion\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Reprise d'historique de l'application de gestion (lot P3, 10/11/2026, `docs/periodes-gestion.md` § 4) : une seule ligne
 * active par site ; créneau, coefficient d'estimation, périodes créées par la reprise, résumé rendu par `GET /api/gestion/reprise`.
 * Jamais lue en cache applicatif (`CacheTags::NO_CACHE_READ`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'gestion_reprise')]
class GestionReprise
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'applied_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $appliedAt = null;

    #[ORM\Column]
    private int $months = 12;

    #[ORM\Column(name: 'start_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(name: 'end_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(name: 'estimate_coefficient', type: 'decimal', precision: 8, scale: 3, nullable: true)]
    private ?string $estimateCoefficient = null;

    /** @var int[] */
    #[ORM\Column(name: 'created_period_ids', type: 'json')]
    private array $createdPeriodIds = [];

    #[ORM\Column(type: 'json')]
    private array $summary = [];

    /**
     * Résultats figés que l'application de la reprise a effacés (lot REPRISE-FIGE, 12/11/2026) : lignes entières de period_result, chaque
     * colonne telle qu'enregistrée (le résultat lui-même reste le texte JSON d'origine), remises à l'identique à l'annulation. Null pour
     * une reprise appliquée avant ce lot : son annulation laisse les résultats se recalculer, comme avant.
     *
     * @var list<array<string, mixed>>|null
     */
    #[ORM\Column(name: 'frozen_results', type: 'json', nullable: true)]
    private ?array $frozenResults = null;

    public function getId(): ?int { return $this->id; }
    public function getAppliedAt(): ?\DateTimeImmutable { return $this->appliedAt; }
    public function setAppliedAt(\DateTimeImmutable $at): static { $this->appliedAt = $at; return $this; }
    public function getMonths(): int { return $this->months; }
    public function setMonths(int $months): static { $this->months = $months; return $this; }
    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function setStartDate(\DateTimeImmutable $d): static { $this->startDate = $d; return $this; }
    public function getEndDate(): ?\DateTimeImmutable { return $this->endDate; }
    public function setEndDate(\DateTimeImmutable $d): static { $this->endDate = $d; return $this; }
    public function getEstimateCoefficient(): ?float { return $this->estimateCoefficient === null ? null : (float) $this->estimateCoefficient; }
    public function setEstimateCoefficient(?float $c): static { $this->estimateCoefficient = $c === null ? null : number_format($c, 3, '.', ''); return $this; }
    /** @return int[] */
    public function getCreatedPeriodIds(): array { return $this->createdPeriodIds; }
    /** @param int[] $ids */
    public function setCreatedPeriodIds(array $ids): static { $this->createdPeriodIds = array_values($ids); return $this; }
    public function getSummary(): array { return $this->summary; }
    public function setSummary(array $summary): static { $this->summary = $summary; return $this; }
    /** @return list<array<string, mixed>>|null */
    public function getFrozenResults(): ?array { return $this->frozenResults; }
    public function setFrozenResults(?array $rows): static { $this->frozenResults = $rows; return $this; }
}
