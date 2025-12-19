<?php

namespace App\Dto;

use App\Entity\BookingConfiguration;
use App\Entity\Product;
use App\Enum\ProductMode;
use Symfony\Component\Validator\Constraints as Assert;

class BookingSetupRequest
{
    public bool $enableBooking = false;

    #[Assert\NotBlank]
    public string $granularity = 'minutes_30';

    #[Assert\PositiveOrZero]
    public int $stockQuantity = 1;

    #[Assert\Positive]
    public int $minDuration = 1;

    #[Assert\PositiveOrZero]
    public ?int $bufferTime = 0;

    /**
     * Factory pour pré-remplir le DTO à partir des données existantes (Entité)
     */
    public static function createFromProduct(Product $product): self
    {
        $dto = new self();
        $config = $product->getBookingConfiguration();

        // 1. Déterminer si activé via le MODE du produit
        $dto->enableBooking = ($product->getMode() === ProductMode::BOOKING);

        // 2. Remplir les valeurs si une config existe déjà
        if ($config) {
            $dto->granularity = $config->getGranularity() ?? 'minutes_30';
            $dto->stockQuantity = $config->getStockQuantity() ?? 1;
            $dto->minDuration = $config->getMinDuration() ?? 1;
            $dto->bufferTime = $config->getBufferTime() ?? 0;
        }

        return $dto;
    }
}