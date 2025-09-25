<?php
namespace App\Controller\EmbedApiController;

use App\Dto\EmbedOutputDto;
use App\UseCase\EmbedUseCase\GetAllEmbedsUseCase;
use App\UseCase\EmbedUseCase\GetEmbedByIdUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/embeds')]
class EmbedApiController extends AbstractController
{
    public function __construct(
        private GetAllEmbedsUseCase $getAllUseCase,
        private GetEmbedByIdUseCase $getByIdUseCase
    ) {}

    #[Route('', name: 'api_embed_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $embeds = $this->getAllUseCase->execute();
        $dtos = array_map(fn($embed) => new EmbedOutputDto($embed), $embeds);
        return $this->json($dtos);
    }

    #[Route('/{id}', name: 'api_embed_get_one', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOne(int $id): JsonResponse
    {
        $embed = $this->getByIdUseCase->execute($id);

        if (!$embed) {
            return $this->json(['message' => 'Embed non trouvé'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(new EmbedOutputDto($embed));
    }    
}