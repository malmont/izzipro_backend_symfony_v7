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

    #[ORM\Column]
    private ?float $weight = null;

    #[ORM\Column]
    private ?float $length = null;

    #[ORM\Column(nullable: true)]
    private ?float $width = null;

    #[ORM\Column(nullable: true)]
    private ?float $height = null;

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



    public function setWeight(float $weight): static
    {
        $this->weight = $weight;

        return $this;
    }



    public function setLength(float $length): static
    {
        $this->length = $length;

        return $this;
    }



    public function setWidth(?float $width): static
    {
        $this->width = $width;

        return $this;
    }

    public function getHeight(): ?float
    {
        return $this->height;
    }

    public function setHeight(?float $height): static
    {
        $this->height = $height;

        return $this;
    }

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
        return (int) round($this->width * 10);
    }


    public function getLength(): int
    {
        return (int) round($this->length * 10);
    }


    public function getDepth(): int
    {
        return (int) round($this->height * 10);
    }


    public function getWeight(): int
    {
        return (int) round($this->weight * 1000);
    }
    

    public function getAllowedRotation(): Rotation
    {
        return Rotation::BestFit;
    }
}
