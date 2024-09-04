<?php
namespace App\Dto;

class CarrierDTO
{
    public int $id;
    public string $name;
    public ?string $photo;
    public string $description;
    public float $price;

    public function __construct(int $id, string $name, ?string $photo, string $description, float $price)
    {
        $this->id = $id;
        $this->name = $name;
        $this->photo = $photo;
        $this->description = $description;
        $this->price = $price;
    }

    public static function fromEntity($carrier, string $host): self
    {
        return new self(
            $carrier->getId(),
            $carrier->getName(),
            $carrier->getPhoto() ? $host . '/assets/uploads/Carrier/' . $carrier->getPhoto() : null,
            $carrier->getDescription(),
            $carrier->getPrice()
        );
    }
}
