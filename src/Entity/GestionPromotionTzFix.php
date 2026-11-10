<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Journal de la remise en UTC des dates de promotion (lot P5b, `docs/promotions-gestion.md` § 7) ; écrit par `app:gestion:promotions:fuseau`, en SQL */
#[ORM\Entity]
#[ORM\Table(name: 'gestion_promotion_tz_fix')]
#[ORM\Index(name: 'idx_gestion_promotion_tz_fix_product', columns: ['product_id'])]
class GestionPromotionTzFix
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'product_id')]
    private int $productId = 0;

    #[ORM\Column(name: 'old_from', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $oldFrom = null;

    #[ORM\Column(name: 'old_to', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $oldTo = null;

    #[ORM\Column(name: 'new_from', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $newFrom = null;

    #[ORM\Column(name: 'new_to', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $newTo = null;

    #[ORM\Column(length: 64)]
    private string $timezone = 'UTC';

    #[ORM\Column(name: 'applied_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $appliedAt = null;

    public function getId(): ?int { return $this->id; }
}
