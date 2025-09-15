<?php
namespace App\Dto;

use App\Entity\Product;
use App\Entity\Categories;
use App\Entity\Style;
use App\Entity\Color;
use App\Entity\Size;
use App\Entity\ProductVariant;

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
    public int $quantity;
    public ?int $freezeQuantity = null;
    public string $createdAt;
    public ?string $tags;
    public ?string $slug;
    public ?float $purchasePrice;
    public ?float $coefficientMultiplier;
    public ?string $barcode;
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
        $this->quantity = $product->getQuantity();
        $this->freezeQuantity = $product->getFreezeQuantity() ?? 0;
        $this->createdAt = $product->getCreatedAt()->format('Y-m-d H:i:s');
        $this->tags = $productTranslation?->getTags() ?? $product->getTags();
        $this->slug = $productTranslation?->getSlug() ?? $product->getSlug();
        $this->purchasePrice = $product->getPurchasePrice();
        $this->coefficientMultiplier = $product->getCoefficientMultiplier();
        $this->barcode = $product->getBarcode();
    
        $this->style = $product->getStyle() ? [
            'id' => $product->getStyle()->getId(),
            'name' => $product->getStyle()->getName(),
        ] : null;
        $this->specifications = $product->getSpecifications();
        $this->variants = array_map(function (ProductVariant $variant) use ($locale) {
            $color = $variant->getColor();
            $size = $variant->getSize();
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
            ];
        }, $product->getVariants()->toArray());
    
        $categoriesCollection = $product->getCategory();
        if ($categoriesCollection && !$categoriesCollection->isEmpty()) {
            $this->category = array_map(function (Categories $category) use ($locale) {
                return [
                    'id'          => $category->getId(),
                    'name'        => $category->getTranslation($locale)?->getName(),
                    'description' => $category->getTranslation($locale)?->getDescription(),
                    'image'       => $category->getImage(),
                ];
            }, $categoriesCollection->toArray());
        } else {
            $this->category = [];
        }   
    }   
 }
    