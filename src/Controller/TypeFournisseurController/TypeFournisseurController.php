<?php

namespace App\Controller\TypeFournisseurController;

use App\UseCase\TypeFournisseurUseCase\GetListTypeFournisseurUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class TypeFournisseurController extends AbstractController
{
    private GetListTypeFournisseurUseCase $useCase;

    public function __construct(GetListTypeFournisseurUseCase $useCase)
    {
        $this->useCase = $useCase;
    }

    #[Route('/api/type-fournisseurs', name: 'api_type_fournisseurs', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $dtoList = $this->useCase->execute();

        // Convertir les DTO en tableau
        $data = array_map(function ($dto) {
            return [
                'id'    => $dto->id,
                'name'  => $dto->name,
                'photo' => $dto->photo,
            ];
        }, $dtoList);

        return $this->json($data);
    }
}
