<?php

namespace App\Dto;

use App\Entity\Product;
use App\Entity\ProductOptionValue;
use App\Entity\ProductVariant;
use App\Entity\VehicleProduct;

/**
 * Contrat « boutique réglable » d'un produit (08/10/2026), commun à toutes les routes qui renvoient un produit
 * (ProductDetailedOutputDTO, ProductOutputDTO, ProductOutputCategoryDto) : modes explicites à la place des déductions
 * par le nom, montants en cents, fiche véhicule. Les anciens champs (mode, price en dollars, bookingConfig) restent
 * pour le carrousel des landing pages.
 */
final class ProductCommerceDto
{
    public const DEFAULT_CURRENCY = 'CAD';

    /** @return array<string, mixed> champs à fusionner dans la sortie d'un produit */
    public static function fields(Product $product, string $locale = 'fr', string $currency = self::DEFAULT_CURRENCY): array
    {
        return [
            'kind' => $product->getKind(),
            'sale' => ['enabled' => $product->isSaleEnabled()],
            'rental' => ['enabled' => $product->isRentalEnabled()] + ($product->isRentalEnabled() ? self::bookingConfig($product, $locale) : []),
            'subscription' => ['enabled' => $product->isSubscriptionEnabled()],
            'customizable' => $product->hasCustomizationConfig(), // d'après les combinaisons saisies, pas la case
            'pricing' => self::pricing($product, $currency),
            'vehicleDetails' => $product instanceof VehicleProduct ? self::vehicleDetails($product) : null,
            'rating' => $product->getRatingCount() > 0 && $product->getRatingAverage() !== null ? round($product->getRatingAverage(), 1) : null,
            'reviewCount' => $product->getRatingCount(),
        ];
    }

    /**
     * Prix en cents : regular (prix de vente), special (promotion et ses dates, si définie), amount (prix à payer
     * maintenant : la promotion si elle est en cours, sinon le prix de vente).
     *
     * @return array{currency: string, regular: int, special: ?array{amount: int, from: ?string, to: ?string, active: bool}, amount: int}
     */
    public static function pricing(Product $product, string $currency = self::DEFAULT_CURRENCY): array
    {
        $regular = self::cents($product->getPrice());
        $special = null;
        if ($product->getSpecialPrice() !== null) {
            $now = new \DateTimeImmutable();
            $from = $product->getSpecialPriceFrom();
            $to = $product->getSpecialPriceTo();
            $special = [
                'amount' => self::cents($product->getSpecialPrice()),
                'from' => $from?->format(\DateTimeInterface::ATOM),
                'to' => $to?->format(\DateTimeInterface::ATOM),
                'active' => ($from === null || $from <= $now) && ($to === null || $to >= $now),
            ];
        }

        return ['currency' => $currency, 'regular' => $regular, 'special' => $special, 'amount' => $special !== null && $special['active'] ? $special['amount'] : $regular];
    }

    /**
     * Réglages de réservation (bookingConfig, nom unique) : grille, durées, stock, battement, tarifs en cents, heures
     * d'ouverture, demi-journées, dates autorisées, créneau du soir, caution, frais, délai d'arrivée, textes.
     *
     * @return array<string, mixed>
     */
    public static function bookingConfig(Product $product, string $locale = 'fr'): array
    {
        $config = $product->getBookingConfiguration();
        $rates = array_values(array_map(fn ($pack) => [
            'id' => $pack->getId(),
            'name' => $pack->getTranslation($locale)?->getName() ?? $pack->getName(),
            'hourRate' => self::cents($pack->getHourRate()),
            'halfDayRate' => self::cents($pack->getHalfDayRate()),
            'dayRate' => self::cents($pack->getDayRate()),
            'weekRate' => self::cents($pack->getWeekRate()),
            'monthRate' => self::cents($pack->getMonthRate()),
        ], $product->getRentalPacks()));

        return [
            'granularity' => $config?->getGranularity(), // days | hours | minutes_30 | minutes_15 | datetime (prise et retour avec date et heure, 08/11/2026)
            'timeStepMinutes' => $config?->effectiveTimeStepMinutes(), 'returnToleranceMinutes' => $config?->isDatetime() ? (int) ($config->getReturnToleranceMinutes() ?? 0) : null,
            'minDuration' => $config?->getMinDuration(),
            'maxDuration' => $config?->getMaxDuration(),
            'stockQuantity' => $config?->getStockQuantity(),
            'bufferTime' => $config?->getBufferTime(),
            'rates' => $rates,
            'openingHours' => $config?->getOpeningStart() !== null || $config?->getOpeningEnd() !== null ? ['start' => $config->getOpeningStart(), 'end' => $config->getOpeningEnd()] : null,
            'halfDays' => $config?->getHalfDays() ?? [],
            'allowedDates' => $config?->getAllowedDates() ?: null,
            'eveningSlot' => $config?->getEveningSlot() ?: null,
            'minDaysStandard' => $config?->getMinDaysStandard(),
            'deposit' => $config?->getDeposit(),
            'extraPassengerFee' => $config?->getExtraPassengerFee(),
            'arrivalLeadMinutes' => $config?->getArrivalLeadMinutes(),
            'cancellationPolicy' => $config?->getCancellationPolicy(),
            'included' => $config?->getIncluded() ?? [],
            'excluded' => $config?->getExcluded() ?? [],
            'notes' => $config?->getNotes(),
        ] + (($promotions = \App\Services\Booking\RentalPromotionService::displayAll($product)) !== [] ? ['promotion' => $promotions[0], 'promotions' => $promotions] : []); // lot LOC3 : clés absentes sans promotion ; promotions = toutes (11/11/2026)
    }

