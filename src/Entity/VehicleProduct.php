<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ApiResource]
class VehicleProduct extends Product
{
    #[ORM\Column(nullable: true)]
    private ?int $year = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $brand = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vin = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $transmission = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $gasType = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $enginePower = null;

    #[ORM\Column(nullable: true)]
    private ?int $hoursOrMileage = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $vehicleCondition = null; // 'neuf', 'occasion'

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $color = null;

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(?int $year): static
    {
        $this->year = $year;
        return $this;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function setBrand(?string $brand): static
    {
        $this->brand = $brand;
        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): static
    {
        $this->model = $model;
        return $this;
    }

    public function getVin(): ?string
    {
        return $this->vin;
    }

    public function setVin(?string $vin): static
    {
        $this->vin = $vin;
        return $this;
    }

    public function getTransmission(): ?string
    {
        return $this->transmission;
    }

    public function setTransmission(?string $transmission): static
    {
        $this->transmission = $transmission;
        return $this;
    }

    public function getGasType(): ?string
    {
        return $this->gasType;
    }

    public function setGasType(?string $gasType): static
    {
        $this->gasType = $gasType;
        return $this;
    }

    public function getEnginePower(): ?string
    {
        return $this->enginePower;
    }

    public function setEnginePower(?string $enginePower): static
    {
        $this->enginePower = $enginePower;
        return $this;
    }

    public function getHoursOrMileage(): ?int
    {
        return $this->hoursOrMileage;
    }

    public function setHoursOrMileage(?int $hoursOrMileage): static
    {
        $this->hoursOrMileage = $hoursOrMileage;
        return $this;
    }

    public function getVehicleCondition(): ?string
    {
        return $this->vehicleCondition;
    }

    public function setVehicleCondition(?string $vehicleCondition): static
    {
        $this->vehicleCondition = $vehicleCondition;
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
}
