<?php

namespace App\Gestion\Controller;

use App\Gestion\Services\GestionValidationException;
use App\Gestion\UseCase\GestionPriceUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Appliquer un prix (lot P4, 11/11/2026, `docs/prix-gestion.md`), `ROLE_ADMIN` (`access_control` `/api/gestion`) : aperçu sans écriture, application
 * (même chemin qu'une modification d'article), annulation dans les 24 h, journal des lots.
 */
#[Route('/api/gestion/prix')]
class GestionPriceController extends AbstractController
{
    public function __construct(private readonly GestionPriceUseCase $useCase)
    {
    }

    #[Route('/apercu', name: 'gestion_prix_apercu', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        return $this->json($this->useCase->preview($this->body($request)));
    }

    #[Route('/appliquer', name: 'gestion_prix_appliquer', methods: ['POST'])]
    public function apply(Request $request): JsonResponse
    {
        return $this->json($this->useCase->apply($this->body($request), $this->getUser()?->getUserIdentifier()), Response::HTTP_CREATED);
    }

    #[Route('/annuler', name: 'gestion_prix_annuler', methods: ['POST'])]
    public function undo(Request $request): JsonResponse
    {
        return $this->json($this->useCase->undo($this->body($request), $this->getUser()?->getUserIdentifier()));
    }

    #[Route('/lots', name: 'gestion_prix_lots', methods: ['GET'])]
    public function batches(Request $request): JsonResponse
    {
        return $this->json($this->useCase->batches((int) $request->query->get('limit', 20)));
    }

    #[Route('/lots/{id}', name: 'gestion_prix_lot', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function batch(int $id): JsonResponse
    {
        return $this->json($this->useCase->batch($id));
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
