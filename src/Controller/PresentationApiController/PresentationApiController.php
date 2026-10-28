<?php
namespace App\Controller\PresentationApiController;

use App\UseCase\PresentationUseCase\GetAllPresentationsUseCase;
use App\UseCase\PresentationUseCase\GetPresentationByIdUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Cache\CacheLocale;
use App\Cache\CacheTags;
use App\Services\MediaUrlResolver;
use App\Services\TenantCacheService;

#[Route('/api/presentations')]
class PresentationApiController extends AbstractController
{
    public function __construct(
        private GetAllPresentationsUseCase $getAllUseCase,
        private GetPresentationByIdUseCase $getByIdUseCase,
        private TenantCacheService $cache,
        private ?MediaUrlResolver $mediaUrlResolver = null
    ) {}

    #[Route('', name: 'api_presentation_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $locale = CacheLocale::normalize($request->query->get('locale'));
        $cacheKey = 'presentations_all_' . $locale;
        $baseImageUrl = $this->mediaUrlResolver?->getSliderBaseUrl($request->getSchemeAndHttpHost())
            ?? ($request->getSchemeAndHttpHost() . '/assets/uploads/slider');

        $dtos = $this->cache->get(
            $cacheKey,
            fn () => $this->getAllUseCase->execute($baseImageUrl, $locale),
            3600,
            CacheTags::listTags(CacheTags::PRESENTATIONS)
        );

        return $this->json($dtos);
    }

    #[Route('/{id}', name: 'api_presentation_get_one', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOne(int $id, Request $request): JsonResponse
    {
        $locale = CacheLocale::normalize($request->query->get('locale'));
        $cacheKey = 'presentation_' . $id . '_' . $locale;
        $baseImageUrl = $this->mediaUrlResolver?->getSliderBaseUrl($request->getSchemeAndHttpHost())
            ?? ($request->getSchemeAndHttpHost() . '/assets/uploads/slider');

        $dto = $this->cache->get(
            $cacheKey,
            fn () => $this->getByIdUseCase->execute($id, $baseImageUrl, $locale),
            3600,
            CacheTags::itemTags(CacheTags::PRESENTATIONS, $id)
        );
        if (!$dto) {
            return $this->json(['message' => 'Présentation non trouvée'], Response::HTTP_NOT_FOUND);
        }
        return $this->json($dto);
    }
}