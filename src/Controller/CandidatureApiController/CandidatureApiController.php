<?php
namespace App\Controller\CandidatureApiController;

use App\Dto\CandidatureInputDto;
use App\Dto\CandidatureOutputDto;
use App\UseCase\CandidatureUseCase\GetAllCandidaturesUseCase;
use App\UseCase\CandidatureUseCase\CreateCandidatureUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\UseCase\CandidatureUseCase\GetCandidatureByIdUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/candidatures')]
class CandidatureApiController extends AbstractController
{
    public function __construct(
        private GetAllCandidaturesUseCase $getAllCandidaturesUseCase,
        private CreateCandidatureUseCase $createCandidatureUseCase,
        private GetCandidatureByIdUseCase $getCandidatureByIdUseCase
    ) {}

    /** Liste d'administration de données personnelles : lue en base, jamais en cache (28/10/2026) */
    #[Route('', name: 'api_candidature_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $candidatures = $this->getAllCandidaturesUseCase->execute();

        return $this->json(array_map(fn($candidature) => new CandidatureOutputDto($candidature), $candidatures));
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