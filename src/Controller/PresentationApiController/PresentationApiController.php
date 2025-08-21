<?php
namespace App\Controller\PresentationApiController;

use App\Dto\PresentationOutputDto;
use App\UseCase\PresentationUseCase\GetAllPresentationsUseCase;
use App\UseCase\PresentationUseCase\GetPresentationByIdUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/presentations')]
class PresentationApiController extends AbstractController
{
    public function __construct(
        private GetAllPresentationsUseCase $getAllUseCase,
        private GetPresentationByIdUseCase $getByIdUseCase
    ) {}

    #[Route('', name: 'api_presentation_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/slider'; 
        $entities = $this->getAllUseCase->execute();
        $dtos = array_map(fn($entity) => new PresentationOutputDto($entity, $baseImageUrl), $entities);
        return $this->json($dtos);
    }

    #[Route('/{id}', name: 'api_presentation_get_one', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOne(int $id, Request $request): JsonResponse
    {
        $entity = $this->getByIdUseCase->execute($id);
        if (!$entity) {
            return $this->json(['message' => 'Présentation non trouvée'], Response::HTTP_NOT_FOUND);
        }
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/slider'; // Adaptez le chemin si nécessaire
        return $this->json(new PresentationOutputDto($entity, $baseImageUrl));
    }
}