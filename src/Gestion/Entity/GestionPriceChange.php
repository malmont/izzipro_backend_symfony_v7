<?php

namespace App\Gestion\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Ligne d'un lot de prix (lot P4, `docs/prix-gestion.md`) : ancien et nouveau prix (cents) d'un article ou d'une variante ; en SQL, hors cache applicatif */
#[ORM\Entity]
#[ORM\Table(name: 'gestion_price_change')]
#[ORM\Index(name: 'idx_gestion_price_change_batch', columns: ['batch_id'])]
#[ORM\Index(name: 'idx_gestion_price_change_product', columns: ['product_id'])]
class GestionPriceChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'batch_id')]
    private int $batchId = 0;

    #[ORM\Column(name: 'product_id')]
    private int $productId = 0;

    #[ORM\Column(name: 'variant_id', nullable: true)]
    private ?int $variantId = null;

    #[ORM\Column(length: 8)]
    private string $level = 'product';

    #[ORM\Column(name: 'old_price')]
    private int $oldPrice = 0;

    #[ORM\Column(name: 'new_price')]
    private int $newPrice = 0;

    public function getId(): ?int { return $this->id; }
}
