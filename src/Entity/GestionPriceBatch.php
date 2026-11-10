<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Lot de prix appliqués par la gestion (lot P4, 11/11/2026, `docs/prix-gestion.md`) : corps de l'aperçu, auteur, conseil d'origine, annulation
 * (24 h). Écrit et lu en SQL par `GestionPriceService`, jamais en cache applicatif (`CacheTags::NO_CACHE_READ`) ; les prix eux-mêmes passent
 * par le chemin d'écriture d'un article (`LandingContentEditor`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'gestion_price_batch')]
class GestionPriceBatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'json')]
    private array $body = [];

    #[ORM\Column(length: 12)]
    private string $mode = 'percent';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4)]
    private string $value = '0';

    #[ORM\Column(length: 10)]
    private string $rounding = 'none';

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

    #[ORM\Column(name: 'undone_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $undoneAt = null;

    #[ORM\Column(name: 'undone_by', length: 255, nullable: true)]
    private ?string $undoneBy = null;

    #[ORM\Column(name: 'undo_skipped', type: 'json', nullable: true)]
    private ?array $undoSkipped = null;

    public function getId(): ?int { return $this->id; }
}
