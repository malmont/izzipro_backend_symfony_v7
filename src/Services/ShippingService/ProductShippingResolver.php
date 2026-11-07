<?php

namespace App\Services\ShippingService;

use App\Entity\Product;
use App\Entity\ProductShipping;
use App\Entity\ShippingClass;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\TenantEntityManagerProvider;

/**
 * Poids et dimensions effectifs d'un produit (livraison, lot 1 bis, 07/11/2026) : sa fiche poids si elle en a une, sinon les valeurs
 * par défaut de sa classe d'expédition, sinon le poids par défaut du site (réglage `shipping.default_weight_kg`) avec des dimensions
 * types. Avant, un produit sans fiche faisait échouer tout le calcul des colis et des tarifs du panier. L'objet rendu en repli est
 * transitoire (jamais persisté) ; `source` dit d'où viennent les valeurs.
 */
class ProductShippingResolver
{
    /** Dimensions types d'un produit sans fiche ni classe : longueur, largeur, hauteur en cm */
    public const DEFAULT_DIMENSIONS_CM = [20.0, 15.0, 10.0];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly BoutiqueSettingsService $settings)
    {
    }

    /** @return array{item: ProductShipping, source: string} source : product | class | site */
    public function resolve(int $productId): array
    {
        $em = $this->emProvider->getEntityManager();
        /** @var ProductShipping|null $row */
        $row = $em->getRepository(ProductShipping::class)->findOneBy(['product' => $productId]);
        $class = $row?->getShippingClassEntity();
        if ($row !== null && (float) $row->getWeightKg() > 0) {
            if ($row->getWidthCm() !== null && $row->getHeightCm() !== null && (float) $row->getLengthCm() > 0) {
                return ['item' => $row, 'source' => 'product'];
            }
            // Fiche incomplète : copie transitoire complétée par la classe ou les valeurs types, la fiche n'est pas modifiée
            [$l, $w, $h] = $this->dimensionsOf($class);

            return ['item' => $this->transient((float) $row->getWeightKg(), (float) $row->getLengthCm() > 0 ? (float) $row->getLengthCm() : $l, $row->getWidthCm() ?? $w, $row->getHeightCm() ?? $h, $class), 'source' => 'product'];
        }
        if ($class !== null && (float) $class->getDefaultWeightKg() > 0) {
            [$l, $w, $h] = $this->dimensionsOf($class);

            return ['item' => $this->transient((float) $class->getDefaultWeightKg(), $l, $w, $h, $class), 'source' => 'class'];
        }
        [$l, $w, $h] = self::DEFAULT_DIMENSIONS_CM;

        return ['item' => $this->transient($this->settings->shippingSettings()['defaultWeightKg'], $l, $w, $h, $class), 'source' => 'site'];
    }

    public function forProductId(int $productId): ProductShipping
    {
        return $this->resolve($productId)['item'];
    }

    /** @return array{0: float, 1: float, 2: float} */
    private function dimensionsOf(?ShippingClass $class): array
    {
        if ($class !== null && (float) $class->getDefaultLengthCm() > 0 && (float) $class->getDefaultWidthCm() > 0 && (float) $class->getDefaultHeightCm() > 0) {
            return [(float) $class->getDefaultLengthCm(), (float) $class->getDefaultWidthCm(), (float) $class->getDefaultHeightCm()];
        }

        return self::DEFAULT_DIMENSIONS_CM;
    }

    private function transient(float $weightKg, float $lengthCm, float $widthCm, float $heightCm, ?ShippingClass $class): ProductShipping
    {
        // Sans produit rattaché : rien ne doit persister cette copie par cascade
        $item = (new ProductShipping())->setWeightKg($weightKg)->setLengthCm($lengthCm)->setWidthCm($widthCm)->setHeightCm($heightCm);
        if ($class !== null) {
            $item->setShippingClassEntity($class)->setShippingClass($class->getName());
        }

        return $item;
    }
}
