<?php

namespace App\Controller\FraisDePortController;

use App\Entity\Commande;
use App\Dto\FraisDePortInputDTO;
use App\UseCase\FraisDePortUseCase\GetFraisDePortByCommandeUseCase;
use App\UseCase\FraisDePortUseCase\CreateFraisDePortUseCase;
use App\UseCase\FraisDePortUseCase\DeleteFraisDePortUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

class FraisDePortController extends AbstractController
{
    private GetFraisDePortByCommandeUseCase $getFraisDePortByCommandeUseCase;
    private CreateFraisDePortUseCase $createFraisDePortUseCase;
    private DeleteFraisDePortUseCase $deleteFraisDePortUseCase;

    public function __construct(
        GetFraisDePortByCommandeUseCase $getFraisDePortByCommandeUseCase,
        CreateFraisDePortUseCase $createFraisDePortUseCase,
        DeleteFraisDePortUseCase $deleteFraisDePortUseCase
    ) {
        $this->getFraisDePortByCommandeUseCase = $getFraisDePortByCommandeUseCase;
        $this->createFraisDePortUseCase = $createFraisDePortUseCase;
        $this->deleteFraisDePortUseCase = $deleteFraisDePortUseCase;
    }

    #[Route('/api/commandes/{id}/frais-de-port', name: 'create_frais_de_port', methods: ['POST'])]
    public function createFraisDePort(Commande $commande, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $inputDTO = new FraisDePortInputDTO(
            $data['name'] ?? '',
            $data['facture'] ?? '',
            $data['image'] ?? null,
            $data['tracknumber'] ?? '',
            (float)($data['price'] ?? 0),
            (int)($data['transporteur']['id'] ?? 0)
        );

        $this->createFraisDePortUseCase->execute($commande, $inputDTO);

        return $this->json(['success' => 'Frais de port created'], JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/commandes/{id}/frais-de-port', name: 'get_frais_de_port', methods: ['GET'])]
    public function getFraisDePort(Commande $commande): JsonResponse
    {
        $fraisDePort = $this->getFraisDePortByCommandeUseCase->execute($commande);

        if (!$fraisDePort) {
            return new JsonResponse(['error' => 'No shipping cost associated with this order'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json($fraisDePort, JsonResponse::HTTP_OK);
    }

    #[Route('/api/commandes/{id}/frais-de-port', name: 'delete_frais_de_port', methods: ['DELETE'])]
    public function deleteFraisDePort(Commande $commande): JsonResponse
    {
        $this->deleteFraisDePortUseCase->execute($commande);
        return $this->json(['success' => 'Frais de port deleted'], JsonResponse::HTTP_NO_CONTENT);
    }
}
