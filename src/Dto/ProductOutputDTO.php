<?php
namespace App\Dto;

use App\Entity\Product;

class ProductOutputDTO
{
    public int $id;
    public string $name;
    public string $description;
    public ?float $purchasePrice;
    public ?float $coefficientMultiplier;
    public string $slug;
    public ?string $image;
    public array $categories;
    public ?array $style;
    public array $variants;

    public function __construct(Product $product, string $host)
    {
        $this->id = $product->getId();
        $this->name = $product->getName();
        $this->description = $product->getDescription();
        $this->purchasePrice = $product->getPurchasePrice();
        $this->coefficientMultiplier = $product->getCoefficientMultiplier();
        $this->slug = $product->getSlug();
        $this->image = $product->getImage() ?: null;
        $this->categories = array_map(fn($category) => [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'description' => $category->getDescription(),
        ], $product->getCategory()->toArray());

        $this->style = $product->getStyle() ? [
            'id' => $product->getStyle()->getId(),
            'name' => $product->getStyle()->getName(),
        ] : null;
        $this->variants = array_map(fn($variant) => [
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
        ], $product->getVariants()->toArray());
    }
}
