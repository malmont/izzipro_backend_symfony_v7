<?php

namespace App\UseCase\Currency;

use App\Services\CurrencyService\CurrencyService;
use App\Dto\CurrencyOutputDto;

class GetAvailableCurrencies
{
    public function __construct(
        private CurrencyService $currencyService
    ) {}

    /**
     * @return CurrencyOutputDto[]
     */
    public function execute(): array
    {
        $entities = $this->currencyService->getCurrenciesOrUpdate();
        
        $outputs = [];

        foreach ($entities as $currency) {
            $outputs[] = new CurrencyOutputDto(
                code: $currency->getCode(),
                symbol: $currency->getSymbol(),
                name: $currency->getName(),
                rate: (float) $currency->getExchangeRate()
            );
        }

        return $outputs;
    }
}
