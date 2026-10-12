<?php

namespace App\Services\SiteContentService;

use App\Entity\DemoContentInterface;
use App\Entity\ProductCustomizationImage;
use App\Entity\ProductOptionValue;
use App\Entity\ProductPicture;
use App\Entity\ProductVariant;

/**
 * Une fiche d'exemple modifiée par le client devient une fiche définitive (isDemo: false) : appelé par les écritures
 * de l'éditeur (PATCH d'un contenu, variantes, valeurs et combinaisons de personnalisation, présentations d'un groupe)
 * et de l'administration (EasyAdmin), avant leur enregistrement. Une sous-fiche (variante, photo, combinaison, valeur
 * d'option) rend définitive la fiche qui la porte (produit, option).
 *
 * Les écritures automatiques (stock décompté par une commande, note moyenne d'un avis) ne passent pas par ici : une
 * fiche d'exemple reste d'exemple tant que le client ne l'a pas modifiée lui-même.
 *
 * À appeler après l'enregistrement de l'écriture, puis enregistrer à nouveau si la fiche a changé (retour true) : une
 * variante enregistrée relance un flush imbriqué (quantité du produit, ProductVariant) qui écraserait sinon les autres
 * changements en attente du produit.
 */
final class DemoContentMarker
{
    /** @return bool true si la fiche est devenue définitive (à enregistrer) */
    public static function customized(?object $entity): bool
    {
        $owner = match (true) {
            $entity instanceof ProductVariant, $entity instanceof ProductPicture => $entity->getProduct(),
            $entity instanceof ProductCustomizationImage => $entity->getProductVariant()?->getProduct(),
            $entity instanceof ProductOptionValue => $entity->getProductOption(),
            default => $entity,
        };
        if ($owner instanceof DemoContentInterface && $owner->isDemo()) {
            $owner->setIsDemo(false);

            return true;
        }

        return false;
    }
}
