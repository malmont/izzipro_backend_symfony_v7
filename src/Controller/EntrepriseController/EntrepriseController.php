<?php

namespace App\Controller\EntrepriseController;

use App\UseCase\EntrepriseUsecase\CreateEntrepriseUseCase;
use App\UseCase\EntrepriseUsecase\GetEntrepriseUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class EntrepriseController extends AbstractController
{
    private CreateEntrepriseUseCase $createEntrepriseUseCase;
    private GetEntrepriseUseCase $getEntrepriseUseCase;
    
    public function __construct(
         CreateEntrepriseUseCase $createEntrepriseUseCase,
         GetEntrepriseUseCase $getEntrepriseUseCase
    ) {
        $this->createEntrepriseUseCase = $createEntrepriseUseCase;
        $this->getEntrepriseUseCase = $getEntrepriseUseCase;

    }

    #[Route('/api/entreprise', name: 'api_entreprise_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Vous pouvez ajouter ici des validations sur les données

        $entrepriseDto = $this->createEntrepriseUseCase->execute($data);

        return $this->json($entrepriseDto);
    }

    #[Route('/api/entreprise/{id}', name: 'api_entreprise_get', methods: ['GET'])]
    public function getEntreprise(int $id,Request $request ): JsonResponse
    {
        $host = $request->getSchemeAndHttpHost();
        $entrepriseDto = $this->getEntrepriseUseCase->execute($id, $host);

        if (!$entrepriseDto) {
            return $this->json(['message' => 'Entreprise not found'], 404);
        }

        return $this->json($entrepriseDto);
    }
}
