<?php
namespace App\Controller\CandidatureApiController;

use App\Dto\CandidatureInputDto;
use App\Dto\CandidatureOutputDto;
use App\Services\TenantCacheService;
use App\UseCase\CandidatureUseCase\GetAllCandidaturesUseCase;
use App\UseCase\CandidatureUseCase\CreateCandidatureUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\UseCase\CandidatureUseCase\GetCandidatureByIdUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/candidatures')]
class CandidatureApiController extends AbstractController
{
    public function __construct(
        private GetAllCandidaturesUseCase $getAllCandidaturesUseCase,
        private CreateCandidatureUseCase $createCandidatureUseCase,
        private TenantCacheService $cache,
        private GetCandidatureByIdUseCase $getCandidatureByIdUseCase
    ) {}

    #[Route('', name: 'api_candidature_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $cacheKey = 'candidatures_all';
        $cacheTags = ['candidatures'];

        $candidaturesDto = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) {
                $candidatures = $this->getAllCandidaturesUseCase->execute();
                return array_map(fn($candidature) => new CandidatureOutputDto($candidature), $candidatures);
            },
            3600,
            $cacheTags
        );

        return $this->json($candidaturesDto);
    }
     #[Route('/{id}', name: 'api_candidature_get_one', methods: ['GET'])]
    public function getOne(int $id): JsonResponse
    {
        $candidature = $this->getCandidatureByIdUseCase->execute($id);

        if (!$candidature) {
            return $this->json(['message' => 'Candidature non trouvée'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json(new CandidatureOutputDto($candidature));
    }

    #[Route('', name: 'api_candidature_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CandidatureInputDto $dto): JsonResponse
    {
        $candidature = $this->createCandidatureUseCase->execute($dto);
        return $this->json(new CandidatureOutputDto($candidature), Response::HTTP_CREATED);
    }
}