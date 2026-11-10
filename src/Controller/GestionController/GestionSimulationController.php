<?php

namespace App\Controller\GestionController;

use App\Entity\Collections;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\GestionService\GestionValidationException;
use App\UseCase\GestionUseCase\GestionSimulationUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Simulateur d'une période de gestion (lot B1b-2, 11/11/2026, `docs/simulateur-gestion.md`), `ROLE_ADMIN` (`access_control` `/api/periodes`) :
 * POST pour porter les leviers, **aucune écriture** ; « valider » = PUT `/api/periodes/{id}/budget` avec `plannedToSave`.
 */
class GestionSimulationController extends AbstractController
{
    public function __construct(private readonly GestionSimulationUseCase $useCase, private readonly ?EmailLocale $siteLocale = null)
    {
    }

    #[Route('/api/periodes/{id}/simulation', name: 'gestion_periode_simulation', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function simulate(Collections $collection, Request $request): JsonResponse
    {
        $body = $request->getContent() === '' ? [] : json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            throw new GestionValidationException(['body' => 'INVALID_JSON']);
        }

        return $this->json($this->useCase->simulate($collection, $body, $this->locale($request)));
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
