<?php
namespace App\Dto;

use App\Entity\Carrier;

class CarrierDTO
{
    public int $id;
    public ?string $name;
    public ?string $photo;
    public ?string $description; 
    public ?string $carrierAccountId;
    public float $price;

    public function __construct(int $id, ?string $name, ?string $photo, ?string $carrierAccountId, ?string $description, float $price)
    {
        $this->id = $id;
        $this->name = $name;
        $this->photo = $photo;
        $this->carrierAccountId = $carrierAccountId;
        $this->description = $description;
        $this->price = $price;
    }

    public static function fromEntity(Carrier $carrier, string $host, string $locale): self
    {
         $translation = $carrier->getTranslation($locale);
        // TODO: Après la migration, on "branchera" la logique de traduction ici
        return new self(
            $carrier->getId(),
            $carrier->getName(),
            $carrier->getPhoto() ? rtrim($host, '/') . '/assets/uploads/Carrier/' . $carrier->getPhoto() : null,
            $carrier->getCarrierAccountId() ?? null,
            $carrier->getDescription(),
            $carrier->getPrice()
        );
    }
}