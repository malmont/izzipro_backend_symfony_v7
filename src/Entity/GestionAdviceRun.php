<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Génération du guide de la gestion (lot G1a, 11/11/2026, `docs/guide-ia-gestion.md`) : règles calculées pour une période un jour du magasin
 * (`auto` au premier GET du jour, `manual` par la relance, 3 par jour) puis rédaction par l'IA (statut pending | ok | rules_only | not_included |
 * credits_exhausted | error). Écrit et lu en SQL par `GestionAdviceService`, jamais en cache applicatif (`CacheTags::NO_CACHE_READ`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'gestion_advice_run')]
#[ORM\Index(name: 'idx_gestion_advice_run_period_day', columns: ['period_id', 'store_day'])]
class GestionAdviceRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'period_id')]
    private int $periodId = 0;

    #[ORM\Column(name: 'store_day', type: 'date_immutable')]
    private ?\DateTimeImmutable $storeDay = null;

    #[ORM\Column(name: 'run_trigger', length: 10)]
    private string $trigger = 'auto';

    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $headline = null;

    #[ORM\Column(name: 'headline_locale', length: 5, nullable: true)]
    private ?string $headlineLocale = null;

    #[ORM\Column(name: 'ai_usage_id', nullable: true)]
    private ?int $aiUsageId = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $rejected = 0;

    #[ORM\Column(name: 'created_by', length: 255, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function getId(): ?int { return $this->id; }
}
