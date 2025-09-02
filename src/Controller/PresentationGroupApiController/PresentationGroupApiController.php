<?php
namespace App\Controller\PresentationGroupApiController;

use App\Dto\PresentationGroupOutputDto;
use App\UseCase\PresentationGroupUseCase\GetAllPresentationGroupsUseCase;
use App\UseCase\PresentationGroupUseCase\GetPresentationGroupByIdUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/presentation-groups')]
class PresentationGroupApiController extends AbstractController
{
    public function __construct(
        private GetAllPresentationGroupsUseCase $getAllUseCase,
        private GetPresentationGroupByIdUseCase $getByIdUseCase
    ) {}

    #[Route('', name: 'api_presentation_group_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/slider';
        $entities = $this->getAllUseCase->execute();
        $dtos = array_map(fn($entity) => new PresentationGroupOutputDto($entity, $baseImageUrl), $entities);
        return $this->json($dtos);
    }

    #[Route('/{id}', name: 'api_presentation_group_get_one', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOne(int $id, Request $request): JsonResponse
    {
        $entity = $this->getByIdUseCase->execute($id);
        if (!$entity) {
            return $this->json(['message' => 'Groupe de présentations non trouvé'], Response::HTTP_NOT_FOUND);
        }
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/slider';
        return $this->json(new PresentationGroupOutputDto($entity, $baseImageUrl));
    }
}
