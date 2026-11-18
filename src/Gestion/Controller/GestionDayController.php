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
    public function __construct(private readonly GestionDayUseCase $useCase, private readonly ?\App\Services\Group\AllShops\AllShopsReader $allShops = null)
    {
    }

    #[Route('/api/gestion/journee', name: 'gestion_journee', methods: ['GET'])]
    public function overview(\Symfony\Component\HttpFoundation\Request $request): JsonResponse
    {
        // lot G-2 : « Toutes les boutiques » (en-tête X-Group-Scope) : chaque boutique à son jour, puis les totaux par devise
        $group = $this->allShops?->forRequest($request, 'gestion_journee', [], fn (): array => ['data' => $this->useCase->overview()],
            \App\Services\Group\AllShops\AllShopsMerger::day(...));
        if ($group !== null) {
            return $this->json($group);
        }

        return $this->json($this->useCase->overview());
    }
}
