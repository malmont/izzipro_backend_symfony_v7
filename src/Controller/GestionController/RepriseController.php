<?php

namespace App\Controller\GestionController;

use App\UseCase\GestionUseCase\RepriseHistoriqueUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reprise d'historique de l'application de gestion (lot P3, 10/11/2026, `docs/periodes-gestion.md` § 4), `ROLE_ADMIN`
 * (`access_control` `/api/gestion`) : simulation (rien n'est écrit), application (201), annulation, état.
 * 422 `VALIDATION_FAILED`, 409 `REPRISE_ACTIVE`, 404 `REPRISE_NOT_FOUND` (`GestionValidationExceptionListener`).
 */
class RepriseController extends AbstractController
{
    public function __construct(private readonly RepriseHistoriqueUseCase $useCase)
    {
    }

    #[Route('/api/gestion/reprise/simulation', name: 'gestion_reprise_simulation', methods: ['POST'])]
    public function simulate(Request $request): JsonResponse
    {
        $data = $this->body($request);

        return $data === null ? $this->invalidJson() : $this->json($this->useCase->simulate($data));
    }

    #[Route('/api/gestion/reprise/appliquer', name: 'gestion_reprise_appliquer', methods: ['POST'])]
    public function apply(Request $request): JsonResponse
    {
        $data = $this->body($request);

        return $data === null ? $this->invalidJson() : $this->json($this->useCase->apply($data), JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/gestion/reprise/annuler', name: 'gestion_reprise_annuler', methods: ['POST'])]
    public function cancel(): JsonResponse
    {
        return $this->json($this->useCase->cancel());
    }

    #[Route('/api/gestion/reprise', name: 'gestion_reprise_etat', methods: ['GET'])]
    public function state(): JsonResponse
    {
        return $this->json($this->useCase->state());
    }

    /** Corps JSON (objet) ; corps vide = {} ; null si invalide */
    private function body(Request $request): ?array
    {
        $content = trim((string) $request->getContent());
        if ($content === '') {
            return [];
        }
        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    private function invalidJson(): JsonResponse
    {
        return $this->json(['error' => 'INVALID_JSON', 'code' => 'INVALID_JSON', 'message' => 'Corps JSON invalide'], JsonResponse::HTTP_BAD_REQUEST);
    }
}
