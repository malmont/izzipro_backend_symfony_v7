<?php

namespace App\UseCase\CarrierUseCase;

use App\Services\CarrierService\CarrierService;
use App\Dto\CarrierDTO;

class GetAllCarriersUseCase
{
    private CarrierService $carrierService;

    public function __construct(CarrierService $carrierService)
    {
        $this->carrierService = $carrierService;
    }

    public function execute(string $host, string $locale): array
    {
        $carriers = $this->carrierService->getAllCarriers();
        return array_map(
            fn($carrier) => CarrierDTO::fromEntity($carrier, $host, $locale), 
            $carriers
        );
    }
}