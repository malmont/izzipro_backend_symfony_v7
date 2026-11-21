<?php

namespace App\UseCase\VehicleProductUseCase;

use App\Dto\VehicleProductOutputDTO;
use App\Services\VehicleProductService\VehicleProductService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** GET /api/vehicle_products/{id} */
class GetVehicleProductUseCase
{
    public function __construct(private VehicleProductService $vehicleProducts)
    {
    }

    /** @return array<string, mixed> */
    public function execute(int $id): array
    {
        $vehicle = $this->vehicleProducts->find($id) ?? throw new NotFoundHttpException('Not Found');

        return VehicleProductOutputDTO::fromEntity($vehicle);
    }
}
