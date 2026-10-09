<?php
// src/Dto/ProductOutputDTO.php

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
    public array $pictures = [];
    public ?array $saleUnit = null;
    public ?string $code = null;

    
    public string $mode;
    public ?array $bookingConfig = null;
    // -------------------------------

    public ?array $categories;
    public ?array $style;
    public array $variants;
    public ?array $specifications;

    // --- Contrat de la boutique réglable (ProductCommerceDto, 08/10/2026) : modes explicites, cents, fiche véhicule ---
    public string $kind;
    public array $sale;
    public array $rental;
    public array $subscription;
    public bool $customizable;
    public array $pricing;
    public ?array $vehicleDetails;
    /** Avis publiés : moyenne de 0 à 5 arrondie à 0,1 (null sans avis) et nombre */
    public ?float $rating;
    public int $reviewCount;

    public function __construct(Product $product, string $host, string $locale = 'fr', string $currency = ProductCommerceDto::DEFAULT_CURRENCY)
    {
        foreach (ProductCommerceDto::fields($product, $locale, $currency) as $field => $value) {
            $this->$field = $value;
        }
        $productTranslation = $product->getTranslation($locale);
        $this->id = $product->getId();
        $this->name = $productTranslation?->getName() ?? $product->getName();
        $this->description = $productTranslation?->getDescription() ?? $product->getDescription();
        $this->purchasePrice = $product->getPurchasePrice();
        $this->coefficientMultiplier = $product->getCoefficientMultiplier();
        $this->slug = $productTranslation?->getSlug() ?? $product->getSlug();
        $this->specifications = $product->getSpecifications();
        $this->code = $product->getCode();
        
        // Nom de fichier téléversé, URL, ou clé de la médiathèque (/media/secure/…) posée depuis l'éditeur
        $this->image = MediaUrlResolver::joinStored($product->getImage(), rtrim($host, '/') . '/assets/uploads/products');
        

        $this->mode = $product->getMode()->value;

        $this->pictures = array_values(array_map(fn ($picture) => ['id' => $picture->getId(), 'url' => MediaUrlResolver::joinStored($picture->getImageUrl(), rtrim($host, '/') . '/assets/uploads/products')], $product->getPictures()->toArray()));

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
                    'name'        => $pack->getName(),
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

        $categoriesCollection = $product->getCategory();

        if ($categoriesCollection && !$categoriesCollection->isEmpty()) {
            $this->categories = array_map(function (Categories $category) use ($locale) {
                 return [
                    'id'          => $category->getId(),
                    'name'        => $category->getTranslation($locale)?->getName(),
                    'description' => $category->getTranslation($locale)?->getDescription(),
                    'isRentalCategory' => $category->isRentalCategory(),
                    'syncWeb'     => $category->isSyncWeb(),
                    'isVisible'   => $category->isVisible(),
                ];
            }, $categoriesCollection->toArray());
        } else {
            $this->categories = null;
        }

        $this->style = $product->getStyle() ? [
            'id' => $product->getStyle()->getId(),
            'name' => $product->getStyle()->getName(),
        ] : null;


        $this->variants = ProductCommerceDto::variants($product, $locale);
    }
}