<?php
namespace App\Controller\MarqueApiController;

use App\Dto\MarqueInputDto;
use App\Dto\MarqueOutputDto;
use App\Services\TenantCacheService;
use App\UseCase\MarqueUseCase\GetAllMarquesUseCase;
use App\UseCase\MarqueUseCase\CreateMarqueUseCase;
use App\UseCase\MarqueUseCase\UpdateMarqueUseCase;
use App\UseCase\MarqueUseCase\DeleteMarqueUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/marques')]
class MarqueApiController extends AbstractController
{
    public function __construct(
        private GetAllMarquesUseCase $getAllMarquesUseCase,
        private CreateMarqueUseCase $createMarqueUseCase,
        private UpdateMarqueUseCase $updateMarqueUseCase,
        private DeleteMarqueUseCase $deleteMarqueUseCase,
        private TenantCacheService $cache
    ) {}

    #[Route('', name: 'api_marque_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $cacheKey = 'marques_all';
        $cacheTags = ['marques'];
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos';

        $marquesDto = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($baseImageUrl) {
                $marques = $this->getAllMarquesUseCase->execute();
                return array_map(fn($marque) => new MarqueOutputDto($marque, $baseImageUrl), $marques);
            },
            3600,
            $cacheTags
        );

        return $this->json($marquesDto);
    }

    #[Route('', name: 'api_marque_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] MarqueInputDto $dto, Request $request): JsonResponse
    {
        $marque = $this->createMarqueUseCase->execute($dto);
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos';
        return $this->json(new MarqueOutputDto($marque, $baseImageUrl), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_marque_update', methods: ['PUT'])]
    public function update(int $id, #[MapRequestPayload] MarqueInputDto $dto, Request $request): JsonResponse
    {
        $marque = $this->updateMarqueUseCase->execute($id, $dto);
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos';
        return $this->json(new MarqueOutputDto($marque));
    }

    #[Route('/{id}', name: 'api_marque_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->deleteMarqueUseCase->execute($id);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}