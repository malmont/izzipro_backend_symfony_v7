<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Ligne d'un lot de promotions (lot P5, `docs/promotions-gestion.md`) : ancien et nouvel état du prix promotionnel d'un article ; en SQL, hors cache applicatif */
#[ORM\Entity]
#[ORM\Table(name: 'gestion_promotion_change')]
#[ORM\Index(name: 'idx_gestion_promotion_change_batch', columns: ['batch_id'])]
#[ORM\Index(name: 'idx_gestion_promotion_change_product', columns: ['product_id'])]
class GestionPromotionChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'batch_id')]
    private int $batchId = 0;

    #[ORM\Column(name: 'product_id')]
    private int $productId = 0;

    #[ORM\Column(name: 'old_special_price', nullable: true)]
    private ?int $oldSpecialPrice = null;

    #[ORM\Column(name: 'old_from', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $oldFrom = null;

    #[ORM\Column(name: 'old_to', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $oldTo = null;

    #[ORM\Column(name: 'new_special_price')]
    private int $newSpecialPrice = 0;

    #[ORM\Column(name: 'new_from', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $newFrom = null;

    #[ORM\Column(name: 'new_to', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $newTo = null;

    public function getId(): ?int { return $this->id; }
}
