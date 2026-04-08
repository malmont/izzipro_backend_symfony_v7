<?php

namespace App\Dto;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\ProductOptionValue;
use App\Enum\ProductMode; 

class ProductOutputCategoryDto
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
    public int $quantity;
    public ?int $freezeQuantity;
    public string $createdAt;
    public ?string $tags; 
    public ?string $slug;
    public ?float $purchasePrice;
    public ?float $coefficientMultiplier;
    public ?string $barcode;
    
    // --- NOUVEAUX CHAMPS POUR LE BOOKING ---
    public string $mode;
    public ?array $bookingConfig = null;
    // ---------------------------------------

    public ?array $style;
    public array $variants;
    public ?array $category;


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
        
        $this->quantity = $product->getQuantity();
        
        $this->freezeQuantity = $product->getFreezeQuantity() ?? 0;
        $this->createdAt = $product->getCreatedAt()->format('Y-m-d H:i:s');
        $this->tags = $productTranslation?->getTags() ?? $product->getTags(); 
        $this->slug = $productTranslation?->getSlug() ?? $product->getSlug();
        $this->purchasePrice = $product->getPurchasePrice();
        $this->coefficientMultiplier = $product->getCoefficientMultiplier();
        $this->barcode = $product->getBarcode();
 
        $this->mode = $product->getMode()->value;

        if ($product->isBookable() && $config = $product->getBookingConfiguration()) {
            $this->bookingConfig = [
                'granularity'   => $config->getGranularity(),
                'minDuration'   => $config->getMinDuration(),
                'stockQuantity' => $config->getStockQuantity(),
                'bufferTime'    => $config->getBufferTime(),
            ];
        }
        // --------------------------------

        $style = $product->getStyle();
        if ($style) {
            if (method_exists($style, '__isInitialized') && !$style->__isInitialized()) {
                if (method_exists($style, '__load')) {
                    $style->__load();
                } else {
                    $style->getName();
                }
            }
            $this->style = [
                'id'   => $style->getId(),
                'name' => $style->getName(),
            ];
        } else {
            $this->style = null;
        }

        $this->variants = array_map(function (ProductVariant $variant) use ($locale){
            $color = $variant->getColor();
            $size = $variant->getSize();
            
            $options = array_map(function (ProductOptionValue $optionValue) use ($locale){
                $parentOption = $optionValue->getProductOption();
                return [
                    'value_id'    => $optionValue->getId(),
                    'value'       => $optionValue->getTranslation($locale)?->getValue(),
                    'option_name' => $parentOption ? $parentOption->getName() : null,
                    'option_id'   => $parentOption ? $parentOption->getId() : null,
                    'option_code' => $parentOption ? $parentOption->getCode() : null,
                ];
            }, $variant->getOptionValues()->toArray());

            return [
                'id' => $variant->getId(),
                'color' => $color ? [
                    'id'       => $color->getId(),
                    'name' => $color->getTranslation($locale)?->getName() ?? $color->getName(),
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

        $this->category = array_map(function ($category) use ($host, $locale) {
            return [
                'id'          => $category->getId(),
                'name'        => $category->getTranslation($locale)?->getName(),
                'description' => $category->getTranslation($locale)?->getDescription(),
                'isRentalCategory' => $category->isRentalCategory(),
                'image'       => $category->getImage() 
                    ? $host . '/assets/uploads/categories/' . $category->getImage() 
                    : null,
            ];
        }, $product->getCategory()->toArray());
        
        // $this->specifications = $product->getSpecifications();
    }
}