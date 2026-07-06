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
    public function execute(string $locale = 'fr'): array
    {
        $vehicles = $this->vehicleService->getVehiclesForCarousel();

        return array_map(function ($vehicle) use ($locale) {
            return new VehicleOutputDto($vehicle, $locale);
        }, $vehicles);
    }
}
