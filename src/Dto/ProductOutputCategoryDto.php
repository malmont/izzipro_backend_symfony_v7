<?php

namespace App\Dto;

use App\Entity\Product;
use App\Services\MediaUrlResolver;
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
    public ?float $specialPrice = null;
    public ?string $specialPriceFrom = null;
    public ?string $specialPriceTo = null;
    public ?string $image;
    public array $pictures = [];
    public int $quantity;
    public ?int $freezeQuantity;
    public string $createdAt;
    public ?string $tags; 
    public ?string $slug;
    public ?float $purchasePrice;
    public ?float $coefficientMultiplier;
    public ?string $barcode;
    public ?string $code = null;
    public ?array $saleUnit = null;
    
    
    // --- NOUVEAUX CHAMPS POUR LE BOOKING ---
    public string $mode;
    public ?array $bookingConfig = null;
    // ---------------------------------------

    public ?array $style;
    public array $variants;
    public ?array $category;
    public ?array $categories = null;

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
        
        $this->quantity = $product->getQuantity();
        
        $this->freezeQuantity = $product->getFreezeQuantity() ?? 0;
        $this->createdAt = $product->getCreatedAt()->format('Y-m-d H:i:s');
        $this->tags = $productTranslation?->getTags() ?? $product->getTags(); 
        $this->slug = $productTranslation?->getSlug() ?? $product->getSlug();
        $this->purchasePrice = $product->getPurchasePrice();
        $this->coefficientMultiplier = $product->getCoefficientMultiplier();
        $this->barcode = $product->getBarcode();
        $this->code = $product->getCode();
 
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
        // --------------------------------


        $this->saleUnit = $product->getSaleUnit() ? [
            'id' => $product->getSaleUnit()->getId(),
            'name' => $product->getSaleUnit()->getName(),
        ] : null;

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

        $this->variants = ProductCommerceDto::variants($product, $locale);

        $this->category = array_map(function ($category) use ($host, $locale) {
            return [
                'id'          => $category->getId(),
                'name'        => $category->getTranslation($locale)?->getName(),
                'description' => $category->getTranslation($locale)?->getDescription(),
                'isRentalCategory' => $category->isRentalCategory(),
                'syncWeb'     => $category->isSyncWeb(),
                'isVisible'   => $category->isVisible(),
                'image'       => MediaUrlResolver::joinStored($category->getImage(), rtrim($host, '/') . '/assets/uploads/categories'),
            ];
        }, $product->getCategory()->toArray());
        
        $this->categories = $this->category;
        // $this->specifications = $product->getSpecifications();
     }
 }