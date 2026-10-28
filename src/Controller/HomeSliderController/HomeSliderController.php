<?php

namespace App\Controller\HomeSliderController;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Cache\CacheLocale;
use App\Cache\CacheTags;
use App\UseCase\GetAllHomeSliderUseCase\GetAllHomeSliderUseCase;
use App\Services\MediaUrlResolver;
use App\Services\TenantCacheService;

class HomeSliderController extends AbstractController
{
    public function __construct(
        private GetAllHomeSliderUseCase $getAllHomeSliderUseCase,
        private TenantCacheService $cache,
        private ?MediaUrlResolver $mediaUrlResolver = null
    ) {}

    #[Route('/api/homeslider', name: 'get_home_slider', methods: ['GET'])]
    public function getHomeSlider(Request $request): JsonResponse
    {
        $host = $this->mediaUrlResolver?->getPublicHost($request->getSchemeAndHttpHost())
            ?? $request->getSchemeAndHttpHost();
        $locale = CacheLocale::normalize($request->query->get('locale'));
        $cacheKey = 'homeslider_all_' . $locale;

        $homeSliderDto = $this->cache->get(
            $cacheKey,
            fn () => $this->getAllHomeSliderUseCase->execute($host, $locale),
            3600,
            CacheTags::listTags(CacheTags::HOMESLIDER)
        );

        return $this->json($homeSliderDto, JsonResponse::HTTP_OK);
    }
}