<?php
// src/Dto/ProductOutputDTO.php

namespace App\Dto;

use App\Entity\Product;
use App\Entity\Category; 
use App\Entity\Style;
use App\Entity\Color;
use App\Entity\Size;
use App\Entity\ProductVariant;
use App\Entity\ProductOptionValue; // <-- AJOUT 1 : On importe l'entité
use App\Entity\ProductOption; // <-- AJOUT 2 : On importe le parent

class ProductOutputDTO
{
    public int $id;
    public ?string $name;
    public ?string $description;
    public ?float $purchasePrice;
    public ?float $coefficientMultiplier;
    public ?string $slug;
    public ?string $image;
    public ?array $categories;
    public ?array $style;
    public array $variants;
    public ?array $specifications;

    public function __construct(Product $product, string $host, string $locale = 'fr')
    {
        $productTranslation = $product->getTranslation($locale);
        $this->id = $product->getId();
        $this->name = $productTranslation?->getName() ?? $product->getName();
        $this->description = $productTranslation?->getDescription() ?? $product->getDescription();
        $this->purchasePrice = $product->getPurchasePrice();
        $this->coefficientMultiplier = $product->getCoefficientMultiplier();
        $this->slug = $productTranslation?->getSlug() ?? $product->getSlug();
        $this->specifications = $product->getSpecifications();
        $imagePath = $product->getImage();
        if (empty($imagePath)) {
            $this->image = null;
        } elseif (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')) {
            $this->image = $imagePath;
        } else {
            $cleanedHost = rtrim($host, '/');
            $this->image = $cleanedHost . '/assets/uploads/products/' . $imagePath;
        }
        
        // ... (votre logique pour 'categories' et 'style' reste inchangée) ...
        $categoriesCollection = $product->getCategory();
        if ($categoriesCollection && !$categoriesCollection->isEmpty()) {
            $this->categories = array_map(function (Category $category) use ($locale) {
                 return [
                    'id'          => $category->getId(),
                    'name'        => $category->getTranslation($locale)?->getName(),
                    'description' => $category->getTranslation($locale)?->getDescription(),
                ];
            }, $categoriesCollection->toArray());
        } else {
            $this->categories = null;
        }

        $this->style = $product->getStyle() ? [
            'id' => $product->getStyle()->getId(),
            'name' => $product->getStyle()->getName(),
        ] : null;


        // --- DÉBUT DU BLOC MODIFIÉ ---
        $this->variants = array_map(function (ProductVariant $variant) use ($locale) {
            $color = $variant->getColor();
            $size = $variant->getSize();

            // --- AJOUT 3 : On boucle sur les 'optionValues' de la variante ---
            $options = array_map(function (ProductOptionValue $optionValue) use ($locale) {
                $parentOption = $optionValue->getProductOption();
                
                return [
                    'value_id'    => $optionValue->getId(),
                    'value'       => $optionValue->getTranslation($locale)?->getValue() ?? $optionValue->getValue(),
                    'option_name' => $parentOption ? ($parentOption->getTranslation($locale)?->getName() ?? $parentOption->getName()) : null,
                    'option_id'   => $parentOption ? $parentOption->getId() : null,
                    'option_code' => $parentOption ? $parentOption->getCode() : null, // Très utile pour le front !
                ];
            }, $variant->getOptionValues()->toArray());
            // --- FIN DE L'AJOUT ---

            return [
                'id' => $variant->getId(),
                'color' => $color ? [
                    'id'       => $color->getId(),
                    'name'     => $color->getTranslation($locale)?->getName(),
                    'codeHexa' => $color->getCodeHexa(),
                ] : null,
                'size' => $size ? [
                    'id'   => $size->getId(),
                    'name' => $size->getTranslation($locale)?->getName(),
                ] : null,
                'stockQuantity' => $variant->getStockQuantity(),
                'options' => $options, // <-- AJOUT 4 : On ajoute le tableau d'options à la variante
            ];
        }, $product->getVariants()->toArray());
        // --- FIN DU BLOC MODIFIÉ ---
    }
}