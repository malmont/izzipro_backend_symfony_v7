<?php

namespace App\Gestion\Controller;

use App\Gestion\UseCase\GestionAiUsageUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Unités IA de l'application de gestion (lot MOD, 11/11/2026, `docs/gestion-mise-a-niveau.md` § MOD), `ROLE_ADMIN` (`access_control`
 * `/api/gestion`), gardée par le module : palier IA du site, crédits du mois, coût par appel, plafonds et compteurs du jour.
 */
class GestionAiController extends AbstractController
{
    public function __construct(private readonly GestionAiUsageUseCase $useCase)
    {
    }

    #[Route('/api/gestion/ia', name: 'gestion_ia', methods: ['GET'])]
    public function usage(): JsonResponse
    {
        return $this->json($this->useCase->execute($this->isGranted('ROLE_SUPER_ADMIN')));
    }
}
