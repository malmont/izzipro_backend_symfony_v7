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

    public static function fromEntity(ProductOptionValue $value, string $host): self
    {
        $imagePath = $value->getImagePreview();
        $fullUrl = null;

        if ($imagePath) {
            if (str_starts_with((string)$imagePath, 'http')) {
                $fullUrl = $imagePath;
            } else {
                $cleanedHost = rtrim($host, '/');
                $fullUrl = $cleanedHost . '/assets/uploads/icons/' . $imagePath;
            }
        }

        return new self(
            $value->getId(),
            $value->getValue(),
            $fullUrl,
            ($value->getPriceDelta() ?? 0.0) * 100, 
            'CAD' 
        
        );
    }
}