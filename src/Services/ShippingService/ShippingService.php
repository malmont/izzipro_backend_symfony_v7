<?php
// src/Services/ShippingService/ShippingService.php

namespace App\Services\ShippingService;

use App\Dto\ParcelDto;
use App\Dto\RateOptionDto;
use App\Entity\PackagingType;
use App\Services\TenantEntityManagerProvider;

// Assurez-vous d'importer les DTO et Services nécessaires
use App\Dto\CartItemDto;
use App\Services\ShippingService\EasyPostService;
use App\Services\ShippingService\PackagingService;


class ShippingService
{
    public function __construct(
        private TenantEntityManagerProvider $emProvider,
        private EasyPostService           $easyPostService,
        private PackagingService          $packager
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
                            (int)($r->delivery_days ?? $r->est_delivery_days ?? 0)
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
                    (int)($r->delivery_days ?? $r->est_delivery_days ?? 0)
                );
            }
        }

        return $all;
    }

    /**
     * Agrège un tableau de RateOptionDto pour obtenir
     * le total par couple carrier+service.
     *
     * @param RateOptionDto[] $rates
     * @return RateOptionDto[]
     */
    public function aggregateRates(array $rates): array
    {
        $agg = [];
        foreach ($rates as $r) {
            $key = $r->carrier . '|' . $r->service;
            if (!isset($agg[$key])) {
                $agg[$key] = [
                    'carrier'       => $r->carrier,
                    'service'       => $r->service,
                    'total'         => 0.0,
                    'currency'      => $r->currency,
                    'estimatedDays' => 0,
                ];
            }
            $agg[$key]['total']         += $r->price;
            $agg[$key]['estimatedDays']  = max($agg[$key]['estimatedDays'], $r->estimatedDays);
        }

        return array_map(
            fn(array $v) => new RateOptionDto(
                $v['carrier'],
                $v['service'],
                $v['total'],
                $v['currency'],
                $v['estimatedDays']
            ),
            array_values($agg)
        );
    }

    /**
     * Renvoie les dimensions & poids de chaque colis à créer.
     */
    public function getParcelSummaries(array $items, array $to, array $from, array $carrierAccountIds): array
    {
        $em = $this->emProvider->getEntityManager();
        $templateRepo = $em->getRepository(PackagingType::class);
        $templates = $templateRepo->findAll();
        
        $parcels   = $this->getParcelsFromItems($items, $templates);

        return array_map(
            fn(ParcelDto $p, int $i) => [
                'index'  => $i + 1,
                'weight' => round($p->weight, 2),
                'length' => $p->length,
                'width'  => $p->width,
                'height' => $p->height,
            ],
            $parcels,
            array_keys($parcels)
        );
    }

    /**
     * Achète les étiquettes pour chaque colis selon le carrierAccount & le service choisis.
     */
    public function purchase(
        array  $items,
        array  $to,
        array  $from,
        string $carrierAccountId,
        string $service
    ): array {
        $em = $this->emProvider->getEntityManager();
        $templateRepo = $em->getRepository(PackagingType::class);
        $templates = $templateRepo->findAll();

        $parcels   = $this->getParcelsFromItems($items, $templates);
        $labels    = [];

        foreach ($parcels as $p) {
            $payload = [
                'to_address'       => $to,
                'from_address'     => $from,
                'parcel'           => [
                    'weight' => $p->weight * 35.274,
                    'length' => $p->length,
                    'width'  => $p->width,
                    'height' => $p->height,
                ],
                'carrier_accounts' => [$carrierAccountId],
            ];

            $labels[] = $this->easyPostService
                             ->createShipmentAndBuy($payload, $carrierAccountId, $service);
        }

        return $labels;
    }
}