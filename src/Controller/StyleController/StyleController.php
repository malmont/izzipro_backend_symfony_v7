<?php

namespace App\Controller\StyleController;

use App\UseCase\StylesUseCase\GetStylesUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use App\Cache\CacheTags;
use App\Services\TenantCacheService;

class StyleController extends AbstractController
{
    private GetStylesUseCase $getStylesUseCase;
    private TenantCacheService $cache;

    public function __construct(GetStylesUseCase $getStylesUseCase, TenantCacheService $cache)
    {
        $this->getStylesUseCase = $getStylesUseCase;
        $this->cache = $cache;
    }

    #[Route('/api/styles', name: 'get_styles', methods: ['GET'])]
    public function getStyles(): JsonResponse
    {
        $cacheKey = 'styles_all';

        $stylesArray = $this->cache->get(
            $cacheKey,
            fn () => $this->getStylesUseCase->execute(),
            3600,
            CacheTags::listTags(CacheTags::STYLES)
        );

        return new JsonResponse($stylesArray, JsonResponse::HTTP_OK);
    }
}
