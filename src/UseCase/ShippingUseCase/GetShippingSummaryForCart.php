<?php
// src/UseCase/ShippingUseCase/GetShippingSummaryForCart.php

namespace App\UseCase\ShippingUseCase;

use App\Dto\CartItemDto;
use App\Dto\RateSummaryDto;
use App\Repository\ProductShippingRepository;
use App\Repository\PackagingTypeRepository;
use App\Services\ShippingService\ShippingService;
use LogicException;
use App\Services\ShippingService\ShipmentAddressBuilder;

class GetShippingSummaryForCart
{
    public function __construct(
        private ProductShippingRepository $shippingRepo,
        private PackagingTypeRepository   $templateRepo,
        private ShippingService           $shippingService,
        private ShipmentAddressBuilder    $builder
    ) {}

    /**
     * @param CartItemDto[] $cartItems
     * @param array         $toAddress        // keys : street1, street2, city, province, postal_code, country
     * @param array         $fromAddress      // same keys as $toAddress
     * @param string[]      $carrierAccountIds
     * @return RateSummaryDto[]
     */
    public function execute(
        array  $cartItems,
        array  $toAddress,
        array  $fromAddress,
        array  $carrierAccountIds
    ): array {
        // 1) Charger et dupliquer les configs ProductShipping
        $items = [];
        foreach ($cartItems as $ci) {
            $ps = $this->shippingRepo->findOneBy(['product' => $ci->productId]);
            if (! $ps) {
                throw new LogicException("Pas de config shipping pour le produit ID {$ci->productId}");
            }
            for ($i = 0; $i < $ci->quantity; $i++) {
                $items[] = $ps;
            }
        }

        // 2) Adapter les adresses au format EasyPost
        $to   = $this->builder->buildTo($toAddress);
        $from = $this->builder->buildFrom();

        // 3) Calculer les colis
        $templates = $this->templateRepo->findAll();
        $parcels   = $this->shippingService->getParcelsFromItems($items, $templates);

        // 4) Récupérer tous les tarifs
        $rateOptions = $this->shippingService->getRates($parcels, $to, $from, $carrierAccountIds);

        // 5) Agréger par carrier+service
        $buckets = [];
        foreach ($rateOptions as $r) {
            $key = $r->carrier . '||' . $r->service;
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'carrier'       => $r->carrier,
                    'service'       => $r->service,
                    'totalPrice'    => 0.0,
                    'currency'      => $r->currency,
                    'estimatedDays' => 0,
                    'parcelCount'   => 0,
                ];
            }
            // ⚠️ on utilise $r->price (pas ->rate)
            $buckets[$key]['totalPrice']    += $r->price;
            // ⚠️ on utilise $r->deliveryDays (pas ->estimatedDays)
            $buckets[$key]['estimatedDays']  = max(
                $buckets[$key]['estimatedDays'],
                $r->estimatedDays
            );
            $buckets[$key]['parcelCount']++;
        }

        // 6) Construire les DTO de résumé (attention à l’ordre des arguments)
        $summaries = [];
        foreach ($buckets as $b) {
            $summaries[] = new RateSummaryDto(
                $b['carrier'],
                $b['service'],
                round($b['totalPrice'], 2),
                $b['currency'],
                $b['parcelCount'],    // d’abord parcelCount
                $b['estimatedDays']   // puis estimatedDays
            );
        }

        return $summaries;
    }
}
