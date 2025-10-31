<?php
// src/Dto/ProductDetailedOutputDTO.php

namespace App\Dto;

use App\Entity\Product;
use App\Entity\Categories;
use App\Entity\Style;
use App\Entity\Color;
use App\Entity\Size;
use App\Entity\ProductVariant;
use App\Entity\ProductOptionValue; // <-- AJOUT 1
use App\Entity\ProductOption;    // <-- AJOUT 2

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
            'name' => $product->getStyle()->getName(), // Note : Le style n'est pas traduit ici
        ] : null;
        $this->specifications = $product->getSpecifications();

        // --- BLOC DE VARIANTES MIS À JOUR ---
        $this->variants = array_map(function (ProductVariant $variant) use ($locale) {
            $color = $variant->getColor();
            $size = $variant->getSize();

            // --- AJOUT 3 : On boucle sur les 'optionValues' ---
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
            // --- FIN AJOUT ---

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
                'options' => $options, // <-- AJOUT 4 : On ajoute le tableau
            ];
        }, $product->getVariants()->toArray());
        // --- FIN BLOC ---
    
        $categoriesCollection = $product->getCategory();
        if ($categoriesCollection && !$categoriesCollection->isEmpty()) {
            
            // --- BLOC CATÉGORIE CORRIGÉ ---
            $this->category = array_map(function (Categories $category) use ($locale, $host) { // <-- CORRECTION : Type 'Categories'
                
                // --- CORRECTION : On utilise la traduction de la catégorie, pas du produit ---
                $translation = $category->getTranslation($locale); 
                
                return [
                    'id'          => $category->getId(),
                    'name'        => $translation?->getName() ?? $category->getName(),
                    'description' => $translation?->getDescription() ?? $category->getDescription(),
                    'image'       => $category->getImage()
                        ? rtrim($host, '/') . '/assets/uploads/categories/' . $category->getImage()
                        : null,
                ];
            }, $categoriesCollection->toArray());
            // --- FIN CORRECTION ---

        } else {
            $this->category = []; // Initialisé à un tableau vide, cohérent avec $variants
        }   
    }   
 }