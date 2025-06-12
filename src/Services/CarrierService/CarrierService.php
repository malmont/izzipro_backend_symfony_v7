<?php
namespace App\Services\CarrierService;

use App\Entity\Carrier;
use App\Services\TenantEntityManagerProvider; 

class CarrierService
{

    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getAllCarriers(): array
    {
        $em = $this->emProvider->getEntityManager();
        return $em->getRepository(Carrier::class)->findAll();
    }
}