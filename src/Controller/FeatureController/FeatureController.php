<?php
namespace App\Controller\FeatureController;

use App\Cache\CacheLocale;
use App\Cache\CacheTags;
use App\Services\MediaUrlResolver;
use App\UseCase\FeatureUseCase\GetFeaturesUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;     
use Symfony\Component\Routing\Annotation\Route;
use App\Services\TenantCacheService;

#[Route('/api/features', name: 'api_features', methods: ['GET'])]
class FeatureController extends AbstractController
{
    public function __invoke(
        GetFeaturesUseCase $useCase,
        Request $request,
        TenantCacheService $cache,
        ?MediaUrlResolver $mediaUrlResolver = null
    ): JsonResponse {
        // On force la lecture depuis les paramètres de la requête
        $locale = CacheLocale::normalize($request->query->get('locale'));
        $cacheKey = 'features_all_' . $locale;
        $host = $mediaUrlResolver?->getPublicHost($request->getSchemeAndHttpHost())
            ?? $request->getSchemeAndHttpHost();

        $dtos = $cache->get(
            $cacheKey,
            fn () => $useCase->execute($host, $locale),
            3600,
            CacheTags::listTags(CacheTags::FEATURES)
        );
        return $this->json($dtos);
    }
}