    /**
     * Variantes d'un produit par identifiant croissant (ordre de création). Aucune position n'est saisie dans
     * EasyAdmin ; sans ce tri, l'ordre dépendait de la requête (la fiche par slug rendait « L, XL, M, S », 31/10/2026).
     *
     * @return list<ProductVariant>
     */
    public static function orderedVariants(Product $product): array
    {
        $variants = $product->getVariants()->toArray();
        usort($variants, fn (ProductVariant $a, ProductVariant $b) => ($a->getId() ?? PHP_INT_MAX) <=> ($b->getId() ?? PHP_INT_MAX));

        return array_values($variants);
    }

    /**
     * Valeurs d'option d'une variante par type d'option (identifiant croissant) puis par valeur : le même ordre
     * que les types ont été créés, identique sur toutes les routes.
     *
     * @return list<ProductOptionValue>
     */
    public static function orderedOptionValues(ProductVariant $variant): array
    {
        $values = $variant->getOptionValues()->toArray();
        usort($values, fn (ProductOptionValue $a, ProductOptionValue $b) =>
            [$a->getProductOption()?->getId() ?? PHP_INT_MAX, $a->getId() ?? PHP_INT_MAX]
            <=> [$b->getProductOption()?->getId() ?? PHP_INT_MAX, $b->getId() ?? PHP_INT_MAX]);

        return array_values($values);
    }

    /**
     * Variantes : prix explicite en cents (null = prix du produit), stock, options { code, name, value } (les anciens
     * noms value_id, option_name… restent).
     *
     * @return list<array<string, mixed>>
     */
    public static function variants(Product $product, string $locale = 'fr'): array
    {
        return array_values(array_map(function (ProductVariant $variant) use ($locale) {
            $color = $variant->getColor();
            $size = $variant->getSize();
            $options = array_values(array_map(function (ProductOptionValue $optionValue) use ($locale) {
                $parentOption = $optionValue->getProductOption();

                return [
                    'code' => $parentOption?->getCode(),
                    'name' => $parentOption ? ($parentOption->getTranslation($locale)?->getName() ?? $parentOption->getName()) : null,
                    'value' => $optionValue->getTranslation($locale)?->getValue() ?? $optionValue->getValue(),
                    'value_id' => $optionValue->getId(),
                    'option_name' => $parentOption ? ($parentOption->getTranslation($locale)?->getName() ?? $parentOption->getName()) : null,
                    'option_id' => $parentOption?->getId(),
                    'option_code' => $parentOption?->getCode(),
                    'image_preview' => $optionValue->getImagePreview(),
                    'price_delta' => $optionValue->getPriceDelta(),
                ];
            }, self::orderedOptionValues($variant)));

            return [
                'id' => $variant->getId(),
                'price' => $variant->getPrice(),
                'color' => $color ? ['id' => $color->getId(), 'name' => $color->getTranslation($locale)?->getName() ?? $color->getName(), 'codeHexa' => $color->getCodeHexa()] : null,
                'size' => $size ? ['id' => $size->getId(), 'name' => $size->getTranslation($locale)?->getName() ?? $size->getName()] : null,
                'stockQuantity' => $variant->getStockQuantity(),
                'options' => $options,
            ] + (($promotion = \App\Services\OrderService\VariantPriceResolver::display($variant)) !== null ? ['promotion' => $promotion] : []); // lot VAR : clé absente sans promotion ou interrupteur éteint
        }, self::orderedVariants($product)));
    }

    /** @return array<string, mixed> */
    public static function vehicleDetails(VehicleProduct $vehicle): array
    {
        return [
            'year' => $vehicle->getYear(),
            'brand' => $vehicle->getBrand(),
            'model' => $vehicle->getModel(),
            'vin' => $vehicle->getVin(),
            'transmission' => $vehicle->getTransmission(),
            'gasType' => $vehicle->getGasType(),
            'enginePower' => $vehicle->getEnginePower(),
            'hoursOrMileage' => $vehicle->getHoursOrMileage(),
            'condition' => $vehicle->getVehicleCondition(),
            'color' => $vehicle->getColor(),
        ];
    }

    /**
     * Montant stocké → cents entiers. Les prix (produit, promotion, forfaits de location, transporteurs) sont déjà
     * stockés en cents dans des colonnes décimales (EasyAdmin : MoneyField storedAsCents) : seul l'arrondi est fait.
     */
    public static function cents(?float $amount): int
    {
        return (int) round($amount ?? 0.0);
    }
}
