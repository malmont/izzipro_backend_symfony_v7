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
        // 1) Grouper par ID de ShippingClass (les "Buckets")
        $buckets = [];
        foreach ($items as $item) {
            $sc  = $item->getShippingClassEntity();
            $key = $sc ? $sc->getId() : 'default';
            $buckets[$key][] = $item;
        }


        // On va stocker tous les colis finaux de tous les buckets ici
        $allPackedBoxes = new PackedBoxList();

        // 2) Pour chaque bucket, FAIRE UN CALCUL D'EMBALLAGE SÉPARÉ
        foreach ($buckets as $shippingClassKey => $groupItems) {
            
            $packer = new Packer(); 

            // 3) Ajouter tous les gabarits (boîtes) à CE packer
            foreach ($templates as $box) {
                $packer->addBox($box);
            }

            // 4) Ajouter les items de CE BUCKET SEULEMENT
            foreach ($groupItems as $item) {
                $packer->addItem($item, 1); // 1 = quantité
            }

            // 5) Lancer le calcul pour CE BUCKET
            $packedBoxesForThisBucket = $packer->pack();

            // 6) Vérifier les articles trop grands pour CE BUCKET
            $unpackedItems = $packer->getUnpackedItems();

            // On vérifie s'il y a des articles non emballés
            if (count($unpackedItems) > 0) {
                
                $firstItem = null;
                foreach ($unpackedItems as $item) {
                    $firstItem = $item;
                    break;
                }

                throw new ItemTooLargeForPackagingException(
                    "L'article '{$firstItem->getDescription()}' (classe: {$shippingClassKey}) est trop grand pour tous les gabarits d'emballage disponibles."
                );
            }

            // 7) Ajouter les colis de ce bucket à la liste totale
            foreach ($packedBoxesForThisBucket as $packedBox) {
                $allPackedBoxes->insert($packedBox);
            }
        }

        // 8) Retourner la liste complète de TOUS les colis
        return $allPackedBoxes;
    }
}

