<?php
// src/Services/ShippingService/PackagingService.php

namespace App\Services\ShippingService;

use App\Exception\ItemTooLargeForPackagingException;
use App\Entity\PackagingType;
use App\Entity\ProductShipping;
use DVDoug\BoxPacker\Packer;
use DVDoug\BoxPacker\PackedBoxList;

class PackagingService
{
    public function __construct() {}

    /**
     * Calcule les colis optimisés
     *
     * @param ProductShipping[] $items
     * @param PackagingType[] $templates
     * @return PackedBoxList
     * @throws ItemTooLargeForPackagingException
     */
    public function computeParcels(array $items, array $templates): PackedBoxList
    {
        // 1) Grouper par ID de ShippingClass
        $buckets = [];
        foreach ($items as $item) {
            $sc  = $item->getShippingClassEntity();
            $key = $sc ? $sc->getId() : 'default';
            $buckets[$key][] = $item;
        }

        $packer = new Packer();

        // 2) Ajouter tous les gabarits (boîtes)
        foreach ($templates as $box) {
            $packer->addBox($box);
        }

        // 3) Pour chaque bucket, ajouter les items
        foreach ($buckets as $groupItems) {
            foreach ($groupItems as $item) {
                $packer->addItem($item, 1); // 1 = quantité
            }
        }

        // 4) Lancer le calcul
        $packedBoxes = $packer->pack();


        $tooLargeItems = $packer->getUnpackedItems();

        if (count($tooLargeItems) > 0) {
            
            $firstItem = null;
            foreach ($tooLargeItems as $item) {
                $firstItem = $item;
                break;
            }

            throw new ItemTooLargeForPackagingException(
                "L'article '{$firstItem->getDescription()}' (dimensions: {$firstItem->getWidth()}x{$firstItem->getLength()}x{$firstItem->getDepth()} mm) est trop grand pour tous les gabarits d'emballage disponibles."
            );
        }

        // 6) Retourner la collection de colis (qui est un objet PackedBoxList)
        return $packedBoxes;
    }
}

