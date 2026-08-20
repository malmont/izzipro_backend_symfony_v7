<?php

namespace App\Dto;

class VariantConfigResponseDto
{
    public function __construct(
        public int $variantId,
        public string $productName,
        public string $variantName,
        public float $basePrice,
        public ?string $baseImage,
        public array $options = [],   
        public array $combinations = []
    ) {}
}