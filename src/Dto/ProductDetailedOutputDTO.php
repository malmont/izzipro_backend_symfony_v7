<?php
namespace App\Dto;

use App\Entity\Product;

class ProductDetailedOutputDTO
{
    public int $id;
    public string $name;
    public string $description;
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
    public string $slug;
    public ?float $purchasePrice;
    public ?float $coefficientMultiplier;
    public ?string $barcode;
    public ?array $style;
    public array $variants;
    public array $category;

    public function __construct(Product $product, string $host)
    {
        $this->id = $product->getId();
        $this->name = $product->getName();
        $this->description = $product->getDescription();
        $this->moreinformations = $product->getMoreinformations();
        $this->price = $product->getPrice();
        $this->isbestseller = $product->isIsbestseller();
        $this->isnewarrival = $product->isIsnewarrival();
        $this->isfeatured = $product->isIsfeatured();
        $this->isspecialoffer = $product->isIsspecialoffer();
        $this->image = $product->getImage() ? $host . '/assets/uploads/products/' . $product->getImage() : null;
        $this->quantity = $product->getQuantity();
        $this->freezeQuantity = $product->getFreezeQuantity() ?? 0; // Option 2: valeur par défaut
        $this->createdAt = $product->getCreatedAt()->format('Y-m-d H:i:s');
        $this->tags = $product->getTags();
        $this->slug = $product->getSlug();
        $this->purchasePrice = $product->getPurchasePrice();
        $this->coefficientMultiplier = $product->getCoefficientMultiplier();
        $this->barcode = $product->getBarcode();
    
        // Style
        $this->style = $product->getStyle() ? [
            'id' => $product->getStyle()->getId(),
            'name' => $product->getStyle()->getName(),
        ] : null;
    
        // Variants
        $this->variants = array_map(function ($variant) {
            return [
                'id' => $variant->getId(),
                'color' => $variant->getColor() ? [
                    'id' => $variant->getColor()->getId(),
                    'name' => $variant->getColor()->getName(),
                    'codeHexa' => $variant->getColor()->getCodeHexa(),
                ] : null,
                'size' => $variant->getSize() ? [
                    'id' => $variant->getSize()->getId(),
                    'name' => $variant->getSize()->getName(),
                ] : null,
                'stockQuantity' => $variant->getStockQuantity(),
            ];
        }, $product->getVariants()->toArray());
    
        // Categories (changer en 'category' pour correspondre à l'ancienne version)
        $this->category = array_map(function ($category) {
            return [
                'id' => $category->getId(),
                'name' => $category->getName(),
                'description' => $category->getDescription(),
                'image' => $category->getImage(),
            ];
        }, $product->getCategory()->toArray());   
    }   
 }
    