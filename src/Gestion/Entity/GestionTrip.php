<?php

namespace App\Gestion\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Voyage d'achat de l'application de gestion (lot B1a, 11/11/2026, `docs/budgets-gestion.md` § 2) : dates, lieu, budget total et journalier
 * dans la devise du voyage (taux vers la devise du site comme les commandes, lot G6), périmètre du budget (`all` : achats et frais ;
 * `expenses` : frais seulement). Les notes de frais et commandes fournisseurs s'y rattachent par `trip_id` (automatique par la date, ou
 * choix du gérant). Deux voyages ne se chevauchent pas. Lu par son service, jamais en cache applicatif (`CacheTags::NO_CACHE_READ`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'gestion_trip')]
#[ORM\Index(name: 'idx_gestion_trip_dates', columns: ['start_date', 'end_date'])]
class GestionTrip
{
    public const SCOPES = ['all', 'expenses'];
    public const DEFAULT_SCOPE = 'expenses'; // en attendant la décision du propriétaire (R2)

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $place = null;

    #[ORM\Column(name: 'start_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(name: 'end_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(name: 'budget_total_cents')]
    private int $budgetTotalCents = 0;

    #[ORM\Column(name: 'budget_daily_cents', nullable: true)]
    private ?int $budgetDailyCents = null;

    #[ORM\Column(name: 'budget_scope', length: 10)]
    private string $budgetScope = self::DEFAULT_SCOPE;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(name: 'exchange_rate', type: 'decimal', precision: 12, scale: 6, nullable: true)]
    private ?string $exchangeRate = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_by', length: 255, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getPlace(): ?string { return $this->place; }
    public function setPlace(?string $place): static { $this->place = $place; return $this; }
    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function setStartDate(\DateTimeImmutable $d): static { $this->startDate = $d; return $this; }
    public function getEndDate(): ?\DateTimeImmutable { return $this->endDate; }
    public function setEndDate(\DateTimeImmutable $d): static { $this->endDate = $d; return $this; }
    public function getBudgetTotalCents(): int { return $this->budgetTotalCents; }
    public function setBudgetTotalCents(int $v): static { $this->budgetTotalCents = $v; return $this; }
    public function getBudgetDailyCents(): ?int { return $this->budgetDailyCents; }
    public function setBudgetDailyCents(?int $v): static { $this->budgetDailyCents = $v; return $this; }
    public function getBudgetScope(): string { return $this->budgetScope; }
    public function setBudgetScope(string $s): static { $this->budgetScope = $s; return $this; }
    public function getCurrency(): ?string { return $this->currency; }
    public function setCurrency(?string $c): static { $this->currency = $c; return $this; }
    public function getExchangeRate(): ?float { return $this->exchangeRate === null ? null : (float) $this->exchangeRate; }
    public function setExchangeRate(?float $rate): static { $this->exchangeRate = $rate === null ? null : number_format($rate, 6, '.', ''); return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): static { $this->notes = $n; return $this; }
    public function getCreatedBy(): ?string { return $this->createdBy; }
    public function setCreatedBy(?string $b): static { $this->createdBy = $b; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $d): static { $this->createdAt = $d; return $this; }
    public function setUpdatedAt(\DateTimeImmutable $d): static { $this->updatedAt = $d; return $this; }

    /** Nombre de jours du voyage, bornes comprises */
    public function days(): int { return (int) $this->startDate->diff($this->endDate)->days + 1; }
    /** Taux vers la devise du site (1 dans la devise du site) */
    public function rate(): float { return $this->currency === null ? 1.0 : (float) ($this->exchangeRate ?? 1); }
    /** Budget journalier effectif dans la devise du voyage : saisi, sinon total ÷ jours (arrondi bas) */
    public function dailyCents(): int { return $this->budgetDailyCents ?? intdiv($this->budgetTotalCents, max(1, $this->days())); }
    public function budgetTotalSite(): int { return (int) round($this->budgetTotalCents * $this->rate()); }
    public function budgetDailySite(): int { return (int) round($this->dailyCents() * $this->rate()); }
    public function covers(\DateTimeInterface $day): bool
    {
        $d = $day->format('Y-m-d');

        return $d >= $this->startDate->format('Y-m-d') && $d <= $this->endDate->format('Y-m-d');
    }
}
