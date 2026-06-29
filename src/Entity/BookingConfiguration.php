<?php

namespace App\Entity;

use App\Repository\BookingConfigurationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BookingConfigurationRepository::class)]
class BookingConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'bookingConfiguration', cascade: ['persist'])]
    private ?Product $product = null;

    #[ORM\Column(length: 40)]
    private ?string $granularity = null;

    #[ORM\Column]
    private ?int $stockQuantity = null;

    #[ORM\Column]
    private ?int $minDuration = null;

    #[ORM\Column]
    private ?int $bufferTime = null;

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

    public function getGranularity(): ?string
    {
        return $this->granularity;
    }

    public function setGranularity(string $granularity): static
    {
        $this->granularity = $granularity;

        return $this;
    }

    public function getStockQuantity(): ?int
    {
        return $this->stockQuantity;
    }

    public function setStockQuantity(int $stockQuantity): static
    {
        $this->stockQuantity = $stockQuantity;

        return $this;
    }

    public function getMinDuration(): ?int
    {
        return $this->minDuration;
    }

    public function setMinDuration(int $minDuration): static
    {
        $this->minDuration = $minDuration;

        return $this;
    }

    public function getBufferTime(): ?int
    {
        return $this->bufferTime;
    }

    public function setBufferTime(int $bufferTime): static
    {
        $this->bufferTime = $bufferTime;

        return $this;
    }
}
