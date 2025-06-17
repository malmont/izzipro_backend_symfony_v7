<?php
namespace App\Services\SizesService;

use App\Entity\Size;
use App\Services\TenantEntityManagerProvider; 

class SizeService
{

    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getAllSizes(): array
    {
        $em = $this->emProvider->getEntityManager();
        
        return $em->getRepository(Size::class)->findAll();
    }
}