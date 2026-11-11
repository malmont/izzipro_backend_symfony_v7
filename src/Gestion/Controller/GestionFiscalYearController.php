<?php

namespace App\Gestion\Controller;

use App\Gestion\Services\GestionValidationException;
use App\Gestion\UseCase\GestionFiscalYearUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Exercice du site (lot AN, partie EX, 11/11/2026, `docs/annee-gestion.md` § 1), `ROLE_ADMIN` (`access_control` `/api/gestion`). Écran : `exercice`. */
#[Route('/api/gestion/reglages/exercice')]
class GestionFiscalYearController extends AbstractController
{
    public function __construct(private readonly GestionFiscalYearUseCase $useCase)
    {
    }

    #[Route('', name: 'gestion_reglages_exercice', methods: ['GET'])]
    public function settings(): JsonResponse
    {
        return $this->json($this->useCase->settings());
    }

    #[Route('', name: 'gestion_reglages_exercice_put', methods: ['PUT'])]
    public function put(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            throw new GestionValidationException(['body' => 'INVALID_JSON']);
        }

        return $this->json($this->useCase->put($body));
    }
}
