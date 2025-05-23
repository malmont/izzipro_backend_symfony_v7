<?php
namespace App\Dto;

class CarrierDTO
{
    public int $id;
    public string $name;
    public ?string $photo;
    public string $description;
    public ?string $carrierAccountId;
    public float $price;

    public function __construct(int $id, string $name, ?string $photo,?string $carrierAccountId = null, string $description, float $price)
    {
        $this->id = $id;
        $this->name = $name;
        $this->photo = $photo;
        $this->carrierAccountId = $carrierAccountId;
        $this->description = $description;
        $this->price = $price;
    }

    public static function fromEntity($carrier, string $host): self
    {
        return new self(
            $carrier->getId(),
            $carrier->getName(),
            $carrier->getPhoto() ? $host . '/assets/uploads/Carrier/' . $carrier->getPhoto() : null,
            $carrier->getCarrierAccountId()??'',
            $carrier->getDescription(),
            $carrier->getPrice()
        );
    }
}
