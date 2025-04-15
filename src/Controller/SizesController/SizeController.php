<?php

namespace App\Controller\SizesController;

use App\UseCase\SizesUseCase\GetSizesUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;


class SizeController extends AbstractController
{
    private GetSizesUseCase $getSizesUseCase;
    private CacheInterface $cache;

    public function __construct(GetSizesUseCase $getSizesUseCase, CacheInterface $cache)
    {
        $this->getSizesUseCase = $getSizesUseCase;
        $this->cache = $cache;
    }

    #[Route('/api/sizes', name: 'get_sizes', methods: ['GET'])]
    public function getSizes(): JsonResponse
    {
        $cacheKey = 'sizes_all';
        $sizesArray = $this->cache->get($cacheKey, function (ItemInterface $item) {
            $item->expiresAfter(3600); // 1 heure
            return $this->getSizesUseCase->execute();
        });

        return new JsonResponse($sizesArray, JsonResponse::HTTP_OK);
    }
}
