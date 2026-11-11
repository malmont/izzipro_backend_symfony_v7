<?php

namespace App\Controller\GestionController;

use App\Services\EmailConfigurationService\EmailLocale;
use App\UseCase\GestionUseCase\GestionFixedCostUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Frais fixes mensuels de la gestion (lot FF1, 11/11/2026, `docs/frais-fixes-gestion.md`), `ROLE_ADMIN` (`access_control` `/api/gestion`) :
 * mouvements prévus sortants et récurrents cochés frais fixes (ou par la règle de leur catégorie). La saisie passe par les mouvements de la
 * trésorerie (`fixedCost`). Code d'écran : `frais-fixes`.
 */
class GestionFixedCostController extends AbstractController
{
    public function __construct(private readonly GestionFixedCostUseCase $useCase, private readonly ?EmailLocale $siteLocale = null)
    {
    }

    #[Route('/api/gestion/frais-fixes', name: 'gestion_frais_fixes', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return $this->json($this->useCase->list($request->query->getBoolean('all'), $this->locale($request)));
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
