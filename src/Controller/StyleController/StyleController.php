<?php

namespace App\Controller\StyleController;

use App\UseCase\StylesUseCase\GetStylesUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;

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
            function (ItemInterface $item) {
                $item->expiresAfter(3600); // 1 heure
                $item->tag(['styles_all']);
                return $this->getStylesUseCase->execute();
            },
            /* ttl */ 3600,
            /* extraTags */ ['styles_all']
        );

        return new JsonResponse($stylesArray, JsonResponse::HTTP_OK);
    }
}
