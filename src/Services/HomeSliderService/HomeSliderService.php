<?php
namespace App\Services\HomeSliderService;

use App\Entity\HomeSlider;
use App\Services\TenantEntityManagerProvider; // Ajouté

class HomeSliderService 
{
    private TenantEntityManagerProvider $tenantEmProvider;

    public function __construct(TenantEntityManagerProvider $tenantEmProvider)
    {
        $this->tenantEmProvider = $tenantEmProvider;
    }

    public function getAllhomeSliders(): array
    {
        $em = $this->tenantEmProvider->getEntityManager();
        return $em->getRepository(HomeSlider::class)->findAll(); 
    } 
}
