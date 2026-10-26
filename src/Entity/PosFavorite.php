<?php

namespace App\Entity;

use App\Repository\PosFavoriteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Produit épinglé dans la grille de favoris de la caisse (F10) par le responsable : pour tout le site (poste vide) ou pour un poste.
 * Les épinglés d'un poste REMPLACENT ceux du site pour ce poste (sans fusion). Variante facultative : une variante précise, sinon
 * le produit (la caisse demande la variante s'il en a plusieurs). Suppression du produit, de la variante ou du poste : épinglage supprimé.
 */
#[ORM\Entity(repositoryClass: PosFavoriteRepository::class)]
#[ORM\Table(name: 'pos_favorite')]
#[ORM\Index(name: 'idx_pos_favorite_scope', columns: ['cash_register_id', 'position'])]
#[ORM\Index(name: 'idx_pos_favorite_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_pos_favorite_variant', columns: ['product_variant_id'])]
class PosFavorite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Product $product = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ProductVariant $productVariant = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?CashRegister $cashRegister = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?int $createdById = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProduct(): ?Product { return $this->product; }
    public function setProduct(?Product $product): static { $this->product = $product; return $this; }
    public function getProductVariant(): ?ProductVariant { return $this->productVariant; }
    public function setProductVariant(?ProductVariant $variant): static { $this->productVariant = $variant; return $this; }
    public function getCashRegister(): ?CashRegister { return $this->cashRegister; }
    public function setCashRegister(?CashRegister $register): static { $this->cashRegister = $register; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(?int $position): static { $this->position = max(0, (int) $position); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getCreatedById(): ?int { return $this->createdById; }
    public function setCreatedById(?int $id): static { $this->createdById = $id; return $this; }

    public function __toString(): string
    {
        return (string) $this->product?->getName() . ($this->productVariant ? ' (variante ' . $this->productVariant->getId() . ')' : '');
    }
}
