<?php

namespace App\LossPrevention\Controller;

use App\Entity\User;
use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\UseCase\LossCountUseCase;
use App\Services\EmailConfigurationService\EmailLocale;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Comptages et bilan du soir de la prévention des pertes (lot L4-d, 16/11/2026, `docs/prevention-pertes.md` § 17.16). `ROLE_ADMIN`
 * (`access_control` `/api/gestion`), garde de la gestion puis garde du module, comme le reste de la section. Rien n'est écrit dans le stock,
 * les ventes ni le catalogue. Les routes `/exercices/comptages…` jouent les mêmes gestes sur des pièces d'exercice ; elles sont ouvertes
 * sans le droit du module (`LossPreventionModuleSubscriber::OPEN_ROUTES`).
 */
#[Route('/api/gestion/pertes')]
class LossCountController extends AbstractController
{
    public function __construct(private readonly LossCountUseCase $useCase, private readonly ?EmailLocale $siteLocale = null)
    {
    }

    /** Comptages, les plus récents d'abord → 200 { counts, open, expireHours } */
    #[Route('/comptages', name: 'gestion_pertes_comptages', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return $this->json($this->useCase->list(false, $this->locale($request)));
    }

    /** Ouvre un comptage `{ scope: all | <zone> }` → 201 ; 409 COUNT_ALREADY_OPEN ; 422 scope INVALID_SCOPE */
    #[Route('/comptages', name: 'gestion_pertes_comptages_open', methods: ['POST'])]
    public function open(Request $request): JsonResponse
    {
        return $this->json($this->useCase->open($this->body($request), $this->userId(), false, $this->locale($request)), 201);
    }

    /** Un comptage ; fermé : ses pièces non lues et l'écart par article → 200 ; 404 COUNT_NOT_FOUND */
    #[Route('/comptages/{id}', name: 'gestion_pertes_comptage', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->detail($id, false, $this->locale($request)));
    }

    /** Ferme un comptage → 200 ; 404 COUNT_NOT_FOUND ; 409 COUNT_NOT_OPEN */
    #[Route('/comptages/{id}/fermer', name: 'gestion_pertes_comptage_fermer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function close(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->close($id, $this->userId(), false, $this->locale($request)));
    }

    /** Bilan du soir `?day=Y-m-d&days=30` → 200 ; 422 day INVALID_DATE, days PARAM_OUT_OF_RANGE */
    #[Route('/bilan', name: 'gestion_pertes_bilan', methods: ['GET'])]
    public function evening(Request $request): JsonResponse
    {
        return $this->json($this->useCase->evening($request->query->all(), $this->locale($request)));
    }

    // ----- Mode exercice : comptages sur des pièces d'exercice, ouverts sans le droit du module

    #[Route('/exercices/comptages', name: 'gestion_pertes_exercices_comptages', methods: ['GET'])]
    public function exerciseList(Request $request): JsonResponse
    {
        return $this->json($this->useCase->list(true, $this->locale($request)));
    }

    #[Route('/exercices/comptages', name: 'gestion_pertes_exercices_comptages_open', methods: ['POST'])]
    public function exerciseOpen(Request $request): JsonResponse
    {
        return $this->json($this->useCase->open($this->body($request), $this->userId(), true, $this->locale($request)), 201);
    }

    #[Route('/exercices/comptages/{id}', name: 'gestion_pertes_exercices_comptage', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function exerciseDetail(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->detail($id, true, $this->locale($request)));
    }

    /** Bilan du soir sur l'exercice (pièces, faits, constats, appareils et comptages d'exercice) */
    #[Route('/exercices/bilan', name: 'gestion_pertes_exercices_bilan', methods: ['GET'])]
    public function exerciseEvening(Request $request): JsonResponse
    {
        return $this->json($this->useCase->evening($request->query->all(), $this->locale($request), true));
    }

    #[Route('/exercices/comptages/{id}/fermer', name: 'gestion_pertes_exercices_comptage_fermer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function exerciseClose(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->close($id, $this->userId(), true, $this->locale($request)));
    }

    private function userId(): int
    {
        $user = $this->getUser();
        if (!$user instanceof User || $user->getId() === null) {
            throw $this->createAccessDeniedException();
        }

        return (int) $user->getId();
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $content = (string) $request->getContent();
        $body = $content === '' ? [] : json_decode($content, true);
        if (!is_array($body)) {
            throw new GestionValidationException(['body' => 'INVALID_JSON']);
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
