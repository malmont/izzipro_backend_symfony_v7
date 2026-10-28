<?php

namespace App\Controller\ColorsController;


use App\UseCase\ColorUseCase\GetAllColorsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Cache\CacheLocale;
use App\Cache\CacheTags;
use App\Services\TenantCacheService;

class ColorController extends AbstractController
{
    private GetAllColorsUseCase $getAllColorsUseCase;
    private TenantCacheService $cache;

    public function __construct(GetAllColorsUseCase $getAllColorsUseCase, TenantCacheService $cache)
    {
        $this->getAllColorsUseCase = $getAllColorsUseCase;
        $this->cache = $cache;
    }

    #[Route('/api/colors', name: 'get_colors', methods: ['GET'])]
    public function getColors(Request $request): JsonResponse
    {
        $locale = CacheLocale::normalize($request->getLocale());
        $cacheKey = 'colors_all_' . $locale;

        $colorsDto = $this->cache->get(
            $cacheKey,
            fn () => $this->getAllColorsUseCase->execute($locale),
            3600,
            CacheTags::listTags(CacheTags::COLORS)
        );
        
        return $this->json($colorsDto);
    }
}