<?php
namespace App\Controller\MultilienApiController;

use App\Dto\MultilienInputDto;
use App\Dto\MultilienOutputDto;
use App\Services\TenantCacheService;
use App\UseCase\MultilienUseCase\GetAllMultiliensUseCase;
use App\UseCase\MultilienUseCase\CreateMultilienUseCase;
use App\UseCase\MultilienUseCase\UpdateMultilienUseCase;
use App\UseCase\MultilienUseCase\DeleteMultilienUseCase;
use App\UseCase\MultilienUseCase\GetMultilienByIdUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/multiliens')]
class MultilienApiController extends AbstractController
{
    public function __construct(
        private GetAllMultiliensUseCase $getAllMultiliensUseCase,
        private CreateMultilienUseCase $createMultilienUseCase,
        private UpdateMultilienUseCase $updateMultilienUseCase,
        private DeleteMultilienUseCase $deleteMultilienUseCase,
        private TenantCacheService $cache,
        private GetMultilienByIdUseCase $getMultilienByIdUseCase
    ) {}

    #[Route('', name: 'api_multilien_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $cacheKey = 'multiliens_all';
        $cacheTags = ['multiliens'];
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/uploads/multiliens';

        $multiliensDto = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($baseImageUrl) {
                $multiliens = $this->getAllMultiliensUseCase->execute();
                return array_map(fn($multilien) => new MultilienOutputDto($multilien, $baseImageUrl), $multiliens);
            },
            3600,
            $cacheTags
        );

        return $this->json($multiliensDto);
    }

    #[Route('/{id}', name: 'api_multilien_get_one', methods: ['GET'])]
    public function getOne(int $id, Request $request): JsonResponse
    {
        $multilien = $this->getMultilienByIdUseCase->execute($id);

        if (!$multilien) {
            return $this->json(['message' => 'Multilien non trouvé'], Response::HTTP_NOT_FOUND);
        }
        
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/uploads/multiliens';
        return $this->json(new MultilienOutputDto($multilien, $baseImageUrl));
    }

    #[Route('', name: 'api_multilien_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] MultilienInputDto $dto, Request $request): JsonResponse
    {
        $multilien = $this->createMultilienUseCase->execute($dto);
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/uploads/multiliens';
        return $this->json(new MultilienOutputDto($multilien, $baseImageUrl), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_multilien_update', methods: ['PUT'])]
    public function update(int $id, #[MapRequestPayload] MultilienInputDto $dto, Request $request): JsonResponse
    {
        $multilien = $this->updateMultilienUseCase->execute($id, $dto);
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/uploads/multiliens';
        return $this->json(new MultilienOutputDto($multilien));
    }

    #[Route('/{id}', name: 'api_multilien_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->deleteMultilienUseCase->execute($id);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
