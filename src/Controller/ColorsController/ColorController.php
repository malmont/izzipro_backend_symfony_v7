<?php

namespace App\Controller\ColorsController;

use App\Dto\ColorOutputDTO;
use App\UseCase\ColorUseCase\GetAllColorsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;

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
    public function getColors(): JsonResponse
    {
        $cacheKey = 'colors_all';
        $colorsArray = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) {
                $item->expiresAfter(3600); 
                $colors = $this->getAllColorsUseCase->execute();
                return array_map(function ($color) {
                    $dto = new ColorOutputDTO($color);
                    return method_exists($dto, 'toArray') ? $dto->toArray() : $dto;
                }, $colors);
            },

        );
        
        return new JsonResponse($colorsArray, JsonResponse::HTTP_OK);
    }
}
