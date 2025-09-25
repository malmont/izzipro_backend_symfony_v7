<?php

namespace App\Controller\ColorsController;


use App\UseCase\ColorUseCase\GetAllColorsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
    public function getColors(Request $request): JsonResponse
    {
        $locale = $request->getLocale();
        $cacheKey = 'colors_all_' . $locale;

        $colorsDto = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($locale) {
                $item->expiresAfter(3600); 
                $item->tag(['colors_all']);
                
                return $this->getAllColorsUseCase->execute($locale);
            }
        );
        
        return $this->json($colorsDto);
    }
}