<?php

namespace App\UseCase\CartUseCase;

use App\Dto\CartQuoteInputDto;
use App\Services\OrderService\CartQuoteCalculator;
use App\Services\OrderService\CartQuoteException;
use App\Services\OrderService\OrderTaxAddressResolver;

/**
 * POST /api/cart/quote : devis d'un panier, sans effet (rien n'est réservé ni enregistré).
 * Vente au comptoir (19/10/2026) : un devis qui porte cashRegisterId sans shippingAddress est taxé à l'adresse de la
 * fiche entreprise, comme la vente POST /api/order/create-multi-payment sans addressId : le POS obtient le même total.
 * Sans cashRegisterId (boutique web, application e-commerce), rien ne change : sans adresse, aucune taxe.
 */
class QuoteCartUseCase
{
    public function __construct(private readonly CartQuoteCalculator $calculator, private readonly OrderTaxAddressResolver $taxAddress)
    {
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws CartQuoteException
     */
    public function execute(array $body): array
    {
        if (is_numeric($body['cashRegisterId'] ?? null) && !is_array($body['shippingAddress'] ?? null)) {
            $store = $this->taxAddress->forStore();
            if ($store !== null) {
                $body['shippingAddress'] = $store;
            }
        }

        return $this->calculator->quote(CartQuoteInputDto::fromArray($body));
    }
}
