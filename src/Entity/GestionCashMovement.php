<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Mouvement de trésorerie prévu (lot B1b-1, 11/11/2026, `docs/tresorerie-gestion.md` § 2) : loyer, salaires, assurances, abonnements, prêt,
 * taxes, autres ; sortie ou entrée, en cents dans la devise du site, ponctuel ou récurrent (hebdomadaire, mensuel, trimestriel, annuel ;
 * le 31 d'un mois court tombe le dernier jour). Les frais fixes s'appuieront dessus. Écrit et lu en SQL par `GestionCashService`, jamais
 * en cache applicatif (`CacheTags::NO_CACHE_READ`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'gestion_cash_movement')]
#[ORM\Index(name: 'idx_gestion_cash_movement_category', columns: ['category'])]
class GestionCashMovement
{
    public const CATEGORIES = ['rent', 'salaries', 'insurance', 'subscription', 'utilities', 'loan', 'tax', 'other']; // utilities : lot FF1 (énergie, eau, internet)
    /** Lot FF1 : catégories dont un mouvement sortant récurrent est un frais fixe par défaut (loan : capital, tax, other : non) */
    public const FIXED_COST_CATEGORIES = ['rent', 'salaries', 'insurance', 'subscription', 'utilities'];
    public const DIRECTIONS = ['out', 'in'];
    public const RECURRENCES = ['none', 'weekly', 'monthly', 'quarterly', 'yearly'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $label = '';

    #[ORM\Column(length: 20)]
    private string $category = 'other';

    #[ORM\Column(length: 3)]
    private string $direction = 'out';

    #[ORM\Column(name: 'amount_cents', type: 'bigint')]
    private string|int $amountCents = 0;

    #[ORM\Column(name: 'start_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(length: 10)]
    private string $recurrence = 'none';

    #[ORM\Column(name: 'end_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /** Lot FF1 : frais fixe choisi par le gérant ; null = règle par défaut (FIXED_COST_CATEGORIES, sortant, récurrent) */
    #[ORM\Column(name: 'fixed_cost', nullable: true)]
    private ?bool $fixedCost = null;

    #[ORM\Column(name: 'created_by', length: 255, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int { return $this->id; }
}
