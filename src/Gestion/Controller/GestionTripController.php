<?php

namespace App\Gestion\Controller;

use App\Gestion\Services\GestionValidationException;
use App\Gestion\UseCase\GestionTripUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Voyages d'achat de la gestion (lot B1a, 11/11/2026, `docs/budgets-gestion.md` §§ 2-3), `ROLE_ADMIN` (`access_control` `/api/gestion`) :
 * liste, création, lecture, modification, suppression, suivi jour par jour, rattachement manuel des notes de frais et commandes.
 */
#[Route('/api/gestion/voyages')]
class GestionTripController extends AbstractController
{
    public function __construct(private readonly GestionTripUseCase $useCase)
    {
    }

    #[Route('', name: 'gestion_voyages_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(100, (int) $request->query->get('limit', 50)));
        [$rows, $total] = $this->useCase->list($request->query->get('from'), $request->query->get('to'), $page, $limit);
        $response = $this->json($rows);
        $response->headers->set('X-Total-Count', (string) $total);
        $response->headers->set('X-Page', (string) $page);
        $response->headers->set('X-Limit', (string) $limit);

        return $response;
    }

    #[Route('', name: 'gestion_voyages_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->json($this->useCase->create($this->body($request), $this->getUser()?->getUserIdentifier()), Response::HTTP_CREATED);
    }

    #[Route('/rattacher', name: 'gestion_voyages_rattacher', methods: ['POST'])]
    public function attach(Request $request): JsonResponse
    {
        return $this->json($this->useCase->attach($this->body($request)));
    }

    #[Route('/{id}', name: 'gestion_voyages_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        return $this->json($this->useCase->get($id));
    }

    #[Route('/{id}', name: 'gestion_voyages_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->update($id, $this->body($request)));
    }

    #[Route('/{id}', name: 'gestion_voyages_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): Response
    {
        $this->useCase->delete($id);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/suivi', name: 'gestion_voyages_suivi', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function follow(int $id): JsonResponse
    {
        return $this->json($this->useCase->follow($id));
    }

    private function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            throw new GestionValidationException(['body' => 'INVALID_JSON']);
        }

        return $body;
    }
}
