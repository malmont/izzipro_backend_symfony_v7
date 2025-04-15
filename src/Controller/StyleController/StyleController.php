<?php

namespace App\Controller\StyleController;

use App\UseCase\StylesUseCase\GetStylesUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;


class StyleController extends AbstractController
{
    private GetStylesUseCase $getStylesUseCase;
    private CacheInterface $cache;

    public function __construct(GetStylesUseCase $getStylesUseCase, CacheInterface $cache)
    {
        $this->getStylesUseCase = $getStylesUseCase;
        $this->cache = $cache;
    }

    #[Route('/api/styles', name: 'get_styles', methods: ['GET'])]
    public function getStyles(): JsonResponse
    {
        $cacheKey = 'styles_all';
        $stylesArray = $this->cache->get($cacheKey, function (ItemInterface $item) {
            $item->expiresAfter(3600); // 1 heure
            return $this->getStylesUseCase->execute();
        });

        return new JsonResponse($stylesArray, JsonResponse::HTTP_OK);
    }
}
