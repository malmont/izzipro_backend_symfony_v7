<?php
// src/Dto/ProductDetailedOutputDTO.php

namespace App\Dto;

use App\Entity\Product;
use App\Entity\Categories;
use App\Entity\Style;
use App\Entity\Color;
use App\Entity\Size;
use App\Entity\ProductVariant;
use App\Entity\ProductOptionValue;
use App\Entity\ProductOption;
use App\Enum\ProductMode; // <-- AJOUT IMPORTANT

class ProductDetailedOutputDTO
{
    public int $id;
    public ?string $name;
    public ?string $description;
    public ?string $moreinformations;
    public float $price;
    public bool $isbestseller;
    public bool $isnewarrival;
    public bool $isfeatured;
    public bool $isspecialoffer;
    public ?string $image;
    public array $pictures = [];
    public int $quantity;
    public ?int $freezeQuantity = null;
    public string $createdAt;
    public ?string $tags;
    public ?string $slug;
    public ?float $purchasePrice;
    public ?float $coefficientMultiplier;
    public ?string $barcode;
    public ?array $saleUnit = null;
    
    
    // --- NOUVEAUX CHAMPS BOOKING ---
    public string $mode;
    public ?array $bookingConfig = null;
    // -------------------------------

    public ?array $style;
    public array $variants;
    public ?array $category;
    public ?array $specifications;

    public function __construct(Product $product, string $host, string $locale = 'fr')
    {
        $productTranslation = $product->getTranslation($locale);
        $this->id = $product->getId();
        $this->name = $productTranslation?->getName() ?? $product->getName();
        $this->description = $productTranslation?->getDescription() ?? $product->getDescription();
        $this->moreinformations = $productTranslation?->getMoreinformations() ?? $product->getMoreinformations();
        $this->price = $product->getPrice();
        $this->isbestseller = $product->isIsbestseller();
        $this->isnewarrival = $product->isIsnewarrival();
        $this->isfeatured = $product->isIsfeatured();
        $this->isspecialoffer = $product->isIsspecialoffer();
        
        $imagePath = $product->getImage();
        if (empty($imagePath)) {
            $this->image = null;
        } elseif (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')) {
            $this->image = $imagePath;
        } else {
            $cleanedHost = rtrim($host, '/');
            $this->image = $cleanedHost . '/assets/uploads/products/' . $imagePath;
        }

        // Pour le booking, ceci retourne la capacité totale (Pool)
        $this->quantity = $product->getQuantity();
        
        $this->freezeQuantity = $product->getFreezeQuantity() ?? 0;
        $this->createdAt = $product->getCreatedAt()->format('Y-m-d H:i:s');
        $this->tags = $productTranslation?->getTags() ?? $product->getTags();
        $this->slug = $productTranslation?->getSlug() ?? $product->getSlug();
        $this->purchasePrice = $product->getPurchasePrice();
        $this->coefficientMultiplier = $product->getCoefficientMultiplier();
        $this->barcode = $product->getBarcode();

        // --- LOGIQUE BOOKING ---
        // 1. Le Mode (retail vs booking)
        $this->mode = $product->getMode()->value;

        $this->pictures = array_map(function ($picture) use ($host) {
            $path = $picture->getImageUrl();
            $cleanedHost = rtrim($host, '/');
            return [
                'id' => $picture->getId(),
                'url' => str_starts_with($path, 'http') ? $path : $cleanedHost . '/assets/uploads/products/' . $path,
            ];
        }, (array)($product->getPictures() ? $product->getPictures()->toArray() : []));

        // 2. La Config (pour le calendrier Front)

        if ($product->isBookable() && $config = $product->getBookingConfiguration()) {
            $this->bookingConfig = [
                'granularity'   => $config->getGranularity(),
                'minDuration'   => $config->getMinDuration(),
                'stockQuantity' => $config->getStockQuantity(),
                'bufferTime'    => $config->getBufferTime(),
            ];

            $packs = $product->getRentalPacks();
            if (!empty($packs)) {
                $this->bookingConfig['rates'] = array_map(fn($pack) => [
                    'id'          => $pack->getId(),
                    'name'        => $pack->getTranslation($locale)?->getName() ?? $pack->getName(),
                    'hourRate'    => $pack->getHourRate(),
                    'halfDayRate' => $pack->getHalfDayRate(),
                    'dayRate'     => $pack->getDayRate(),
                    'weekRate'    => $pack->getWeekRate(),
                    'monthRate'   => $pack->getMonthRate(),
                ], $packs);
            } else {
                $this->bookingConfig['rates'] = [];
            }
        }
        // -----------------------

        $this->saleUnit = $product->getSaleUnit() ? [
            'id' => $product->getSaleUnit()->getId(),
            'name' => $product->getSaleUnit()->getName(),
        ] : null;


    
        $this->style = $product->getStyle() ? [
            'id' => $product->getStyle()->getId(),
            'name' => $product->getStyle()->getName(),
        ] : null;
        $this->specifications = $product->getSpecifications();

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
                    'name'     => $color->getTranslation($locale)?->getName() ?? $color->getName(),
                    'codeHexa' => $color->getCodeHexa(),
                ] : null,
                'size' => $size ? [
                    'id'   => $size->getId(),
                    'name' => $size->getTranslation($locale)?->getName() ?? $size->getName(),
                ] : null,
                'stockQuantity' => $variant->getStockQuantity(),
                'options' => $options, 
            ];
        }, $product->getVariants()->toArray());
    
        $categoriesCollection = $product->getCategory();
        if ($categoriesCollection && !$categoriesCollection->isEmpty()) {
            
            $this->category = array_map(function (Categories $category) use ($locale, $host) {
                
                $translation = $category->getTranslation($locale); 
                
                return [
                    'id'          => $category->getId(),
                    'name'        => $translation?->getName() ?? $category->getName(),
                    'description' => $translation?->getDescription() ?? $category->getDescription(),
                    'isRentalCategory' => $category->isRentalCategory(),
                    'syncWeb'     => $category->isSyncWeb(),
                    'isVisible'   => $category->isVisible(),
                    'image'       => $category->getImage()
                        ? rtrim($host, '/') . '/assets/uploads/categories/' . $category->getImage()
                        : null,
                ];
            }, $categoriesCollection->toArray());

        } else {
            $this->category = []; 
        }   
    }   
}