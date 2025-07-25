<?php
namespace App\Controller\EmploiApiController;

use App\Dto\EmploiInputDto;
use App\Dto\EmploiOutputDto;
use App\Services\TenantCacheService;
use App\UseCase\EmploiUseCase\GetAllEmploisUseCase;
use App\UseCase\EmploiUseCase\CreateEmploiUseCase;
use App\UseCase\EmploiUseCase\UpdateEmploiUseCase;
use App\UseCase\EmploiUseCase\DeleteEmploiUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/emplois')]
class EmploiApiController extends AbstractController
{
    public function __construct(
        private GetAllEmploisUseCase $getAllEmploisUseCase,
        private CreateEmploiUseCase $createEmploiUseCase,
        private UpdateEmploiUseCase $updateEmploiUseCase,
        private DeleteEmploiUseCase $deleteEmploiUseCase,
        private TenantCacheService $cache
    ) {}

    #[Route('', name: 'api_emploi_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $cacheKey = 'emplois_all';
        $cacheTags = ['emplois'];

        $emploisDto = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) {
                $emplois = $this->getAllEmploisUseCase->execute();
                return array_map(fn($emploi) => new EmploiOutputDto($emploi), $emplois);
            },
            3600,
            $cacheTags
        );

        return $this->json($emploisDto);
    }

    #[Route('', name: 'api_emploi_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] EmploiInputDto $dto): JsonResponse
    {
        $emploi = $this->createEmploiUseCase->execute($dto);
        return $this->json(new EmploiOutputDto($emploi), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_emploi_update', methods: ['PUT'])]
    public function update(int $id, #[MapRequestPayload] EmploiInputDto $dto): JsonResponse
    {
        $emploi = $this->updateEmploiUseCase->execute($id, $dto);
        return $this->json(new EmploiOutputDto($emploi));
    }

    #[Route('/{id}', name: 'api_emploi_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->deleteEmploiUseCase->execute($id);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
