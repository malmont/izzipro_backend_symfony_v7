<?php

namespace App\Dto;

use App\Entity\Product;

/**
 * La fiche d'un article telle que la GESTION la lit dans une commande fournisseur (`GET /api/commandes/{id}/products`, réservée à
 * l'administrateur) : la fiche publique, plus le coût d'achat et le coefficient. Lot COST-1 (16/11/2026) : ces deux champs ne vivent
 * plus que dans cette réponse-ci et dans `ProductOutputDTO` (réponse de la création d'un article par une commande fournisseur, elle
 * aussi réservée à l'administrateur) ; ne jamais rendre l'une ou l'autre par une route publique.
 */
class ProductPurchaseOutputDTO extends ProductDetailedOutputDTO
{
    public ?float $purchasePrice;
    public ?float $coefficientMultiplier;

    public function __construct(Product $product, string $host, string $locale = 'fr', string $currency = ProductCommerceDto::DEFAULT_CURRENCY)
    {
        parent::__construct($product, $host, $locale, $currency);
        $this->purchasePrice = $product->getPurchasePrice();
        $this->coefficientMultiplier = $product->getCoefficientMultiplier();
    }
}
