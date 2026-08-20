<?php

namespace App\Entity;

use App\Repository\BookingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BookingRepository::class)]
class Booking
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'bookings')]
    private ?Product $product = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $startAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $endAt = null;

    #[ORM\Column]
    private ?int $quantity = null;

    #[ORM\Column(length: 40)]
    private ?string $status = null;

    #[ORM\Column(nullable: true)]
    private ?int $rentalPackId = null;

    #[ORM\OneToOne(inversedBy: 'booking', targetEntity: OrderItems::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true)]
    private ?OrderItems $orderItem = null;
#[ORM\Column(options: ['default' => false])]
    private bool $isFinalized = false;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->isFinalized = false;
    }

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

    public function getStartAt(): ?\DateTimeImmutable
    {
        return $this->startAt;
    }

    public function setStartAt(\DateTimeImmutable $startAt): static
    {
        $this->startAt = $startAt;

        return $this;
    }

    public function getEndAt(): ?\DateTimeImmutable
    {
        return $this->endAt;
    }

    public function setEndAt(\DateTimeImmutable $endAt): static
    {
        $this->endAt = $endAt;

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

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }
    public function getOrderItem(): ?OrderItems
    {
        return $this->orderItem;
    }

    public function setOrderItem(?OrderItems $orderItem): self
    {
        $this->orderItem = $orderItem;
        return $this;
    }

    public function getRentalPackId(): ?int
    {
        return $this->rentalPackId;
    }

    public function setRentalPackId(?int $rentalPackId): static
    {
        $this->rentalPackId = $rentalPackId;

        return $this;
    }

    public function isFinalized(): bool
    {
        return $this->isFinalized;
    }

    public function setIsFinalized(bool $isFinalized): static
    {
        $this->isFinalized = $isFinalized;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
