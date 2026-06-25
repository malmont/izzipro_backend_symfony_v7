<?php

namespace App\Services\VehicleService;

use App\Entity\Vehicle;
use App\Services\TenantEntityManagerProvider;

class VehicleService
{
    public function __construct(
        private TenantEntityManagerProvider $emProvider
    ) {}

    /**
     * Retrieve vehicles that are flagged for web display.
     *
     * @return Vehicle[]
     */
    public function getVehiclesForCarousel(): array
    {
        $em = $this->emProvider->getEntityManager();
        return $em->getRepository(Vehicle::class)->findBy(
            ['webDisplay' => true],
            ['id' => 'DESC']
        );
    }
}
