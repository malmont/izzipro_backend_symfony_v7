<?php
// src/Services/ShippingService/ShippingService.php

namespace App\Services\ShippingService;

use App\Dto\ParcelDto;
use App\Dto\RateOptionDto;

class ShippingService
{
    public function __construct(
        private EasyPostService  $easyPostService,
        private PackagingService $packager
    ) {}

    /**
     * Transforme des ProductShipping en ParcelDto[]
     */
    public function getParcelsFromItems(array $items, array $templates): array
    {
        return $this->packager->computeParcels($items, $templates);
    }

    /**
     * Récupère les tarifs EasyPost, avec fallback “1-item = 1-colis” si empty.
     *
     * @param ParcelDto[] $parcels
     * @param array       $to
     * @param array       $from
     * @param string[]    $carrierAccountIds
     * @return RateOptionDto[]
     */
    public function getRates(array $parcels, array $to, array $from, array $carrierAccountIds): array
    {
        $all = [];
        foreach ($parcels as $p) {
            // 1) Payload principal
            $payload = [
                'to_address'       => $to,
                'from_address'     => $from,
                'parcel'           => [
                    'weight' => $p->weight * 35.274, // convertir kg → oz
                    'length' => $p->length,
                    'width'  => $p->width,
                    'height' => $p->height,
                ],
                'carrier_accounts' => $carrierAccountIds,
            ];

            $rates = $this->easyPostService->getRates($payload);

            // 2) Fallback : si aucun tarif renvoyé, on découpe en 1-item = 1-colis
            if (empty($rates) && !empty($p->items)) {
                foreach ($p->items as $item) {
                    $subPayload = [
                        'to_address'       => $to,
                        'from_address'     => $from,
                        'parcel'           => [
                            'weight' => $item->getWeight() * 35.274,
                            'length' => $item->getLength(),
                            'width'  => $item->getWidth(),
                            'height' => $item->getHeight(),
                        ],
                        'carrier_accounts' => $carrierAccountIds,
                    ];
                    $subRates = $this->easyPostService->getRates($subPayload);
                    foreach ($subRates as $r) {
                        $all[] = new RateOptionDto(
                            $r->carrier,
                            $r->service,
                            (float)$r->rate,
                            $r->currency,
                            (int)$r->delivery_days
                        );
                    }
                }
                continue;
            }

            // 3) Sinon, on collecte les tarifs normaux
            foreach ($rates as $r) {
                $all[] = new RateOptionDto(
                    $r->carrier,
                    $r->service,
                    (float)$r->rate,
                    $r->currency,
                    (int)$r->delivery_days
                );
            }
        }

        return $all;
    }
}
