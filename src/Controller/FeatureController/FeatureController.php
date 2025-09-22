<?php
namespace App\Controller\FeatureController;

use App\UseCase\FeatureUseCase\GetFeaturesUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;     
use Symfony\Component\Routing\Annotation\Route;
use App\Services\TenantCacheService; 
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/features', name: 'api_features', methods: ['GET'])]
class FeatureController extends AbstractController
{
    public function __invoke(
        GetFeaturesUseCase $useCase,
        Request $request,
        TenantCacheService $cache
    ): JsonResponse {
        // On force la lecture depuis les paramètres de la requête
        $locale = $request->query->get('locale', 'fr');
        $cacheKey = 'features_all_' . $locale;
        $host = $request->getSchemeAndHttpHost();

        $dtos = $cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($useCase, $host, $locale) {
                $item->expiresAfter(3600);
                $item->tag(['features_all', 'locale_' . $locale]);

                return $useCase->execute($host, $locale);
            }
        );
        return $this->json($dtos);
    }
}