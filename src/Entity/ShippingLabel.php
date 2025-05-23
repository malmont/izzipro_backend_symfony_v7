<?php

namespace App\Entity;

use App\Repository\ShippingLabelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ShippingLabelRepository::class)]
class ShippingLabel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'shippingLabel', cascade: ['persist', 'remove'])]
    private ?Parcel $parcel = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $labelUrl = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $trackingCode = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
private ?\DateTimeInterface $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParcel(): ?Parcel
    {
        return $this->parcel;
    }

    public function setParcel(?Parcel $parcel): static
    {
        $this->parcel = $parcel;

        return $this;
    }

    public function getLabelUrl(): ?string
    {
        return $this->labelUrl;
    }

    public function setLabelUrl(?string $labelUrl): static
    {
        $this->labelUrl = $labelUrl;

        return $this;
    }

    public function getTrackingCode(): ?string
    {
        return $this->trackingCode;
    }

    public function setTrackingCode(?string $trackingCode): static
    {
        $this->trackingCode = $trackingCode;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
