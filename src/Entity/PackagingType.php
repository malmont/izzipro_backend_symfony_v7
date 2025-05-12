<?php

namespace App\Entity;

use App\Repository\PackagingTypeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PackagingTypeRepository::class)]
class PackagingType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column]
    private ?float $innerLength = null;

    #[ORM\Column]
    private ?float $innerWidth = null;

    #[ORM\Column]
    private ?float $innerHeight = null;

    #[ORM\Column]
    private ?float $maxWeight = null;

    #[ORM\Column]
    private ?int $volumetricDivisor = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getInnerLength(): ?float
    {
        return $this->innerLength;
    }

    public function setInnerLength(float $innerLength): static
    {
        $this->innerLength = $innerLength;

        return $this;
    }

    public function getInnerWidth(): ?float
    {
        return $this->innerWidth;
    }

    public function setInnerWidth(float $innerWidth): static
    {
        $this->innerWidth = $innerWidth;

        return $this;
    }

    public function getInnerHeight(): ?float
    {
        return $this->innerHeight;
    }

    public function setInnerHeight(float $innerHeight): static
    {
        $this->innerHeight = $innerHeight;

        return $this;
    }

    public function getMaxWeight(): ?float
    {
        return $this->maxWeight;
    }

    public function setMaxWeight(float $maxWeight): static
    {
        $this->maxWeight = $maxWeight;

        return $this;
    }

    public function getVolumetricDivisor(): ?int
    {
        return $this->volumetricDivisor;
    }

    public function setVolumetricDivisor(int $volumetricDivisor): static
    {
        $this->volumetricDivisor = $volumetricDivisor;

        return $this;
    }
}
