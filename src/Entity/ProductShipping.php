<?php

namespace App\Entity;

use App\Repository\ProductShippingRepository;
use Doctrine\ORM\Mapping as ORM;
use DVDoug\BoxPacker\Item;
use DVDoug\BoxPacker\Rotation;

#[ORM\Entity(repositoryClass: ProductShippingRepository::class)]
class ProductShipping implements Item
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'productShipping', cascade: ['persist', 'remove'])]
    private ?Product $product = null;

    #[ORM\Column(name: "weight")]
    private ?float $weightKg = null;

    #[ORM\Column(name: "length")]
    private ?float $lengthCm = null;

    #[ORM\Column(name: "width", nullable: true)]
    private ?float $widthCm = null;

    #[ORM\Column(name: "height", nullable: true)]
    private ?float $heightCm = null; 

    // --- FIN DES PROPRIÉTÉS RENOMMÉES ---


    #[ORM\Column(length: 50, nullable: true)]
    private ?string $shippingClass = null;

    #[ORM\ManyToOne(inversedBy: 'productShippings')]
    #[ORM\JoinColumn(nullable: true)]
    private ?ShippingClass $shippingClassEntity = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;
        return $this;
    }

    // --- NOUVEAUX GETTERS/SETTERS POUR EASYADMIN (en KG et CM) ---

    public function getWeightKg(): ?float
    {
        return $this->weightKg;
    }

    public function setWeightKg(float $weightKg): static
    {
        $this->weightKg = $weightKg;
        return $this;
    }

    public function getLengthCm(): ?float
    {
        return $this->lengthCm;
    }

    public function setLengthCm(float $lengthCm): static
    {
        $this->lengthCm = $lengthCm;
        return $this;
    }

    public function getWidthCm(): ?float
    {
        return $this->widthCm;
    }

    public function setWidthCm(?float $widthCm): static
    {
        $this->widthCm = $widthCm;
        return $this;
    }

    public function getHeightCm(): ?float
    {
        return $this->heightCm;
    }

    public function setHeightCm(?float $heightCm): static
    {
        $this->heightCm = $heightCm;
        return $this;
    }

    // --- FIN DES NOUVEAUX GETTERS/SETTERS ---


    public function getShippingClass(): ?string
    {
        return $this->shippingClass;
    }

    public function setShippingClass(?string $shippingClass): static
    {
        $this->shippingClass = $shippingClass;
        return $this;
    }

    public function getShippingClassEntity(): ?ShippingClass
    {
        return $this->shippingClassEntity;
    }

    public function setShippingClassEntity(?ShippingClass $shippingClassEntity): static
    {
        $this->shippingClassEntity = $shippingClassEntity;
        return $this;
    }


    public function getDescription(): string
    {
        return 'ProductShippingID:' . $this->getId(); 
    }

    public function getWidth(): int
    {
        return (int) round(($this->widthCm ?? 0) * 10);
    }

    public function getLength(): int
    {
        return (int) round(($this->lengthCm ?? 0) * 10);
    }

    public function getDepth(): int
    {
        return (int) round(($this->heightCm ?? 0) * 10);
    }

    public function getWeight(): int 
    {
        return (int) round(($this->weightKg ?? 0) * 1000);
    }
    
    public function getAllowedRotation(): Rotation
    {
        return Rotation::BestFit;
    }
}

