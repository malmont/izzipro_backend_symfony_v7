<?php

namespace App\Controller\GestionController;

use App\Services\EmailConfigurationService\EmailLocale;
use App\UseCase\GestionUseCase\GestionStatisticsUseCase;
use App\UseCase\GestionUseCase\GestionYearUseCase;
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
    public function __construct(private readonly GestionStatisticsUseCase $useCase, private readonly GestionYearUseCase $years, private readonly ?EmailLocale $siteLocale = null)
    {
    }

    /** Lot AN : l'année de l'entreprise (séries mensuelles, cumul, an dernier, périodes, rentabilité, leviers, bilan d'une année terminée) */
    #[Route('/api/gestion/statistiques/annee', name: 'gestion_statistiques_annee', methods: ['GET'])]
    public function year(Request $request): JsonResponse
    {
        return $this->json($this->years->overview($request->query->all(), $this->locale($request)));
    }

    /** Lot AN : avis de l'IA sur une année terminée (409 YEAR_NOT_FINISHED sinon ; plafond partagé de 10 avis par jour) */
    #[Route('/api/gestion/statistiques/annee/avis', name: 'gestion_statistiques_annee_avis', methods: ['POST'])]
    public function yearAdvice(Request $request): JsonResponse
    {
        return $this->json($this->years->advise($request->query->all(), $this->locale($request), $this->getUser()?->getUserIdentifier()));
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
