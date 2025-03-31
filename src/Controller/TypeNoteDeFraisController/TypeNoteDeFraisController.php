<?php

namespace App\Controller\TypeNoteDeFraisController;

use App\UseCase\TypeNoteDeFraisUseCase\GetListTypeNoteDeFraisUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class TypeNoteDeFraisController extends AbstractController
{
    private GetListTypeNoteDeFraisUseCase $useCase;

    public function __construct(GetListTypeNoteDeFraisUseCase $useCase)
    {
        $this->useCase = $useCase;
    }

    #[Route('/api/type-note-de-frais', name: 'api_type_note_de_frais', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $dtoList = $this->useCase->execute();

        // Transformation des DTO en tableau
        $data = array_map(function ($dto) {
            return [
                'id'    => $dto->id,
                'name'  => $dto->name,
                'image' => $dto->image,
            ];
        }, $dtoList);

        return $this->json($data);
    }
}
