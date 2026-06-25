<?php

namespace App\Dto;

use App\Entity\Vehicle;

class VehicleOutputDto
{
    public int $id;
    public int $gemsuiteVehicleId;
    public ?int $productId = null;
    public ?string $productName = null;
    public ?float $productPrice = null;
    public ?string $title;
    public ?string $description;
    public ?string $picture;
    public ?int $year;
    public ?string $color;
    public ?int $transmission;
    public ?int $gasType;
    public ?bool $newVehicle;
    public ?bool $featuredVehicle;
    public ?bool $webDisplay;
    public ?string $slug;

    public function __construct(Vehicle $vehicle)
    {
        $this->id = $vehicle->getId();
        $this->gemsuiteVehicleId = $vehicle->getGemsuiteVehicleId();
        
        $product = $vehicle->getProduct();
        if ($product) {
            $this->productId = $product->getId();
            $this->productName = $product->getName();
            $this->productPrice = $product->getPrice();
        }

        $this->title = $vehicle->getTitle();
        $this->description = $vehicle->getDescription();
        $this->picture = $vehicle->getPicture();
        $this->year = $vehicle->getYear();
        $this->color = $vehicle->getColor();
        $this->transmission = $vehicle->getTransmission();
        $this->gasType = $vehicle->getGasType();
        $this->newVehicle = $vehicle->getNewVehicle();
        $this->featuredVehicle = $vehicle->getFeaturedVehicle();
        $this->webDisplay = $vehicle->getWebDisplay();
        $this->slug = $vehicle->getSlug();
    }
}
