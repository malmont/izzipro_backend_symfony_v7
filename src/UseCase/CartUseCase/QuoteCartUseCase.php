<?php

namespace App\UseCase\CartUseCase;

use App\Dto\CartQuoteInputDto;
use App\Services\OrderService\CartQuoteCalculator;
use App\Services\OrderService\CartQuoteException;

/** POST /api/cart/quote : devis d'un panier, sans effet (rien n'est réservé ni enregistré) */
class QuoteCartUseCase
{
    public function __construct(private readonly CartQuoteCalculator $calculator)
    {
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws CartQuoteException
     */
    public function execute(array $body): array
    {
        return $this->calculator->quote(CartQuoteInputDto::fromArray($body));
    }
}
