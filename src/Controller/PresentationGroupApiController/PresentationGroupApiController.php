<?php
namespace App\Controller\PresentationGroupApiController;

use App\UseCase\PresentationGroupUseCase\GetAllPresentationGroupsUseCase;
use App\UseCase\PresentationGroupUseCase\GetPresentationGroupByIdUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/presentation-groups')]
class PresentationGroupApiController extends AbstractController
{
    public function __construct(
        private GetAllPresentationGroupsUseCase $getAllUseCase,
        private GetPresentationGroupByIdUseCase $getByIdUseCase,
        private TenantCacheService $cache
    ) {}

    #[Route('', name: 'api_presentation_group_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $locale = $request->query->get('locale', 'fr'); 
        $cacheKey = 'presentation_groups_all_' . $locale;
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/slider';

        $dtos = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($locale, $baseImageUrl) {
                $item->expiresAfter(3600);
                $item->tag(['presentation_groups_all']);

                return $this->getAllUseCase->execute($baseImageUrl, $locale);
            }
        );

        return $this->json($dtos);
    }

    #[Route('/{id}', name: 'api_presentation_group_get_one', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOne(int $id, Request $request): JsonResponse
        {
        $locale = $request->query->get('locale', 'fr'); 
        $cacheKey = 'presentation_group_' . $id . '_' . $locale;
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/slider';

        $dto = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($id, $locale, $baseImageUrl) {
                $item->expiresAfter(3600);
                $item->tag(['presentation_groups_all', 'presentation_group_' . $id]);

                return $this->getByIdUseCase->execute($id, $baseImageUrl, $locale);
            }
        );

        if (!$dto) {
            return $this->json(['message' => 'Groupe de présentations non trouvé'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json($dto);
    }
}