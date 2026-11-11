<?php

namespace App\Gestion\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Changement d'une grille de location fait depuis la gestion (lot LOC4, 11/11/2026, `docs/locations-gestion.md` § 4) : tarifs avant / après
 * (cents), articles qui partagent la grille, annulation dans les 24 h si la grille n'a pas été retouchée. Écrit et lu en SQL par
 * `GestionRentalGridService`, jamais en cache applicatif (`CacheTags::NO_CACHE_READ`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'gestion_rental_grid_change')]
#[ORM\Index(name: 'idx_gestion_rental_grid_change_pack', columns: ['rental_pack_id'])]
class GestionRentalGridChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'rental_pack_id')]
    private int $rentalPackId = 0;

    #[ORM\Column(name: 'before_rates', type: 'json')]
    private array $beforeRates = [];

    #[ORM\Column(name: 'after_rates', type: 'json')]
    private array $afterRates = [];

    #[ORM\Column(name: 'product_ids', type: 'json')]
    private array $productIds = [];

    #[ORM\Column(name: 'advice_id', nullable: true)]
    private ?int $adviceId = null;

    #[ORM\Column(name: 'created_by', length: 255, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'undone_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $undoneAt = null;

    #[ORM\Column(name: 'undone_by', length: 255, nullable: true)]
    private ?string $undoneBy = null;

    public function getId(): ?int { return $this->id; }
}
