<?php

namespace App\Gestion\Controller;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\UseCase\MarketingCampaignUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Campagnes de publicité de l'application de gestion (lot A1, 10/11/2026, `docs/publicite-gestion.md`), `ROLE_ADMIN` (`access_control`
 * `/api/gestion`) : liste, création, lecture, modification, suppression, rentabilité perçue (estimation).
 */
#[Route('/api/gestion/campagnes')]
class MarketingCampaignController extends AbstractController
{
    public function __construct(private readonly MarketingCampaignUseCase $useCase)
    {
    }

    #[Route('', name: 'gestion_campagnes_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(100, (int) $request->query->get('limit', 50)));
        [$rows, $total] = $this->useCase->list($request->query->get('from'), $request->query->get('to'), $request->query->get('channel'), $page, $limit);
        $response = $this->json($rows);
        $response->headers->set('X-Total-Count', (string) $total);
        $response->headers->set('X-Page', (string) $page);
        $response->headers->set('X-Limit', (string) $limit);

        return $response;
    }

    #[Route('', name: 'gestion_campagnes_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->json($this->useCase->create($this->body($request), $this->getUser()?->getUserIdentifier()), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'gestion_campagnes_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        return $this->json($this->useCase->get($this->useCase->find($id)));
    }

    #[Route('/{id}', name: 'gestion_campagnes_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->update($this->useCase->find($id), $this->body($request)));
    }

    #[Route('/{id}', name: 'gestion_campagnes_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): Response
    {
        $this->useCase->delete($this->useCase->find($id));

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/rentabilite', name: 'gestion_campagnes_rentabilite', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function profitability(int $id): JsonResponse
    {
        return $this->json($this->useCase->profitability($this->useCase->find($id)));
    }

    private function body(Request $request): array
    {
        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data)) {
            throw new GestionConflictException('INVALID_JSON', 'Corps JSON invalide', 400);
        }

        return $data;
    }
}
