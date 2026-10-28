<?php
namespace App\Controller\SizesController;

use App\UseCase\SizesUseCase\GetSizesUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request; // On importe Request
use Symfony\Component\Routing\Annotation\Route;
use App\Cache\CacheLocale;
use App\Cache\CacheTags;
use App\Services\TenantCacheService;

class SizeController extends AbstractController
{
    private GetSizesUseCase $getSizesUseCase;
    private TenantCacheService $cache;

    public function __construct(GetSizesUseCase $getSizesUseCase, TenantCacheService $cache)
    {
        $this->getSizesUseCase = $getSizesUseCase;
        $this->cache = $cache;
    }

    #[Route('/api/sizes', name: 'get_sizes', methods: ['GET'])]
    public function getSizes(Request $request): JsonResponse
    {
        $locale = CacheLocale::normalize($request->getLocale());
        $cacheKey = 'sizes_all_' . $locale;

        $sizesDto = $this->cache->get(
            $cacheKey,
            fn () => $this->getSizesUseCase->execute($locale),
            3600,
            CacheTags::listTags(CacheTags::SIZES)
        );

        return $this->json($sizesDto);
    }
}