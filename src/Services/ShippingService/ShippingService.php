<?php
// src/Services/ShippingService/ShippingService.php

namespace App\Services\ShippingService;

use App\Dto\RateOptionDto;
use App\Entity\PackagingType;
use App\Entity\ProductShipping;
use App\Services\TenantEntityManagerProvider;
use App\Exception\ItemTooLargeForPackagingException;
use DVDoug\BoxPacker\PackedBox;
use DVDoug\BoxPacker\PackedBoxList; 

class ShippingService
{
    // Constantes pour les conversions d'unités
    private const G_TO_OZ = 0.035274;
    private const MM_TO_IN = 0.0393701;

    public function __construct(
        private TenantEntityManagerProvider $emProvider,
        private EasyPostService           $easyPostService,
        private PackagingService          $packager
    ) {}

    /**
     * Calcule et retourne les colis optimisés.
     * C'est la méthode "source" qui appelle le PackagingService.
     * @param ProductShipping[] $items
     * @return PackedBoxList
     * @throws ItemTooLargeForPackagingException
     */

    private function getPackedParcels(array $items): PackedBoxList
    {
        $em = $this->emProvider->getEntityManager();
        $templateRepo = $em->getRepository(PackagingType::class);
        $templates = $templateRepo->findAll();

        // Cette méthode renvoie bien un PackedBoxList
        return $this->packager->computeParcels($items, $templates);
    }

    /**
     * @param ProductShipping[] $items
     * @param array $to
     * @param array $from
     * @param string[] $carrierAccountIds
     * @return RateOptionDto[]
     * @throws ItemTooLargeForPackagingException
     */
    public function getRates(array $items, array $to, array $from, array $carrierAccountIds): array
    {
        $packedParcels = $this->getPackedParcels($items);
        $allRates = [];

        /** @var PackedBox $packedBox */
        foreach ($packedParcels as $packedBox) {
            // Cette ligne est déjà correcte
            $box = $packedBox->box;

            // 1) Payload principal
            $payload = [
                'to_address'       => $to,
                'from_address'     => $from,
                'parcel'           => [
                    'weight' => $packedBox->getWeight() * self::G_TO_OZ, // g -> oz
                    'length' => $box->getOuterLength() * self::MM_TO_IN, // mm -> in
                    'width'  => $box->getOuterWidth() * self::MM_TO_IN,  // mm -> in
                    'height' => $box->getOuterDepth() * self::MM_TO_IN,  // mm -> in
                ],
                'carrier_accounts' => $carrierAccountIds,
            ];

            $rates = $this->easyPostService->getRates($payload);

            // 2) Collecter les tarifs normaux
            foreach ($rates as $r) {
                $allRates[] = new RateOptionDto(
                    $r->carrier,
                    $r->service,
                    (float)$r->rate,
                    $r->currency,
                    (int)($r->delivery_days ?? $r->est_delivery_days ?? 0)
                );
            }
        }

        return $allRates;
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
            $key = $r->carrier . '|' * $r->service;
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
     * @param ProductShipping[] $items
     * @return array
     * @throws ItemTooLargeForPackagingException
     */
    public function getParcelSummaries(array $items): array
    {
        $packedParcels = $this->getPackedParcels($items);
        $summaries = [];
        $i = 1;

        /** @var PackedBox $packedBox */
        foreach ($packedParcels as $packedBox) {
            $box = $packedBox->box;
            
            $summaries[] = [
                'index'  => $i++,
                'weight' => round($packedBox->getWeight() / 1000, 2),
                'length' => round($box->getOuterLength() / 10, 1),
                'width'  => round($box->getOuterWidth() / 10, 1),
                'height' => round($box->getOuterDepth() / 10, 1),
            ];
        }
        return $summaries;
    }

    /**
     * Achète les étiquettes pour chaque colis selon le carrierAccount & le service choisis.
     * @param ProductShipping[] $items
     * @return array
     * @throws ItemTooLargeForPackagingException
     */
    public function purchase(
        array  $items,
        array  $to,
        array  $from,
        string $carrierAccountId,
        string $service
    ): array {
        $packedParcels = $this->getPackedParcels($items);
        $labels = [];

        /** @var PackedBox $packedBox */
        foreach ($packedParcels as $packedBox) {
            $box = $packedBox->box; 

            $payload = [
                'to_address'       => $to,
                'from_address'     => $from,
                'parcel'           => [
                    'weight' => $packedBox->getWeight() * self::G_TO_OZ,
                    'length' => $box->getOuterLength() * self::MM_TO_IN,
                    'width'  => $box->getOuterWidth() * self::MM_TO_IN,
                    'height' => $box->getOuterDepth() * self::MM_TO_IN,
                ],
                'carrier_accounts' => [$carrierAccountId],
            ];

            $labels[] = $this->easyPostService
                             ->createShipmentAndBuy($payload, $carrierAccountId, $service);
        }

        return $labels;
    }
}

