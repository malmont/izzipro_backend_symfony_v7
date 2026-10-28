<?php
namespace App\Controller\ExploreCardController;

use App\Cache\CacheLocale;
use App\Cache\CacheTags;
use App\Services\MediaUrlResolver;
use App\UseCase\ExploreCardUseCase\GetExploreCardUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use App\Services\TenantCacheService;

#[Route('/api/explore-cards', name: 'api_explore_cards', methods: ['GET'])]
class ExploreCardController extends AbstractController
{
    public function __invoke(
        GetExploreCardUseCase $useCase,
        Request $request,
        TenantCacheService $cache,
        ?MediaUrlResolver $mediaUrlResolver = null
    ): JsonResponse {
        $locale = CacheLocale::normalize($request->query->get('locale'));
        $cacheKey = 'explore_cards_all_' . $locale;
        $host = $mediaUrlResolver?->getPublicHost($request->getSchemeAndHttpHost())
            ?? $request->getSchemeAndHttpHost();

        $dtos = $cache->get(
            $cacheKey,
            fn () => $useCase->execute($host, $locale),
            3600,
            CacheTags::listTags(CacheTags::EXPLORE_CARDS)
        );
        
        return $this->json($dtos);
    }
}