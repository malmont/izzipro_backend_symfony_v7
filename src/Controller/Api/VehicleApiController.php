<?php

namespace App\Controller\Api;

use App\UseCase\VehicleUseCase\GetVehiclesForCarouselUseCase;
use App\Services\TenantCacheService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/vehicles')]
class VehicleApiController extends AbstractController
{
    public function __construct(
        private GetVehiclesForCarouselUseCase $getVehiclesForCarouselUseCase,
        private TenantCacheService $cache
    ) {}

    #[Route('/carousel', name: 'api_vehicles_carousel', methods: ['GET'], priority: 10)]
    public function getCarouselVehicles(): JsonResponse
    {
        $cacheKey = 'vehicles_carousel';
        $cacheTags = ['vehicles'];

        $vehiclesDto = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) {
                $item->expiresAfter(3600);
                return $this->getVehiclesForCarouselUseCase->execute();
            },
            3600,
            $cacheTags
        );

        return $this->json($vehiclesDto);
    }
}
