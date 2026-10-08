<?php

namespace App\Services\OrderService;

use App\Entity\Product;

/**
 * Une ligne de panier ou de commande est-elle une location ? Un produit peut se vendre et se louer (boutique
 * réglable, 08/10/2026) : c'est une location si la location est activée et que la ligne porte des dates (booking ou
 * rental), ou si le produit ne se vend pas. Même règle pour le montant (create-intent), le stock et la réservation.
 */
final class RentalLineResolver
{
    /** @param array<string, mixed> $item ligne reçue (productVariantId, quantity, booking|rental…) */
    public static function isRental(?Product $product, array $item): bool
    {
        if ($product === null || !$product->isRentalEnabled()) {
            return false;
        }
        $booking = $item['booking'] ?? $item['rental'] ?? null;

        return (is_array($booking) && $booking !== []) || !$product->isSaleEnabled();
    }
}
