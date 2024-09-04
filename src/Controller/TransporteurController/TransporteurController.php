<?php

// src/Controller/TransporteurController.php

namespace App\Controller\TransporteurController;

use App\Dto\TransporteurDTO;
use App\UseCase\TransporteurUseCase\GetTransporteursUseCase;
use App\UseCase\TransporteurUseCase\CreateTransporteurUseCase;
use App\UseCase\TransporteurUseCase\DeleteTransporteurUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class TransporteurController extends AbstractController
{
    private GetTransporteursUseCase $getTransporteursUseCase;
    private CreateTransporteurUseCase $createTransporteurUseCase;
    private DeleteTransporteurUseCase $deleteTransporteurUseCase;

    public function __construct(
        GetTransporteursUseCase $getTransporteursUseCase,
        CreateTransporteurUseCase $createTransporteurUseCase,
        DeleteTransporteurUseCase $deleteTransporteurUseCase
    ) {
        $this->getTransporteursUseCase = $getTransporteursUseCase;
        $this->createTransporteurUseCase = $createTransporteurUseCase;
        $this->deleteTransporteurUseCase = $deleteTransporteurUseCase;
    }

    #[Route('/api/transporteurs', name: 'get_transporteurs', methods: ['GET'])]
    public function getTransporteurs(): JsonResponse
    {
        $transporteursData = $this->getTransporteursUseCase->execute();
        return $this->json($transporteursData, JsonResponse::HTTP_OK);
    }

    #[Route('/api/transporteurs', name: 'create_transporteur', methods: ['POST'])]
    public function createTransporteur(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $transporteurDTO = TransporteurDTO::fromArray($data);

        $transporteur = $this->createTransporteurUseCase->execute($transporteurDTO);

        return $this->json([
            'success' => 'Transporteur created',
            'transporteur_id' => $transporteur->getId(),
        ], JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/transporteurs/{id}', name: 'delete_transporteur', methods: ['DELETE'])]
    public function deleteTransporteur(Transporteur $transporteur): JsonResponse
    {
        $this->deleteTransporteurUseCase->execute($transporteur);
        return new JsonResponse(['success' => 'Transporteur deleted'], JsonResponse::HTTP_NO_CONTENT);
    }
}
