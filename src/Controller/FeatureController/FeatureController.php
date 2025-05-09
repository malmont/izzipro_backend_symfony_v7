<?php
namespace App\Controller\FeatureController;


use App\UseCase\FeatureUseCase\GetFeaturesUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;           // ← on importe Request
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/features', name: 'api_features', methods: ['GET'])]
class FeatureController extends AbstractController
{
    public function __invoke(
        GetFeaturesUseCase $useCase,
        Request $request                              
    ): JsonResponse {
        $host = $request->getSchemeAndHttpHost();
        $dtos = $useCase->execute($host);

        $data = array_map(fn($dto) => [
            'id'      => $dto->id,
            'title'   => $dto->title,
            'iconUrl' => $dto->iconUrl,
        ], $dtos);

        return $this->json($data);
    }
}