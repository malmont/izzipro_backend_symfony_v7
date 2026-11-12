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
use App\Cache\CacheTags;
use App\Services\TenantCacheService;

class TransporteurController extends AbstractController
{
    private GetTransporteursUseCase $getTransporteursUseCase;
    private CreateTransporteurUseCase $createTransporteurUseCase;
    private DeleteTransporteurUseCase $deleteTransporteurUseCase;
    private TenantCacheService $cache;

    public function __construct(
        GetTransporteursUseCase $getTransporteursUseCase,
        CreateTransporteurUseCase $createTransporteurUseCase,
        DeleteTransporteurUseCase $deleteTransporteurUseCase,
        TenantCacheService $cache,
        private readonly ?\App\UseCase\TransporteurUseCase\UpdateTransporteurUseCase $updateTransporteurUseCase = null, // lot EDIT (12/11/2026)
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

        $transporteursData = $this->cache->get(
            $cacheKey,
            fn () => $this->getTransporteursUseCase->execute(),
            3600,
            CacheTags::listTags(CacheTags::TRANSPORTEURS)
        );

        return new JsonResponse($transporteursData, JsonResponse::HTTP_OK);
    }

    #[Route('/api/transporteurs', name: 'create_transporteur', methods: ['POST'])]
    public function createTransporteur(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $transporteurDTO = TransporteurDTO::fromArray($data);
        $transporteur = $this->createTransporteurUseCase->execute($transporteurDTO);

        // 201 avec l'objet créé (lot G4, 09/11/2026 ; avant : {"success", "transporteur_id"})
        return $this->json(['id' => $transporteur->getId(), 'name' => $transporteur->getName(), 'logo' => $transporteur->getLogo(), 'contact' => $transporteur->getContact()], JsonResponse::HTTP_CREATED);
    }

    /** Modification partielle (lot EDIT, 12/11/2026) : 200 avec la forme de la création */
    #[Route('/api/transporteurs/{id}', name: 'patch_transporteur', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function patchTransporteur(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'INVALID_JSON', 'code' => 'INVALID_JSON', 'message' => 'Corps JSON invalide'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $transporteur = $this->updateTransporteurUseCase->execute($id, $data);

        return $this->json(['id' => $transporteur->getId(), 'name' => $transporteur->getName(), 'logo' => $transporteur->getLogo(), 'contact' => $transporteur->getContact()]);
    }

    #[Route('/api/transporteurs/{id}', name: 'delete_transporteur', methods: ['DELETE'])]
    public function deleteTransporteur(\App\Entity\Transporteur $transporteur): \Symfony\Component\HttpFoundation\Response
    {
        $this->deleteTransporteurUseCase->execute($transporteur);

        return new \Symfony\Component\HttpFoundation\Response('', \Symfony\Component\HttpFoundation\Response::HTTP_NO_CONTENT); // 204 sans corps (lot G4)
    }
}
