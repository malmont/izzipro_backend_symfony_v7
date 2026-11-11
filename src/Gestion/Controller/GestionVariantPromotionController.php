<?php

namespace App\Gestion\Controller;

use App\Gestion\UseCase\GestionVariantPromotionUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Promotions par variante (lot VAR, 11/11/2026, `docs/promotions-gestion.md` § 8), `ROLE_ADMIN` (`access_control` `/api/gestion`) : aperçu,
 * application (empreinte, accords explicites), fin, liste, lots. Écriture refusée (409 VARIANT_PROMOTIONS_OFF) tant que le super admin n'a pas
 * allumé l'interrupteur du site. Écran : `promotions`.
 */
#[Route('/api/gestion/promotions/variantes')]
class GestionVariantPromotionController extends AbstractController
{
    public function __construct(private readonly GestionVariantPromotionUseCase $useCase)
    {
    }

    #[Route('', name: 'gestion_promotions_variantes', methods: ['GET'])]
    public function current(Request $request): JsonResponse
    {
        return $this->json($this->useCase->current((string) $request->query->get('status', 'all')));
    }

    #[Route('/apercu', name: 'gestion_promotions_variantes_apercu', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        return $this->json($this->useCase->preview($this->body($request)));
    }

    #[Route('/appliquer', name: 'gestion_promotions_variantes_appliquer', methods: ['POST'])]
    public function apply(Request $request): JsonResponse
    {
        return $this->json($this->useCase->apply($this->body($request), $this->getUser()?->getUserIdentifier()), 201);
    }

    #[Route('/terminer', name: 'gestion_promotions_variantes_terminer', methods: ['POST'])]
    public function end(Request $request): JsonResponse
    {
        return $this->json($this->useCase->end($this->body($request), $this->getUser()?->getUserIdentifier()));
    }

    #[Route('/lots', name: 'gestion_promotions_variantes_lots', methods: ['GET'])]
    public function batches(Request $request): JsonResponse
    {
        return $this->json($this->useCase->batches(max(1, min(100, (int) $request->query->get('limit', 20)))));
    }

    #[Route('/lots/{id}', name: 'gestion_promotions_variantes_lot', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function batch(int $id): JsonResponse
    {
        return $this->json($this->useCase->batch($id));
    }

    private function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            throw new \App\Gestion\Services\GestionValidationException(['body' => 'INVALID_JSON']);
        }

        return $body;
    }
}
