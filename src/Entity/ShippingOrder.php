<?php

namespace App\Entity;

use App\Repository\ShippingOrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ShippingOrderRepository::class)]
class ShippingOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'shippingOrder', cascade: ['persist', 'remove'])]
    private ?Order $odershipping = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $carrierAccountId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $service = null;

    #[ORM\Column(nullable: true)]
    private ?float $totalPrice = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $createdAt = null;

    /**
     * @var Collection<int, Parcel>
     */
    #[ORM\OneToMany(mappedBy: 'shippingOrder', targetEntity: Parcel::class)]
    private Collection $parcels;

    public function __construct()
    {
        $this->parcels = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOdershipping(): ?Order
    {
        return $this->odershipping;
    }

    public function setOdershipping(?Order $odershipping): static
    {
        $this->odershipping = $odershipping;

        return $this;
    }

    public function getCarrierAccountId(): ?string
    {
        return $this->carrierAccountId;
    }

    public function setCarrierAccountId(?string $carrierAccountId): static
    {
        $this->carrierAccountId = $carrierAccountId;

        return $this;
    }

    public function getService(): ?string
    {
        return $this->service;
    }

    public function setService(?string $service): static
    {
        $this->service = $service;

        return $this;
    }

    public function getTotalPrice(): ?float
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(?float $totalPrice): static
    {
        $this->totalPrice = $totalPrice;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * @return Collection<int, Parcel>
     */
    public function getParcels(): Collection
    {
        return $this->parcels;
    }

    public function addParcel(Parcel $parcel): static
    {
        if (!$this->parcels->contains($parcel)) {
            $this->parcels->add($parcel);
            $parcel->setShippingOrder($this);
        }

        return $this;
    }

    public function removeParcel(Parcel $parcel): static
    {
        if ($this->parcels->removeElement($parcel)) {
            // set the owning side to null (unless already changed)
            if ($parcel->getShippingOrder() === $this) {
                $parcel->setShippingOrder(null);
            }
        }

        return $this;
    }
}
