<?php
namespace App\Controller\BaniereStatiqueApiController;

use App\Cache\CacheLocale;
use App\Cache\CacheTags;
use App\Services\MediaUrlResolver;
use App\Services\TenantCacheService;
use App\UseCase\BaniereStatiqueUseCase\GetAllBaniereStatiquesUseCase;
use App\UseCase\BaniereStatiqueUseCase\GetBaniereStatiqueByIdUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/baniere-statiques')]
class BaniereStatiqueApiController extends AbstractController
{
    public function __construct(
        private GetAllBaniereStatiquesUseCase $getAllUseCase,
        private GetBaniereStatiqueByIdUseCase $getByIdUseCase,
        private TenantCacheService $cache,
        private ?MediaUrlResolver $mediaUrlResolver = null
    ) {}

    #[Route('', name: 'api_baniere_statique_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $locale = CacheLocale::normalize($request->query->get('locale'));
        $cacheKey = 'bannieres_statiques_all_' . $locale;
        $baseImageUrl = $this->mediaUrlResolver?->getSliderBaseUrl($request->getSchemeAndHttpHost())
            ?? ($request->getSchemeAndHttpHost() . '/assets/uploads/slider');

        $dtos = $this->cache->get(
            $cacheKey,
            fn () => $this->getAllUseCase->execute($locale, $baseImageUrl),
            3600,
            CacheTags::listTags(CacheTags::BANNIERES_STATIQUES)
        );

        return $this->json($dtos);
    }

    #[Route('/{id}', name: 'api_baniere_statique_get_one', methods: ['GET'])]
    public function getOne(int $id, Request $request): JsonResponse
    {
        $locale = CacheLocale::normalize($request->query->get('locale'));
        $cacheKey = 'baniere_statique_' . $id . '_' . $locale;
        $baseImageUrl = $this->mediaUrlResolver?->getSliderBaseUrl($request->getSchemeAndHttpHost())
            ?? ($request->getSchemeAndHttpHost() . '/assets/uploads/slider');

        $dto = $this->cache->get(
            $cacheKey,
            fn () => $this->getByIdUseCase->execute($id, $locale, $baseImageUrl),
            3600,
            CacheTags::itemTags(CacheTags::BANNIERES_STATIQUES, $id)
        );

        if (!$dto) {
            return $this->json(['message' => 'Bannière statique non trouvée'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json($dto);
    }
}