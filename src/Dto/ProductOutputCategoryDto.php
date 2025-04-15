<?php

namespace App\Dto;

use App\Entity\Product;

class ProductOutputCategoryDto
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
    public ?int $freezeQuantity;
    public string $createdAt;
    public ?string $tags;
    public string $slug;
    public ?float $purchasePrice;
    public ?float $coefficientMultiplier;
    public ?string $barcode;
    public ?array $style;
    public array $variants;
    public array $category;

    /**
     * Transforme un objet Product en DTO.
     *
     * @param Product $product L'entité Product à transformer.
     * @param string  $host    L'URL de base pour construire les liens d'images.
     */
    public function __construct(Product $product, string $host)
    {
        // Champs simples
        $this->id = $product->getId();
        $this->name = $product->getName();
        $this->description = $product->getDescription();
        $this->moreinformations = $product->getMoreinformations();
        $this->price = $product->getPrice();
        $this->isbestseller = $product->isIsbestseller();
        $this->isnewarrival = $product->isIsnewarrival();
        $this->isfeatured = $product->isIsfeatured();
        $this->isspecialoffer = $product->isIsspecialoffer();
        $this->image = $product->getImage() 
            ? $host . '/assets/uploads/products/' . $product->getImage() 
            : null;
        $this->quantity = $product->getQuantity();
        $this->freezeQuantity = $product->getFreezeQuantity() ?? 0;
        $this->createdAt = $product->getCreatedAt()->format('Y-m-d H:i:s');
        $this->tags = $product->getTags();
        $this->slug = $product->getSlug();
        $this->purchasePrice = $product->getPurchasePrice();
        $this->coefficientMultiplier = $product->getCoefficientMultiplier();
        $this->barcode = $product->getBarcode();

        // Force l'initialisation du proxy pour la relation Style
        $style = $product->getStyle();
        if ($style) {
            // Si le proxy n'est pas initialisé, on tente de forcer le chargement
            if (method_exists($style, '__isInitialized') && !$style->__isInitialized()) {
                // Si la méthode __load existe, nous l'utilisons pour charger l'objet
                if (method_exists($style, '__load')) {
                    $style->__load();
                } else {
                    // Sinon, accéder à une propriété pour forcer l'initialisation
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

        // Transformation des Variants
        $this->variants = array_map(function ($variant) {
            $color = $variant->getColor();
            $size = $variant->getSize();
            return [
                'id' => $variant->getId(),
                'color' => $color ? [
                    'id'       => $color->getId(),
                    'name'     => $color->getName(),
                    'codeHexa' => $color->getCodeHexa(),
                ] : null,
                'size' => $size ? [
                    'id'   => $size->getId(),
                    'name' => $size->getName(),
                ] : null,
                'stockQuantity' => $variant->getStockQuantity(),
            ];
        }, $product->getVariants()->toArray());

        // Transformation des Categories
        $this->category = array_map(function ($category) use ($host) {
            return [
                'id'          => $category->getId(),
                'name'        => $category->getName(),
                'description' => $category->getDescription(),
                'image'       => $category->getImage() 
                    ? $host . '/assets/uploads/categories/' . $category->getImage() 
                    : null,
            ];
        }, $product->getCategory()->toArray());
    }
}
