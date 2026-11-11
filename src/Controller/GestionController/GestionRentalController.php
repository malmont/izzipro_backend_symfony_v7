<?php

namespace App\Controller\GestionController;

use App\Services\EmailConfigurationService\EmailLocale;
use App\UseCase\GestionUseCase\GestionRentalUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Locations de la gestion (lot LOC, 11/11/2026, `docs/locations-gestion.md`), `ROLE_ADMIN` (`access_control` `/api/gestion`) : statistiques
 * (LOC1 : revenu à la date de vente et par date de location, occupation par article et par jour de la semaine). Écran : `locations`.
 */
#[Route('/api/gestion/locations')]
class GestionRentalController extends AbstractController
{
    public function __construct(private readonly GestionRentalUseCase $useCase, private readonly ?EmailLocale $siteLocale = null)
    {
    }

    #[Route('/statistiques', name: 'gestion_locations_statistiques', methods: ['GET'])]
    public function statistics(Request $request): JsonResponse
    {
        return $this->json($this->useCase->statistics($request->query->all(), $this->locale($request)));
    }

    private function locale(Request $request): string
    {
        $asked = $request->query->get('locale');
        if (is_string($asked) && in_array(strtolower($asked), ['fr', 'en'], true)) {
            return strtolower($asked);
        }

        return $this->siteLocale?->language(null) ?? 'fr';
    }
}
