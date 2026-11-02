<?php
namespace App\UseCase\OrderUseCase;


use App\Entity\Order;

class CalculateTotalAmountUseCase
{
    /**
     * Total payé = sous-total des lignes + livraison (+ taxes hors TTC). Même règle que le devis (subtotal, shipping, total).
     *
     * @param bool $pricesIncludeTax lignes TTC (F18 partie 2b) : la taxe est déjà dans le sous-total et la livraison
     * @param float $shipping frais de livraison (signés comme le sous-total)
     */
    public function execute(float $subtotal, float $totalTax, bool $pricesIncludeTax = false, float $shipping = 0.0): float
    {
        return $subtotal + $shipping + ($pricesIncludeTax ? 0.0 : $totalTax);
    }
}
