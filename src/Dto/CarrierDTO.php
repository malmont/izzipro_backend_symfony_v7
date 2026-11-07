<?php
namespace App\Dto;

use App\Entity\Carrier;

/** Transporteur : prix fixe en cents ; isFree et estimatedDays pour la boutique réglable (08/10/2026) */
class CarrierDTO
{
    public int $id;
    public ?string $name;
    public ?string $photo;
    public ?string $description;
    public ?string $carrierAccountId;
    public float $price;
    public bool $isFree;
    public ?string $estimatedDays;
    /** Nature (lot 1 Livraison, 07/11/2026) : fixed | pickup | easypost ; isPickup = retrait en magasin (aucune adresse d'expédition demandée) */
    public string $kind = 'fixed';
    public bool $isPickup = false;

    public function __construct(int $id, ?string $name, ?string $photo, ?string $carrierAccountId, ?string $description, float $price, bool $isFree = false, ?string $estimatedDays = null, string $kind = 'fixed')
    {
        $this->id = $id;
        $this->name = $name;
        $this->photo = $photo;
        $this->carrierAccountId = $carrierAccountId;
        $this->description = $description;
        $this->price = $price;
        $this->isFree = $isFree;
        $this->estimatedDays = $estimatedDays;
        $this->kind = $kind;
        $this->isPickup = $kind === 'pickup';
    }

    public static function fromEntity(Carrier $carrier, string $host, string $locale): self
    {
        $translation = $carrier->getTranslation($locale);

        return new self(
            $carrier->getId(),
            $translation?->getName() ?? $carrier->getName(),
            $carrier->getPhoto() ? rtrim($host, '/') . '/assets/uploads/Carrier/' . $carrier->getPhoto() : null,
            $carrier->getCarrierAccountId() ?? null,
            $translation?->getDescription() ?? $carrier->getDescription(),
            (float) $carrier->getPrice(),
            $carrier->isFree(),
            $carrier->getEstimatedDays(),
            $carrier->getKind()
        );
    }
}
