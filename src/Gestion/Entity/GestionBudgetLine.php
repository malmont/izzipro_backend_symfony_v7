<?php

namespace App\Gestion\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Prévu d'un poste du budget d'une période de gestion (lot B1a, 11/11/2026, `docs/budgets-gestion.md` § 1) : purchases | trip | shipping |
 * advertising | other, en cents dans la devise du site. Indépendant du budget global de la période (`collections.budget_collection`).
 * Écrit et lu en SQL par `GestionBudgetService`, jamais en cache applicatif (`CacheTags::NO_CACHE_READ`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'gestion_budget_line')]
#[ORM\UniqueConstraint(name: 'uniq_gestion_budget_line_period_category', columns: ['period_id', 'category'])]
class GestionBudgetLine
{
    public const CATEGORIES = ['purchases', 'trip', 'shipping', 'advertising', 'other'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'period_id')]
    private int $periodId = 0;

    #[ORM\Column(length: 20)]
    private string $category = '';

    #[ORM\Column(name: 'planned_cents')]
    private int $plannedCents = 0;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int { return $this->id; }
}
