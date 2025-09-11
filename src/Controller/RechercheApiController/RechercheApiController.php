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
use App\UseCase\RechercheUseCase\GetRechercheByIdUseCase;

#[Route('/api/recherches')]
class RechercheApiController extends AbstractController
{
    public function __construct(
        private GetAllRecherchesUseCase $getAllRecherchesUseCase,
        private CreateRechercheUseCase $createRechercheUseCase,
        private UpdateRechercheUseCase $updateRechercheUseCase,
        private DeleteRechercheUseCase $deleteRechercheUseCase,
        private TenantCacheService $cache,
        private GetRechercheByIdUseCase $getRechercheByIdUseCase
    ) {}

   #[Route('', name: 'api_recherche_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $locale = $request->getLocale();
        $cacheKey = 'recherches_all_' . $locale;
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/uploads/recherches';

        $dtos = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($locale, $baseImageUrl) {
                $item->expiresAfter(3600);
                $item->tag(['recherches_all']);

                return $this->getAllRecherchesUseCase->execute($baseImageUrl, $locale);
            }
        );

        return $this->json($dtos);
    }

    #[Route('/{id}', name: 'api_recherche_get_one', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOne(int $id, Request $request): JsonResponse
    {
        $locale = $request->getLocale();
        $cacheKey = 'recherche_' . $id . '_' . $locale;
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/uploads/recherches';

        $dto = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($id, $locale, $baseImageUrl) {
                $item->expiresAfter(3600);
                $item->tag(['recherches_all', 'recherche_' . $id]);

                return $this->getRechercheByIdUseCase->execute($id, $baseImageUrl, $locale);
            }
        );

        if (!$dto) {
            return $this->json(['message' => 'Recherche non trouvée'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json($dto);
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
