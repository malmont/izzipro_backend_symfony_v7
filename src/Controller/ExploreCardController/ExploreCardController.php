<?php
namespace App\Controller\ExploreCardController;

use App\UseCase\ExploreCardUseCase\GetExploreCardUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/explore-cards', name: 'api_explore_cards', methods: ['GET'])]
class ExploreCardController extends AbstractController
{
    public function __invoke(
        GetExploreCardUseCase $useCase,
        Request $request,
        TenantCacheService $cache
    ): JsonResponse {
        $locale = $request->getLocale();
        $cacheKey = 'explore_cards_all_' . $locale;
        $host = $request->getSchemeAndHttpHost();

        $dtos = $cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($useCase, $host, $locale) {
                $item->expiresAfter(3600);
                $item->tag(['explore_cards_all']);

                return $useCase->execute($host, $locale);
            }
        );
        return $this->json($dtos);
    }
}