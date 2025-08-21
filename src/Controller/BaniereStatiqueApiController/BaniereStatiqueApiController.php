<?php
namespace App\Controller\BaniereStatiqueApiController;

use App\Dto\BaniereStatiqueOutputDto;
use App\UseCase\BaniereStatiqueUseCase\GetAllBaniereStatiquesUseCase;
use App\UseCase\BaniereStatiqueUseCase\GetBaniereStatiqueByIdUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/baniere-statiques')]
class BaniereStatiqueApiController extends AbstractController
{
    public function __construct(
        private GetAllBaniereStatiquesUseCase $getAllUseCase,
        private GetBaniereStatiqueByIdUseCase $getByIdUseCase
    ) {}

    #[Route('', name: 'api_baniere_statique_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/slider';
        $entities = $this->getAllUseCase->execute();
        $dtos = array_map(fn($entity) => new BaniereStatiqueOutputDto($entity, $baseImageUrl), $entities);
        return $this->json($dtos);
    }

    #[Route('/{id}', name: 'api_baniere_statique_get_one', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOne(int $id, Request $request): JsonResponse
    {
        $entity = $this->getByIdUseCase->execute($id);
        if (!$entity) {
            return $this->json(['message' => 'Bannière statique non trouvée'], Response::HTTP_NOT_FOUND);
        }
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/slider';
        return $this->json(new BaniereStatiqueOutputDto($entity, $baseImageUrl));
    }
}