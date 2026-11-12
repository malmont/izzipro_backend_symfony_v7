<?php

namespace App\Gestion\Controller;

use App\Gestion\UseCase\GestionDayUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Ma journée » (lot JOUR, 12/11/2026, `docs/journee-gestion.md`), `ROLE_ADMIN` (`access_control` `/api/gestion`), module de gestion :
 * ventes du jour par canal (règles des statistiques unifiées), comparaison au même jour de semaine, encaissé, commandes à expédier,
 * assistance, trésorerie, stock bas. Lecture seule, aucun calcul côté application.
 */
class GestionDayController extends AbstractController
{
    public function __construct(private readonly GestionDayUseCase $useCase)
    {
    }

    #[Route('/api/gestion/journee', name: 'gestion_journee', methods: ['GET'])]
    public function overview(): JsonResponse
    {
        return $this->json($this->useCase->overview());
    }
}
