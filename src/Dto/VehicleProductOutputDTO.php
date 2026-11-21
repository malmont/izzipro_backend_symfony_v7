<?php

namespace App\Dto;

use App\Entity\BookingConfiguration;
use App\Entity\VehicleProduct;

/**
 * Produit véhicule rendu par GET /api/vehicle_products et /{id} (lot APIP-RETRAIT, 20/11/2026) : la forme exacte que
 * rendait API Platform en `application/json` (groupe `vehicle_product:read`), capturée dans
 * tests/Fixtures/contrats/vehicle-products.json : champs du véhicule, puis du produit, puis `isRental` ; valeurs nulles
 * omises, configuration de réservation comprise.
 *
 * LISTE BLANCHE : un champ n'apparaît ici que s'il est écrit ci-dessous. Ajouter un groupe sur l'entité ne fait rien sortir
 * (jamais de coût, de marge ni de champ interne dans une réponse publique : PublicCostLeakTest).
 */
final class VehicleProductOutputDTO
{
    /** @return array<string, mixed> */
    public static function fromEntity(VehicleProduct $v): array
    {
        return self::withoutNulls([
            'year' => $v->getYear(),
            'brand' => $v->getBrand(),
            'model' => $v->getModel(),
            'vin' => $v->getVin(),
            'transmission' => $v->getTransmission(),
            'gasType' => $v->getGasType(),
            'enginePower' => $v->getEnginePower(),
            'hoursOrMileage' => $v->getHoursOrMileage(),
            'vehicleCondition' => $v->getVehicleCondition(),
            'color' => $v->getColor(),
            'id' => $v->getId(),
            'name' => $v->getName(),
            'description' => $v->getDescription(),
            'price' => $v->getPrice(),
            'isbestseller' => $v->isIsbestseller(),
            'image' => $v->getImage(),
            'quantity' => $v->getQuantity(),
            'slug' => $v->getSlug(),
            'mode' => $v->getMode()->value,
            'bookingConfiguration' => self::booking($v->getBookingConfiguration()),
            'isRental' => $v->getIsRental(),
        ]);
    }

    /** @return array<string, mixed>|null */
    private static function booking(?BookingConfiguration $b): ?array
    {
        return $b === null ? null : self::withoutNulls([
            'id' => $b->getId(),
            'granularity' => $b->getGranularity(),
            'stockQuantity' => $b->getStockQuantity(),
            'minDuration' => $b->getMinDuration(),
            'bufferTime' => $b->getBufferTime(),
        ]);
    }

    /** @param array<string, mixed> $fields */
    private static function withoutNulls(array $fields): array
    {
        return array_filter($fields, static fn ($x) => $x !== null);
    }
}
