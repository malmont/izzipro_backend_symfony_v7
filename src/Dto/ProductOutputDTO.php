<?php
// src/Dto/ProductOutputDTO.php

namespace App\Dto;

use App\Entity\Product;
use App\Entity\Category; 
use App\Entity\Style;
use App\Entity\Color;
use App\Entity\Size;
use App\Entity\ProductVariant;
use App\Entity\ProductOptionValue;
use App\Entity\ProductOption;
use App\Enum\ProductMode;

class ProductOutputDTO
{
    public int $id;
    public ?string $name;
    public ?string $description;
    public ?float $purchasePrice;
    public ?float $coefficientMultiplier;
    public ?string $slug;
    public ?string $image;
    
    public string $mode;
    public ?array $bookingConfig = null;
    // -------------------------------

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
        

        $this->mode = $product->getMode()->value;

        if ($product->isBookable() && $config = $product->getBookingConfiguration()) {
            $this->bookingConfig = [
                'granularity'   => $config->getGranularity(), 
                'minDuration'   => $config->getMinDuration(),
                'stockQuantity' => $config->getStockQuantity(),
                'bufferTime'    => $config->getBufferTime(),
            ];
        }
        // -----------------------

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


        $this->variants = array_map(function (ProductVariant $variant) use ($locale) {
            $color = $variant->getColor();
            $size = $variant->getSize();

            $options = array_map(function (ProductOptionValue $optionValue) use ($locale) {
                $parentOption = $optionValue->getProductOption();
                
                return [
                    'value_id'    => $optionValue->getId(),
                    'value'       => $optionValue->getTranslation($locale)?->getValue() ?? $optionValue->getValue(),
                    'option_name' => $parentOption ? ($parentOption->getTranslation($locale)?->getName() ?? $parentOption->getName()) : null,
                    'option_id'   => $parentOption ? $parentOption->getId() : null,
                    'option_code' => $parentOption ? $parentOption->getCode() : null,
                ];
            }, $variant->getOptionValues()->toArray());

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
                'options' => $options,
            ];
        }, $product->getVariants()->toArray());
    }
}