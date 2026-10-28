<?php
namespace App\Controller\PresentationGroupApiController;

use App\UseCase\PresentationGroupUseCase\GetAllPresentationGroupsUseCase;
use App\UseCase\PresentationGroupUseCase\GetPresentationGroupByIdUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Cache\CacheLocale;
use App\Cache\CacheTags;
use App\Services\MediaUrlResolver;
use App\Services\TenantCacheService;

#[Route('/api/presentation-groups')]
class PresentationGroupApiController extends AbstractController
{
    public function __construct(
        private GetAllPresentationGroupsUseCase $getAllUseCase,
        private GetPresentationGroupByIdUseCase $getByIdUseCase,
        private TenantCacheService $cache,
        private ?MediaUrlResolver $mediaUrlResolver = null
    ) {}

    #[Route('', name: 'api_presentation_group_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $locale = CacheLocale::normalize($request->query->get('locale'));
        $cacheKey = 'presentation_groups_all_' . $locale;
        $baseImageUrl = $this->mediaUrlResolver?->getSliderBaseUrl($request->getSchemeAndHttpHost())
            ?? ($request->getSchemeAndHttpHost() . '/assets/uploads/slider');

        // un groupe est lu avec ses présentations : il suit aussi leurs écritures
        $dtos = $this->cache->get(
            $cacheKey,
            fn () => $this->getAllUseCase->execute($baseImageUrl, $locale),
            3600,
            [...CacheTags::listTags(CacheTags::PRESENTATION_GROUPS), ...CacheTags::listTags(CacheTags::PRESENTATIONS)]
        );

        return $this->json($dtos);
    }

    #[Route('/{id}', name: 'api_presentation_group_get_one', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOne(int $id, Request $request): JsonResponse
        {
        $locale = CacheLocale::normalize($request->query->get('locale'));
        $cacheKey = 'presentation_group_' . $id . '_' . $locale;
        $baseImageUrl = $this->mediaUrlResolver?->getSliderBaseUrl($request->getSchemeAndHttpHost())
            ?? ($request->getSchemeAndHttpHost() . '/assets/uploads/slider');

        $dto = $this->cache->get(
            $cacheKey,
            fn () => $this->getByIdUseCase->execute($id, $baseImageUrl, $locale),
            3600,
            [...CacheTags::itemTags(CacheTags::PRESENTATION_GROUPS, $id), ...CacheTags::listTags(CacheTags::PRESENTATIONS)]
        );

        if (!$dto) {
            return $this->json(['message' => 'Groupe de présentations non trouvé'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json($dto);
    }
}