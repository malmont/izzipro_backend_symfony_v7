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

    /** Lignes dont il reste à recevoir, toutes commandes, triées par date de commande (lot R1) ; en-têtes X-Total-Count, X-Page, X-Limit */
    #[Route('/api/commandes/lignes/a-recevoir', name: 'commande_lines_to_receive', methods: ['GET'], priority: 10)]
    public function toReceive(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(200, (int) $request->query->get('limit', 50)));
        [$rows, $total] = $this->useCase->toReceive($page, $limit);
        $response = $this->json($rows);
        $response->headers->set('X-Total-Count', (string) $total);
        $response->headers->set('X-Page', (string) $page);
        $response->headers->set('X-Limit', (string) $limit);

        return $response;
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

    /** Réception d'une ligne (lot R1, seule écriture de stock de la gestion) → 200 { line, movement } */
    #[Route('/api/commandes/{id}/lignes/{lineId}/reception', name: 'commande_lines_receive', methods: ['POST'], requirements: ['id' => '\d+', 'lineId' => '\d+'])]
    public function receive(Commande $commande, int $lineId, Request $request): JsonResponse
    {
        return $this->json($this->useCase->receive($commande, $lineId, $this->body($request), $this->getUser()?->getUserIdentifier()));
    }

    /** Annulation d'une réception (lot R1) : mouvement inverse, jamais une suppression → 200 { line, movement } */
    #[Route('/api/commandes/{id}/lignes/{lineId}/reception/annuler', name: 'commande_lines_receive_cancel', methods: ['POST'], requirements: ['id' => '\d+', 'lineId' => '\d+'])]
    public function cancelReception(Commande $commande, int $lineId, Request $request): JsonResponse
    {
        return $this->json($this->useCase->cancelReception($commande, $lineId, $this->body($request), $this->getUser()?->getUserIdentifier()));
    }

    /** Réception en un geste (lot R1) : tout ce qui peut l'être, needsVariant à part → 200 { received, needsVariant, lines, commande } */
    #[Route('/api/commandes/{id}/reception', name: 'commande_receive_all', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function receiveAll(Commande $commande, Request $request): JsonResponse
    {
        return $this->json($this->useCase->receiveAll($commande, $this->body($request), $this->getUser()?->getUserIdentifier()));
    }

    /** Corps JSON facultatif (vide = {}) */
    private function body(Request $request): array
    {
        $content = trim((string) $request->getContent());
        $data = $content === '' ? [] : json_decode($content, true);
        if (!is_array($data)) {
            throw new \App\Services\GestionService\GestionConflictException('INVALID_JSON', 'Corps JSON invalide', 400);
        }

        return $data;
    }

    #[Route('/api/commandes/{id}/lignes/{lineId}', name: 'commande_lines_delete', methods: ['DELETE'], requirements: ['id' => '\d+', 'lineId' => '\d+'])]
    public function delete(Commande $commande, int $lineId): Response
    {
        $this->useCase->delete($commande, $lineId);

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
