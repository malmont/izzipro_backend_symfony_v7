<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\VehicleRepository;
use Doctrine\DBAL\Types\Types;
use ApiPlatform\Metadata\ApiResource;

#[ORM\Entity(repositoryClass: VehicleRepository::class)]
#[ApiResource]
class Vehicle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $gemsuiteVehicleId = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $picture = null;

    #[ORM\Column(nullable: true)]
    private ?int $year = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(nullable: true)]
    private ?int $transmission = null;

    #[ORM\Column(nullable: true)]
    private ?int $gasType = null;

    #[ORM\Column(nullable: true)]
    private ?bool $newVehicle = null;

    #[ORM\Column(nullable: true)]
    private ?bool $featuredVehicle = null;

    #[ORM\Column(nullable: true)]
    private ?bool $webDisplay = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $slug = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGemsuiteVehicleId(): ?int
    {
        return $this->gemsuiteVehicleId;
    }

    public function setGemsuiteVehicleId(int $gemsuiteVehicleId): static
    {
        $this->gemsuiteVehicleId = $gemsuiteVehicleId;

        return $this;
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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getPicture(): ?string
    {
        return $this->picture;
    }

    public function setPicture(?string $picture): static
    {
        $this->picture = $picture;

        return $this;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(?int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getTransmission(): ?int
    {
        return $this->transmission;
    }

    public function setTransmission(?int $transmission): static
    {
        $this->transmission = $transmission;

        return $this;
    }

    public function getGasType(): ?int
    {
        return $this->gasType;
    }

    public function setGasType(?int $gasType): static
    {
        $this->gasType = $gasType;

        return $this;
    }

    public function getNewVehicle(): ?bool
    {
        return $this->newVehicle;
    }

    public function setNewVehicle(?bool $newVehicle): static
    {
        $this->newVehicle = $newVehicle;

        return $this;
    }

    public function getFeaturedVehicle(): ?bool
    {
        return $this->featuredVehicle;
    }

    public function setFeaturedVehicle(?bool $featuredVehicle): static
    {
        $this->featuredVehicle = $featuredVehicle;

        return $this;
    }

    public function getWebDisplay(): ?bool
    {
        return $this->webDisplay;
    }

    public function setWebDisplay(?bool $webDisplay): static
    {
        $this->webDisplay = $webDisplay;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }
}
