<?php

namespace App\Controller\TransporteurController;

use App\Dto\TransporteurDTO;
use App\UseCase\TransporteurUseCase\GetTransporteursUseCase;
use App\UseCase\TransporteurUseCase\CreateTransporteurUseCase;
use App\UseCase\TransporteurUseCase\DeleteTransporteurUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;


class TransporteurController extends AbstractController
{
    private GetTransporteursUseCase $getTransporteursUseCase;
    private CreateTransporteurUseCase $createTransporteurUseCase;
    private DeleteTransporteurUseCase $deleteTransporteurUseCase;
    private CacheInterface $cache;

    public function __construct(
        GetTransporteursUseCase $getTransporteursUseCase,
        CreateTransporteurUseCase $createTransporteurUseCase,
        DeleteTransporteurUseCase $deleteTransporteurUseCase,
        CacheInterface $cache
    ) {
        $this->getTransporteursUseCase = $getTransporteursUseCase;
        $this->createTransporteurUseCase = $createTransporteurUseCase;
        $this->deleteTransporteurUseCase = $deleteTransporteurUseCase;
        $this->cache = $cache;
    }

    #[Route('/api/transporteurs', name: 'get_transporteurs', methods: ['GET'])]
    public function getTransporteurs(): JsonResponse
    {
        $cacheKey = 'transporteurs_all';

        $transporteursData = $this->cache->get($cacheKey, function (ItemInterface $item) {
            // On définit un TTL, ici 1 heure (3600 secondes)
            $item->expiresAfter(3600);
            return $this->getTransporteursUseCase->execute();
        });

        return new JsonResponse($transporteursData, JsonResponse::HTTP_OK);
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
    public function deleteTransporteur(\App\Entity\Transporteur $transporteur): JsonResponse
    {
        $this->deleteTransporteurUseCase->execute($transporteur);
        return new JsonResponse(['success' => 'Transporteur deleted'], JsonResponse::HTTP_NO_CONTENT);
    }
}
