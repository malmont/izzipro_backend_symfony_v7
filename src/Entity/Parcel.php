<?php

namespace App\Entity;

use App\Repository\ParcelRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ParcelRepository::class)]
class Parcel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'parcels')]
    private ?ShippingOrder $shippingOrder = null;

    #[ORM\Column(nullable: true)]
    private ?int $index = null;

    #[ORM\Column(nullable: true)]
    private ?float $weight = null;

    #[ORM\Column(nullable: true)]
    private ?float $length = null;

    #[ORM\Column(nullable: true)]
    private ?float $width = null;

    #[ORM\Column(nullable: true)]
    private ?float $height = null;

    #[ORM\Column(nullable: true)]
    private ?float $price = null;

    #[ORM\OneToOne(mappedBy: 'parcel', cascade: ['persist', 'remove'])]
    private ?ShippingLabel $shippingLabel = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShippingOrder(): ?ShippingOrder
    {
        return $this->shippingOrder;
    }

    public function setShippingOrder(?ShippingOrder $shippingOrder): static
    {
        $this->shippingOrder = $shippingOrder;

        return $this;
    }

    public function getIndex(): ?int
    {
        return $this->index;
    }

    public function setIndex(?int $index): static
    {
        $this->index = $index;

        return $this;
    }

    public function getWeight(): ?float
    {
        return $this->weight;
    }

    public function setWeight(?float $weight): static
    {
        $this->weight = $weight;

        return $this;
    }

    public function getLength(): ?float
    {
        return $this->length;
    }

    public function setLength(?float $length): static
    {
        $this->length = $length;

        return $this;
    }

    public function getWidth(): ?float
    {
        return $this->width;
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

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(?float $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getShippingLabel(): ?ShippingLabel
    {
        return $this->shippingLabel;
    }

    public function setShippingLabel(?ShippingLabel $shippingLabel): static
    {
        // unset the owning side of the relation if necessary
        if ($shippingLabel === null && $this->shippingLabel !== null) {
            $this->shippingLabel->setParcel(null);
        }

        // set the owning side of the relation if necessary
        if ($shippingLabel !== null && $shippingLabel->getParcel() !== $this) {
            $shippingLabel->setParcel($this);
        }

        $this->shippingLabel = $shippingLabel;

        return $this;
    }
}
