<?php
// src/Services/ShippingService/PackagingService.php

namespace App\Services\ShippingService;

use App\Dto\ParcelDto;
use App\Entity\ProductShipping;
use App\Entity\PackagingType;

class PackagingService
{
    /**
     * @param ProductShipping[] $items
     * @param PackagingType[]   $templates
     * @return ParcelDto[]
     */
    public function computeParcels(array $items, array $templates): array
    {
        $parcels = [];

        // 1) Grouper par ID de ShippingClass
        $buckets = [];
        foreach ($items as $item) {
            $sc  = $item->getShippingClassEntity();
            $key = $sc ? $sc->getId() : 'default';
            $buckets[$key][] = $item;
        }

        // 2) Pour chaque bucket (classe), on partitionne
        foreach ($buckets as $groupItems) {
            $parcels = array_merge(
                $parcels,
                $this->computeParcelsForGroup($groupItems, $templates)
            );
        }



        return $parcels;
    }

    /**
     * Greedy groupage d’un seul bucket d’items
     *
     * @param ProductShipping[] $items
     * @param PackagingType[]   $templates
     * @return ParcelDto[]
     */
    private function computeParcelsForGroup(array $items, array $templates): array
    {
        $parcels = [];

        // a) Trier templates par volume croissant
        usort($templates, fn($a, $b) =>
            ($a->getInnerLength() * $a->getInnerWidth() * $a->getInnerHeight())
          <=> ($b->getInnerLength() * $b->getInnerWidth() * $b->getInnerHeight())
        );

        // b) Trier items par poids décroissant
        usort($items, fn($a, $b) => $b->getWeight() <=> $a->getWeight());

        // c) Tant qu’il reste des items
        while (count($items) > 0) {
            $first = array_shift($items);

            // bbox initiale = dimensions du premier item
            $sumW   = $first->getWeight();
            $maxL   = $first->getLength() ?? 0;
            $maxW_  = $first->getWidth()  ?? 0;
            $maxH   = $first->getHeight() ?? 0;
            // 🆕 volume cumulé initial
            $sumV   = $maxL * $maxW_ * $maxH;

            // d) Choisir le template minimal adapté
            $chosenTpl = null;
            foreach ($templates as $tpl) {
                $tplVol = $tpl->getInnerLength()
                        * $tpl->getInnerWidth()
                        * $tpl->getInnerHeight();
                if (
                    $sumW   <= $tpl->getMaxWeight() &&
                    $maxL   <= $tpl->getInnerLength() &&
                    $maxW_  <= $tpl->getInnerWidth()  &&
                    $maxH   <= $tpl->getInnerHeight() &&
                    $sumV   <= $tplVol
                ) {
                    $chosenTpl = $tpl;
                    break;
                }
            }

            // e) Si aucun template ne convient, on expédie l’item seul « brut »
            if (! $chosenTpl) {
                $parcels[] = new ParcelDto($sumW, $maxL, $maxW_, $maxH);
                continue;
            }

            // f) Greedy : ajouter les suivants tant que ça rentre
            $tplVol = $chosenTpl->getInnerLength()
                    * $chosenTpl->getInnerWidth()
                    * $chosenTpl->getInnerHeight();

            foreach ($items as $k => $cand) {
                $itemV = ($cand->getLength() ?? 0)
                       * ($cand->getWidth()  ?? 0)
                       * ($cand->getHeight() ?? 0);

                $newW   = $sumW + $cand->getWeight();
                $newL   = max($maxL, $cand->getLength() ?? 0);
                $newW_  = max($maxW_, $cand->getWidth()   ?? 0);
                $newH_  = max($maxH, $cand->getHeight()   ?? 0);
                $newV   = $sumV + $itemV;

                if (
                    $newW   <= $chosenTpl->getMaxWeight() &&
                    $newL   <= $chosenTpl->getInnerLength() &&
                    $newW_  <= $chosenTpl->getInnerWidth()  &&
                    $newH_  <= $chosenTpl->getInnerHeight() &&
                    $newV   <= $tplVol
                ) {
                    $sumW   = $newW;
                    $sumV   = $newV;
                    $maxL   = $newL;
                    $maxW_  = $newW_;
                    $maxH   = $newH_;
                    unset($items[$k]);
                }
            }

            // g) Calcul volumétrique sur la bounding-box réelle
            $boxVolume = $maxL * $maxW_ * $maxH;
            $volWeight = $boxVolume / $chosenTpl->getVolumetricDivisor();
            $billable  = max($sumW, $volWeight);

            // h) On crée le ParcelDto avec les dims réelles
            $parcels[] = new ParcelDto($billable, $maxL, $maxW_, $maxH);
        }

        return $parcels;
    }
}
