<?php

namespace App\Entity;

use App\Repository\PackagingTypeRepository;
use Doctrine\ORM\Mapping as ORM;
use DVDoug\BoxPacker\Box;

#[ORM\Entity(repositoryClass: PackagingTypeRepository::class)]
class PackagingType implements Box
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column(name: "inner_length")]
    private ?float $innerLengthCm = null;

    #[ORM\Column(name: "inner_width")]
    private ?float $innerWidthCm = null;

    #[ORM\Column]
    private ?float $innerHeight = null; 

    #[ORM\Column(name: "max_weight")]
    private ?float $maxWeightKg = null;

    #[ORM\Column]
    private ?int $volumetricDivisor = null;

    #[ORM\Column(name: "outer_length", nullable: true)]
    private ?float $outerLengthCm = null;

    #[ORM\Column(name: "outer_width", nullable: true)]
    private ?float $outerWidthCm = null;

    #[ORM\Column(nullable: true)]
    private ?float $outerHeight = null;

    #[ORM\Column(name: "empty_weight", nullable: true)]
    private ?float $emptyWeightKg = null;


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

    public function getInnerLengthCm(): ?float
    {
        return $this->innerLengthCm;
    }

    public function setInnerLengthCm(float $innerLengthCm): static
    {
        $this->innerLengthCm = $innerLengthCm;
        return $this;
    }

    public function getInnerWidthCm(): ?float
    {
        return $this->innerWidthCm;
    }

    public function setInnerWidthCm(float $innerWidthCm): static
    {
        $this->innerWidthCm = $innerWidthCm;
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

    public function getMaxWeightKg(): ?float
    {
        return $this->maxWeightKg;
    }

    public function setMaxWeightKg(float $maxWeightKg): static
    {
        $this->maxWeightKg = $maxWeightKg;
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

    public function getOuterLengthCm(): ?float
    {
        return $this->outerLengthCm;
    }

    public function setOuterLengthCm(?float $outerLengthCm): static
    {
        $this->outerLengthCm = $outerLengthCm;
        return $this;
    }

    public function getOuterWidthCm(): ?float
    {
        return $this->outerWidthCm;
    }

    public function setOuterWidthCm(?float $outerWidthCm): static
    {
        $this->outerWidthCm = $outerWidthCm;
        return $this;
    }

    public function getOuterHeight(): ?float
    {
        return $this->outerHeight;
    }

    public function setOuterHeight(?float $outerHeight): static
    {
        $this->outerHeight = $outerHeight;
        return $this;
    }

    public function getEmptyWeightKg(): ?float
    {
        return $this->emptyWeightKg;
    }

    public function setEmptyWeightKg(?float $emptyWeightKg): static
    {
        $this->emptyWeightKg = $emptyWeightKg;
        return $this;
    }


    public function getReference(): string
    {
        return $this->getName() . ' (ID:' . $this->getId() . ')';
    }

    public function getOuterWidth(): int
    {
        return (int) round($this->outerWidthCm * 10); 
    }

    public function getOuterLength(): int
    {
        return (int) round($this->outerLengthCm * 10);
    }

    public function getOuterDepth(): int
    {
        return (int) round($this->outerHeight * 10);
    }

    public function getInnerWidth(): int
    {
        return (int) round($this->innerWidthCm * 10);
    }

    public function getInnerLength(): int
    {
        return (int) round($this->innerLengthCm * 10);
    }

    public function getInnerDepth(): int // Non-conflictuel
    {
        return (int) round($this->getInnerHeight() * 10);
    }

    public function getEmptyWeight(): int
    {
        return (int) round($this->emptyWeightKg * 1000);
    }

    public function getMaxWeight(): int
    {
        return (int) round($this->maxWeightKg * 1000);
    }
}

