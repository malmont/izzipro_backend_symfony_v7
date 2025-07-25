<?php
namespace App\Controller\RechercheApiController;

use App\Dto\RechercheInputDto;
use App\Dto\RechercheOutputDto;
use App\Services\TenantCacheService;
use App\UseCase\RechercheUseCase\GetAllRecherchesUseCase;
use App\UseCase\RechercheUseCase\CreateRechercheUseCase;
use App\UseCase\RechercheUseCase\UpdateRechercheUseCase;
use App\UseCase\RechercheUseCase\DeleteRechercheUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/recherches')]
class RechercheApiController extends AbstractController
{
    public function __construct(
        private GetAllRecherchesUseCase $getAllRecherchesUseCase,
        private CreateRechercheUseCase $createRechercheUseCase,
        private UpdateRechercheUseCase $updateRechercheUseCase,
        private DeleteRechercheUseCase $deleteRechercheUseCase,
        private TenantCacheService $cache
    ) {}

    #[Route('', name: 'api_recherche_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $cacheKey = 'recherches_all';
        $cacheTags = ['recherches'];
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/uploads/recherches';

        $recherchesDto = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($baseImageUrl) {
                $recherches = $this->getAllRecherchesUseCase->execute();
                return array_map(fn($recherche) => new RechercheOutputDto($recherche, $baseImageUrl), $recherches);
            },
            3600,
            $cacheTags
        );

        return $this->json($recherchesDto);
    }

    #[Route('', name: 'api_recherche_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] RechercheInputDto $dto, Request $request): JsonResponse
    {
        $recherche = $this->createRechercheUseCase->execute($dto);
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/uploads/recherches';
        return $this->json(new RechercheOutputDto($recherche, $baseImageUrl), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_recherche_update', methods: ['PUT'])]
    public function update(int $id, #[MapRequestPayload] RechercheInputDto $dto, Request $request): JsonResponse
    {
        $recherche = $this->updateRechercheUseCase->execute($id, $dto);
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/uploads/recherches';
        return $this->json(new RechercheOutputDto($recherche));
    }

    #[Route('/{id}', name: 'api_recherche_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->deleteRechercheUseCase->execute($id);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
