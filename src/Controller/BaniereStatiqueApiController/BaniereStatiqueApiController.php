<?php
namespace App\Controller\BaniereStatiqueApiController;

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
        $locale = $request->getLocale();
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/slider'; 
        $dtos = $this->getAllUseCase->execute($locale, $baseImageUrl);

        return $this->json($dtos);
    }

    #[Route('/{id}', name: 'api_baniere_statique_get_one', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOne(int $id, Request $request): JsonResponse
    {
        $locale = $request->getLocale(); 
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/slider';
        $dto = $this->getByIdUseCase->execute($id, $locale, $baseImageUrl);
        if (!$dto) {
            return $this->json(['message' => 'Bannière statique non trouvée'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json($dto);
    }
}