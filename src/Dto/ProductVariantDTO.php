<?php
// src/Dto/ProductVariantDTO.php

namespace App\Dto;

use App\Entity\ProductVariant;
use App\Entity\ProductOptionValue;
use App\Entity\ProductOption;

class ProductVariantDTO
{
    public int $id;
    public int $stockQuantity;
    public ?ColorDTO $color = null;
    public ?SizeDTO $size = null;
    public array $options;

    public function __construct(
        int $id,
        int $stockQuantity,
        ?ColorDTO $color,
        ?SizeDTO $size,
        array $options
    ) {
        $this->id = $id;
        $this->stockQuantity = $stockQuantity;
        $this->color = $color;
        $this->size = $size;
        $this->options = $options;
    }

    /**
     * MODIFIÉ : La méthode a maintenant besoin du '$locale'
     * pour gérer la traduction des options.
     */
    public static function fromEntity($variant, string $locale = 'fr'): self
    {
        // --- AJOUT 6 : Logique pour mapper les optionValues ---
        $options = array_map(function (ProductOptionValue $optionValue) use ($locale) {
            $parentOption = $optionValue->getProductOption();
            
            return [
                'value_id'    => $optionValue->getId(),
                // On ajoute un fallback au cas où la traduction n'existerait pas
                'value'       => $optionValue->getTranslation($locale)?->getValue() ?? $optionValue->getValue(),
                'option_name' => $parentOption ? ($parentOption->getTranslation($locale)?->getName() ?? $parentOption->getName()) : null,
                'option_id'   => $parentOption ? $parentOption->getId() : null,
                'option_code' => $parentOption ? $parentOption->getCode() : null,
            ];
        }, $variant->getOptionValues()->toArray());
        // --- FIN DE L'AJOUT ---

        return new self(
            $variant->getId(),
            $variant->getStockQuantity(),
            $variant->getColor() ? ColorDTO::fromEntity($variant->getColor(), $locale) : null,
            $variant->getSize() ? SizeDTO::fromEntity($variant->getSize(), $locale) : null,
            $options 
        );
    }
}