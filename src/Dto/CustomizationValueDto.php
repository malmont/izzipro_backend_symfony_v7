<?php

namespace App\Dto;
use App\Entity\ProductOptionValue;

class CustomizationValueDto
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $iconUrl,
        public float $priceDelta,
        public string $currency = 'EUR'
    ) {}

    /** @param ?string $iconUrl adresse résolue par CustomizationMediaResolver (null : pas d'icône ou fichier introuvable) */
    public static function fromEntity(ProductOptionValue $value, ?string $iconUrl): self
    {
        return new self($value->getId(), $value->getValue(), $iconUrl, ($value->getPriceDelta() ?? 0.0) * 100, 'CAD');
    }
}
