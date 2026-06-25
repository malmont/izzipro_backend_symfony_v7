<?php

namespace App\UseCase\VehicleUseCase;

use App\Services\VehicleService\VehicleService;
use App\Dto\VehicleOutputDto;

class GetVehiclesForCarouselUseCase
{
    public function __construct(
        private VehicleService $vehicleService
    ) {}

    /**
     * Fetch visible vehicles for the carousel and map them to DTOs.
     *
     * @return VehicleOutputDto[]
     */
    public function execute(): array
    {
        $vehicles = $this->vehicleService->getVehiclesForCarousel();

        return array_map(function ($vehicle) {
            return new VehicleOutputDto($vehicle);
        }, $vehicles);
    }
}
