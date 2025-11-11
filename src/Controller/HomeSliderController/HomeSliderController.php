<?php

namespace App\Controller\HomeSliderController;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\UseCase\GetAllHomeSliderUseCase\GetAllHomeSliderUseCase;
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;

class HomeSliderController extends AbstractController
{
    public function __construct(
        private GetAllHomeSliderUseCase $getAllHomeSliderUseCase,
        private TenantCacheService $cache
    ) {}

    #[Route('/api/homeslider', name: 'get_home_slider', methods: ['GET'])]
    public function getHomeSlider(Request $request): JsonResponse
    {
        $host = $request->getSchemeAndHttpHost();
        $locale = $request->query->get('locale', 'fr');
        
        $cacheKey = 'homeslider_all_' . $locale;
        $tags = ['homeslider_all'];


        $homeSliderDto = $this->cache->get(
            $cacheKey,
            function(ItemInterface $item) use ($host, $locale) { 
                return $this->getAllHomeSliderUseCase->execute($host, $locale);
            },
            3600,
            $tags 
        );

        return $this->json($homeSliderDto, JsonResponse::HTTP_OK);
    }
}