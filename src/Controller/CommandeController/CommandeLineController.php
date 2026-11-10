<?php

namespace App\Controller\CommandeController;

use App\Entity\Commande;
use App\UseCase\CommandeUseCase\CommandeLineUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lignes d'achat d'une commande fournisseur (lot L0 de la gestion, 10/11/2026, `docs/lignes-achat-gestion.md`), `ROLE_ADMIN`
 * (`access_control` `/api/commandes`) : `GET /api/commandes/{id}/lignes`, `PATCH` et `DELETE /api/commandes/{id}/lignes/{lineId}`.
 */
class CommandeLineController extends AbstractController
{
    public function __construct(private readonly CommandeLineUseCase $useCase)
    {
    }

    #[Route('/api/commandes/{id}/lignes', name: 'commande_lines_list', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function list(Commande $commande): JsonResponse
    {
        return $this->json($this->useCase->list($commande));
    }

    #[Route('/api/commandes/{id}/lignes/{lineId}', name: 'commande_lines_update', methods: ['PATCH'], requirements: ['id' => '\d+', 'lineId' => '\d+'])]
    public function update(Commande $commande, int $lineId, Request $request): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'INVALID_JSON', 'code' => 'INVALID_JSON', 'message' => 'Corps JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->useCase->update($commande, $lineId, $data));
    }

    #[Route('/api/commandes/{id}/lignes/{lineId}', name: 'commande_lines_delete', methods: ['DELETE'], requirements: ['id' => '\d+', 'lineId' => '\d+'])]
    public function delete(Commande $commande, int $lineId): Response
    {
        $this->useCase->delete($commande, $lineId);

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
