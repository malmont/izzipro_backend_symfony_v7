<?php
namespace App\Controller\PresentationApiController;

use App\UseCase\PresentationUseCase\GetAllPresentationsUseCase;
use App\UseCase\PresentationUseCase\GetPresentationByIdUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/presentations')]
class PresentationApiController extends AbstractController
{
    public function __construct(
        private GetAllPresentationsUseCase $getAllUseCase,
        private GetPresentationByIdUseCase $getByIdUseCase,
        private TenantCacheService $cache 
    ) {}

    #[Route('', name: 'api_presentation_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $locale = $request->getLocale();
        $cacheKey = 'presentations_all_' . $locale;
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/slider';

        $dtos = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($locale, $baseImageUrl) {
                $item->expiresAfter(3600);
                $item->tag(['presentations_all']);

                return $this->getAllUseCase->execute($baseImageUrl, $locale);
            }
        );

        return $this->json($dtos);
    }

    #[Route('/{id}', name: 'api_presentation_get_one', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOne(int $id, Request $request): JsonResponse
    {
        $locale = $request->getLocale();
        $cacheKey = 'presentation_' . $id . '_' . $locale;
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/slider';

        $dto = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($id, $locale, $baseImageUrl) {
                $item->expiresAfter(3600);
                $item->tag(['presentations_all', 'presentation_' . $id]);

                return $this->getByIdUseCase->execute($id, $baseImageUrl, $locale);
            }
        );
        if (!$dto) {
            return $this->json(['message' => 'Présentation non trouvée'], Response::HTTP_NOT_FOUND);
        }
        return $this->json($dto);
    }
}