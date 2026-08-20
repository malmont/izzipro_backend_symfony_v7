<?php

namespace App\Dto;
use App\Entity\ProductCustomizationImage;

class CustomizationCombinationDto
{
    public function __construct(
        public int $id,
        public array $optionIds,
        public ?string $imageUrl,
        public int $stock,
        public float $totalPrice
    ) {}

    public static function fromEntity(
        ProductCustomizationImage $image, 
        float $basePrice, 
        array $optionIds, 
        float $optionPriceDelta, 
        string $host
    ): self
    {
        $imagePath = $image->getImagePath();
        $fullUrl = null;

        if ($imagePath) {
            if (str_starts_with((string)$imagePath, 'http')) {
                $fullUrl = $imagePath;
            } else {
                $cleanedHost = rtrim($host, '/');
                $fullUrl = $cleanedHost . '/assets/uploads/products/' . $imagePath;
            }
        }

        return new self(
            $image->getId(),
            $optionIds,
            $fullUrl,
            $image->getNumberOfPieces() ?? 0,
            $basePrice + $optionPriceDelta
        );
    }
}