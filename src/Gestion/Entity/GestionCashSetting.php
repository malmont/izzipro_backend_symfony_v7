<?php

namespace App\Gestion\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Réglages de la trésorerie du site (lot B1b-1, 11/11/2026, `docs/tresorerie-gestion.md` § 3), une seule ligne (id 1) : seuil d'alerte en
 * cents signés (0 par défaut) et `include_estimated_sales` (faux par défaut : l'alerte suit la projection sans ventes estimées).
 * Écrit et lu en SQL par `GestionCashService`, jamais en cache applicatif (`CacheTags::NO_CACHE_READ`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'gestion_cash_setting')]
class GestionCashSetting
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id = 1;

    #[ORM\Column(name: 'threshold_cents', type: 'bigint', options: ['default' => 0])]
    private string|int $thresholdCents = 0;

    #[ORM\Column(name: 'include_estimated_sales', options: ['default' => false])]
    private bool $includeEstimatedSales = false;

    /** Lot RAP : écart toléré au rapprochement, en cents (100 par défaut) */
    #[ORM\Column(name: 'tolerance_cents', type: 'bigint', options: ['default' => 100])]
    private string|int $toleranceCents = 100;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): int { return $this->id; }
}
