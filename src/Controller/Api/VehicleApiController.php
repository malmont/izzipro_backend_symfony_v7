<?php

namespace App\Controller\Api;

use App\UseCase\VehicleUseCase\GetVehiclesForCarouselUseCase;
use App\Services\TenantCacheService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
    public function getCarouselVehicles(Request $request): JsonResponse
    {
        $locale = $request->query->get('locale', 'fr');
        $cacheKey = 'vehicles_carousel_' . $locale;
        $cacheTags = ['vehicles'];

        $vehiclesDto = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($locale) {
                $item->expiresAfter(3600);
                return $this->getVehiclesForCarouselUseCase->execute($locale);
            },
            3600,
            $cacheTags
        );

        return $this->json($vehiclesDto);
    }
}
