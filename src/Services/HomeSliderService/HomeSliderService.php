<?php
namespace App\Services\HomeSliderService;

use App\Entity\HomeSlider;
use App\Repository\HomeSliderRepository;
use App\Services\TenantEntityManagerProvider;

class HomeSliderService 
{
    private HomeSliderRepository $repository;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $em = $emProvider->getEntityManager();
        $this->repository = $em->getRepository(HomeSlider::class);
    }

    public function getAllHomeSlidersByLocale(string $locale): array
    {
        return $this->repository->findAllByLocale($locale);
    } 
}