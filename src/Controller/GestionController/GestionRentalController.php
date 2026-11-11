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
 * (LOC1 : revenu à la date de vente et par date de location, occupation par article et par jour de la semaine) ; promotions de location
 * par article (LOC3) ; changement de grille (LOC4). Écran : `locations`.
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

    /** Lot LOC3 : aperçu d'une promotion de location par article (aucune écriture) */
    #[Route('/promotions/apercu', name: 'gestion_locations_promotions_apercu', methods: ['POST'])]
    public function promotionPreview(Request $request): JsonResponse
    {
        return $this->json($this->useCase->promotionPreview($this->body($request)));
    }

    /** Lot LOC3 : application (empreinte de l'aperçu, tout ou rien) */
    #[Route('/promotions/appliquer', name: 'gestion_locations_promotions_appliquer', methods: ['POST'])]
    public function promotionApply(Request $request): JsonResponse
    {
        return $this->json($this->useCase->promotionApply($this->body($request), $this->getUser()?->getUserIdentifier()), 201);
    }

    /** Lot LOC3 : fin d'un lot (à venir : jamais appliqué ; en cours : jusqu'à la fin de la journée du magasin) */
    #[Route('/promotions/terminer', name: 'gestion_locations_promotions_terminer', methods: ['POST'])]
    public function promotionEnd(Request $request): JsonResponse
    {
        return $this->json($this->useCase->promotionEnd($this->body($request), $this->getUser()?->getUserIdentifier()));
    }

    #[Route('/promotions', name: 'gestion_locations_promotions', methods: ['GET'])]
    public function promotions(): JsonResponse
    {
        return $this->json($this->useCase->promotions());
    }

    #[Route('/promotions/lots', name: 'gestion_locations_promotions_lots', methods: ['GET'])]
    public function promotionBatches(Request $request): JsonResponse
    {
        return $this->json($this->useCase->promotionBatches(max(1, min(100, (int) $request->query->get('limit', 20)))));
    }

    /** Lot LOC4 : grilles de location, tarifs et articles qui les utilisent */
    #[Route('/grilles', name: 'gestion_locations_grilles', methods: ['GET'])]
    public function grids(): JsonResponse
    {
        return $this->json($this->useCase->grids());
    }

    /** Lot LOC4 : aperçu d'un changement de grille (aucune écriture) */
    #[Route('/grilles/apercu', name: 'gestion_locations_grilles_apercu', methods: ['POST'])]
    public function gridPreview(Request $request): JsonResponse
    {
        return $this->json($this->useCase->gridPreview($this->body($request)));
    }

    /** Lot LOC4 : application (empreinte, allowShared pour une grille partagée) */
    #[Route('/grilles/appliquer', name: 'gestion_locations_grilles_appliquer', methods: ['POST'])]
    public function gridApply(Request $request): JsonResponse
    {
        return $this->json($this->useCase->gridApply($this->body($request), $this->getUser()?->getUserIdentifier()), 201);
    }

    /** Lot LOC4 : annulation dans les 24 h si la grille n'a pas été retouchée */
    #[Route('/grilles/annuler', name: 'gestion_locations_grilles_annuler', methods: ['POST'])]
    public function gridUndo(Request $request): JsonResponse
    {
        return $this->json($this->useCase->gridUndo($this->body($request), $this->getUser()?->getUserIdentifier()));
    }

    #[Route('/grilles/modifications', name: 'gestion_locations_grilles_modifications', methods: ['GET'])]
    public function gridChanges(Request $request): JsonResponse
    {
        return $this->json($this->useCase->gridChanges(max(1, min(100, (int) $request->query->get('limit', 20)))));
    }

    private function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            throw new \App\Services\GestionService\GestionValidationException(['body' => 'INVALID_JSON']);
        }

        return $body;
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
