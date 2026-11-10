<?php

namespace App\Controller\GestionController;

use App\Services\GestionService\GestionValidationException;
use App\UseCase\GestionUseCase\GestionPromotionUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Promotions datées (lot P5, 11/11/2026, `docs/promotions-gestion.md`), `ROLE_ADMIN` (`access_control` `/api/gestion`) : aperçu sans écriture,
 * application (prix promotionnel de l'article, même chemin qu'une modification d'article), terminer maintenant, articles en promotion, journal.
 */
#[Route('/api/gestion/promotions')]
class GestionPromotionController extends AbstractController
{
    public function __construct(private readonly GestionPromotionUseCase $useCase)
    {
    }

    #[Route('', name: 'gestion_promotions', methods: ['GET'])]
    public function current(Request $request): JsonResponse
    {
        return $this->json($this->useCase->current($request->query->get('status')));
    }

    #[Route('/apercu', name: 'gestion_promotions_apercu', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        return $this->json($this->useCase->preview($this->body($request)));
    }

    #[Route('/appliquer', name: 'gestion_promotions_appliquer', methods: ['POST'])]
    public function apply(Request $request): JsonResponse
    {
        return $this->json($this->useCase->apply($this->body($request), $this->getUser()?->getUserIdentifier()), Response::HTTP_CREATED);
    }

    #[Route('/terminer', name: 'gestion_promotions_terminer', methods: ['POST'])]
    public function end(Request $request): JsonResponse
    {
        return $this->json($this->useCase->end($this->body($request), $this->getUser()?->getUserIdentifier()));
    }

    #[Route('/lots', name: 'gestion_promotions_lots', methods: ['GET'])]
    public function batches(Request $request): JsonResponse
    {
        return $this->json($this->useCase->batches((int) $request->query->get('limit', 20)));
    }

    #[Route('/lots/{id}', name: 'gestion_promotions_lot', methods: ['GET'], requirements: ['id' => '\d+'])]
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
