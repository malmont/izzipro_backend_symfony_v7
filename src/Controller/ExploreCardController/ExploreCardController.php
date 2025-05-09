<?php
namespace App\Controller\ExploreCardController;

use App\UseCase\ExploreCardUseCase\GetExploreCardUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/explore-cards', name: 'api_explore_cards', methods: ['GET'])]
class ExploreCardController extends AbstractController
{
    public function __invoke(
        GetExploreCardUseCase $useCase,
        Request $request
    ): JsonResponse {
        $host = $request->getSchemeAndHttpHost();

        /** @var \App\Dto\ExploreCardDto[] $dtos */
        $dtos = $useCase->execute($host);

        $data = array_map(fn($dto) => [
            'id'             => $dto->id,
            'isDifferent'    => $dto->isDifferent,
            'standardTitle'  => $dto->standardTitle,
            'differentTitle' => $dto->differentTitle,
            'description'    => $dto->description,
            'link'           => $dto->link,
            'imageUrl'       => $dto->imageUrl,
            'videoUrl'       => $dto->videoUrl,
        ], $dtos);

        return $this->json($data);
    }
}
