<?php

namespace App\Controller\GestionController;

use App\Services\EmailConfigurationService\EmailLocale;
use App\UseCase\GestionUseCase\GestionStatisticsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Statistiques unifiées de la gestion (lot S1, 11/11/2026, `docs/statistiques-gestion.md`), `ROLE_ADMIN` (`access_control` `/api/gestion`) :
 * ventes, marge, taux de marque, commandes, panier moyen, taux de retour, valeur du stock, autres revenus, avec plage précédente, an dernier et
 * séries ; règles du résultat de période. Les anciennes routes de statistiques restent intactes (la caisse en lit certaines). Écran : `statistiques`.
 */
class GestionStatisticsController extends AbstractController
{
    public function __construct(private readonly GestionStatisticsUseCase $useCase, private readonly ?EmailLocale $siteLocale = null)
    {
    }

    #[Route('/api/gestion/statistiques', name: 'gestion_statistiques', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        return $this->json($this->useCase->overview($request->query->all(), $this->locale($request)));
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
