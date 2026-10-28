<?php

namespace App\Controller\Api;

use App\Cache\CacheLocale;
use App\Cache\CacheTags;
use App\UseCase\VehicleUseCase\GetVehiclesForCarouselUseCase;
use App\Services\TenantCacheService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/vehicles')]
class VehicleApiController extends AbstractController
{
    public function __construct(
        private GetVehiclesForCarouselUseCase $getVehiclesForCarouselUseCase,
        private TenantCacheService $cache
    ) {}

    #[Route('/carousel', name: 'api_vehicles_carousel', methods: ['GET'], priority: 10)]
    public function getCarouselVehicles(Request $request): JsonResponse
    {
        $locale = CacheLocale::normalize($request->query->get('locale'));
        $cacheKey = 'vehicles_carousel_' . $locale;

        $vehiclesDto = $this->cache->get(
            $cacheKey,
            fn () => $this->getVehiclesForCarouselUseCase->execute($locale),
            3600,
            CacheTags::listTags(CacheTags::VEHICLES)
        );

        return $this->json($vehiclesDto);
    }
}
