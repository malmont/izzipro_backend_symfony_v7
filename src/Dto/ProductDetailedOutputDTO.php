<?php
// src/Dto/ProductDetailedOutputDTO.php

namespace App\Dto;

use App\Entity\Product;
use App\Services\MediaUrlResolver;
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
    public ?float $specialPrice = null;
    public ?string $specialPriceFrom = null;
    public ?string $specialPriceTo = null;
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
    public ?string $code = null;
    public ?array $saleUnit = null;
    
    
    // --- NOUVEAUX CHAMPS BOOKING ---
    public string $mode;
    public ?array $bookingConfig = null;
    // -------------------------------

    public ?array $style;
    public array $variants;
    public ?array $category;
    public ?array $categories = null;
    public ?array $specifications;

    // --- Contrat de la boutique réglable (ProductCommerceDto, 08/10/2026) : modes explicites, cents, fiche véhicule ---
    public string $kind;
    public array $sale;
    public array $rental;
    public array $subscription;
    public bool $customizable;
    public array $pricing;
    public ?array $vehicleDetails;

    public function __construct(Product $product, string $host, string $locale = 'fr', string $currency = ProductCommerceDto::DEFAULT_CURRENCY)
    {
        foreach (ProductCommerceDto::fields($product, $locale, $currency) as $field => $value) {
            $this->$field = $value;
        }
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
        $this->specialPrice = $product->getSpecialPrice();
        $this->specialPriceFrom = $product->getSpecialPriceFrom()?->format('Y-m-d H:i:s');
        $this->specialPriceTo = $product->getSpecialPriceTo()?->format('Y-m-d H:i:s');
        
        // Nom de fichier téléversé, URL, ou clé de la médiathèque (/media/secure/…) posée depuis l'éditeur
        $this->image = MediaUrlResolver::joinStored($product->getImage(), rtrim($host, '/') . '/assets/uploads/products');

        // Pour le booking, ceci retourne la capacité totale (Pool)
        $this->quantity = $product->getQuantity();
        
        $this->freezeQuantity = $product->getFreezeQuantity() ?? 0;
        $this->createdAt = $product->getCreatedAt()->format('Y-m-d H:i:s');
        $this->tags = $productTranslation?->getTags() ?? $product->getTags();
        $this->slug = $productTranslation?->getSlug() ?? $product->getSlug();
        $this->purchasePrice = $product->getPurchasePrice();
        $this->coefficientMultiplier = $product->getCoefficientMultiplier();
        $this->barcode = $product->getBarcode();
        $this->code = $product->getCode();

        // --- LOGIQUE BOOKING ---
        // 1. Le Mode (retail vs booking)
        $this->mode = $product->getMode()->value;

        $this->pictures = array_values(array_map(fn ($picture) => ['id' => $picture->getId(), 'url' => MediaUrlResolver::joinStored($picture->getImageUrl(), rtrim($host, '/') . '/assets/uploads/products')], $product->getPictures()->toArray()));

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

        $this->variants = ProductCommerceDto::variants($product, $locale);

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
                    'image'       => MediaUrlResolver::joinStored($category->getImage(), rtrim($host, '/') . '/assets/uploads/categories'),
                ];
            }, $categoriesCollection->toArray());

        } else {
            $this->category = []; 
        }   
        $this->categories = $this->category;
    }   
}