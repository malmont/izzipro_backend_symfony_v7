<?php

namespace App\UseCase\VehicleProductUseCase;

use App\Dto\VehicleProductOutputDTO;
use App\Services\VehicleProductService\VehicleProductService;

/** GET /api/vehicle_products : une page de 24, en tableau simple (le format que la boutique lisait d'API Platform) */
class ListVehicleProductsUseCase
{
    public function __construct(private VehicleProductService $vehicleProducts)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function execute(int $page): array
    {
        return array_map(VehicleProductOutputDTO::fromEntity(...), $this->vehicleProducts->page($page));
    }
}
