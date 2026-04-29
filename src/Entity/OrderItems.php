<?php

namespace App\Entity;

use App\Repository\OrderItemsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderItemsRepository::class)]
class OrderItems
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'orderItems')]
    private ?Order $orderAssociated = null;

    #[ORM\ManyToOne(inversedBy: 'orderItems')]
    private ?ProductVariant $productVariant = null;

    #[ORM\Column]
    private ?int $quantity = null;

    #[ORM\Column]
    private ?float $unitPrice = null;

    #[ORM\Column]
    private ?float $totalPrice = null;

    #[ORM\OneToOne(mappedBy: 'orderItem', targetEntity: Booking::class, cascade: ['persist', 'remove'])]
    private ?Booking $booking = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $licenseNumber = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $licenseExpirationDate = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderAssociated(): ?Order
    {
        return $this->orderAssociated;
    }

    public function setOrderAssociated(?Order $orderAssociated): static
    {
        $this->orderAssociated = $orderAssociated;

        return $this;
    }

    public function getProductVariant(): ?ProductVariant
    {
        return $this->productVariant;
    }

    public function setProductVariant(?ProductVariant $productVariant): static
    {
        $this->productVariant = $productVariant;

        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUnitPrice(): ?float
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(float $unitPrice): static
    {
        $this->unitPrice = $unitPrice;

        return $this;
    }

    public function getTotalPrice(): ?float
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(float $totalPrice): static
    {
        $this->totalPrice = $totalPrice;

        return $this;
    }

    public function __toString(): string
    {
        return $this->productVariant ? $this->productVariant->getProduct()->getName() . ' - ' . $this->quantity . ' pcs' : '';
    }
    public function getBooking(): ?Booking
    {
        return $this->booking;
    }

    public function setBooking(?Booking $booking): self
    {
        if ($booking !== null && $booking->getOrderItem() !== $this) {
            $booking->setOrderItem($this);
        }
        $this->booking = $booking;
        return $this;
    }
    public function getLicenseNumber(): ?string
    {
        return $this->licenseNumber;
    }

    public function setLicenseNumber(?string $licenseNumber): self
    {
        $this->licenseNumber = $licenseNumber;
        return $this;
    }

    public function getLicenseExpirationDate(): ?\DateTimeInterface
    {
        return $this->licenseExpirationDate;
    }

    public function setLicenseExpirationDate(?\DateTimeInterface $licenseExpirationDate): self
    {
        $this->licenseExpirationDate = $licenseExpirationDate;
        return $this;
    }
}
