<?php

namespace App\LossPrevention\Controller;

use App\Entity\User;
use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\UseCase\LossBoxUseCase;
use App\Services\EmailConfigurationService\EmailLocale;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Boîtiers de la boutique, vus par l'administrateur (lot L3-a, 15/11/2026, `docs/prevention-pertes.md` § 16). `ROLE_ADMIN`
 * (`access_control` `/api/gestion`), garde de la gestion puis du module. Les routes `/exercices/boitiers…` jouent un boîtier
 * simulé ; elles sont ouvertes sans le droit du module (`LossPreventionModuleSubscriber::OPEN_ROUTES`). Les routes que le boîtier
 * appelle lui-même sont ailleurs (`LossBoxChannelController`, `/api/pertes/boitier`).
 */
#[Route('/api/gestion/pertes')]
class LossBoxController extends AbstractController
{
    public function __construct(private readonly LossBoxUseCase $useCase, private readonly ?EmailLocale $siteLocale = null)
    {
    }

    /** Boîtiers du site → 200 { items, total, limit, exercise, screen } ; une lecture */
    #[Route('/boitiers', name: 'gestion_pertes_boitiers', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return $this->json($this->useCase->list($this->locale($request)));
    }

    /** Ajouter un boîtier `{ name, place? }` → 201 { box, key, enrolment } : la clé n'est rendue qu'ici ; 409 BOX_LIMIT_REACHED */
    #[Route('/boitiers', name: 'gestion_pertes_boitiers_creer', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->json($this->useCase->create($this->body($request), $this->locale($request), $this->userId()), 201);
    }

    /** Fiche d'un boîtier → 200 (appareils, derniers faits, faits de pièces reçus) ; 404 BOX_NOT_FOUND */
    #[Route('/boitiers/{id}', name: 'gestion_pertes_boitier', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function sheet(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->sheet($id, $this->locale($request)));
    }

    /** Renommer un boîtier `{ name?, place? }` → 200 la fiche */
    #[Route('/boitiers/{id}', name: 'gestion_pertes_boitier_modifier', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->update($id, $this->body($request), $this->locale($request)));
    }

    /** Remettre la clé à zéro `{ confirm: true }` → 200 { box, key, enrolment } : la machine d'avant est détachée ; 409 BOX_REVOKED */
    #[Route('/boitiers/{id}/cle', name: 'gestion_pertes_boitier_cle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resetKey(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->resetKey($id, $this->body($request), $this->locale($request), $this->userId()));
    }

    /** Révoquer un boîtier `{ confirm: true }` → 200 la fiche ; définitif */
    #[Route('/boitiers/{id}/revoquer', name: 'gestion_pertes_boitier_revoquer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revoke(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->revoke($id, $this->body($request), $this->locale($request), $this->userId()));
    }

    // ----- Mode exercice : le boîtier simulé, ouvert sans le droit du module (`LossPreventionModuleSubscriber::OPEN_ROUTES`) -----

    /** Boîtiers simulés → 200 { items, total, limit, exercise, screen, scenarios } */
    #[Route('/exercices/boitiers', name: 'gestion_pertes_exercices_boitiers', methods: ['GET'])]
    public function exerciseList(Request $request): JsonResponse
    {
        return $this->json($this->useCase->list($this->locale($request), true));
    }

    /** Créer un boîtier simulé (aucun corps) → 201 { box, scenarios } ; 409 EXERCISE_LIMIT_REACHED */
    #[Route('/exercices/boitiers', name: 'gestion_pertes_exercices_boitiers_creer', methods: ['POST'])]
    public function exerciseCreate(Request $request): JsonResponse
    {
        return $this->json($this->useCase->createExercise($this->locale($request), $this->userId()), 201);
    }

    #[Route('/exercices/boitiers/{id}', name: 'gestion_pertes_exercices_boitier', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function exerciseSheet(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->sheet($id, $this->locale($request), true));
    }

    /** Jouer un scénario `{ scenario }` → 200 { scenario, written, box } ; 409 EXERCISE_NO_PIECE */
    #[Route('/exercices/boitiers/{id}/scenarios', name: 'gestion_pertes_exercices_boitier_scenarios', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function exercisePlay(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->play($id, $this->body($request), $this->locale($request)));
    }

    /** Effacer tous les boîtiers simulés → 200 { deleted } */
    #[Route('/exercices/boitiers', name: 'gestion_pertes_exercices_boitiers_effacer', methods: ['DELETE'])]
    public function exerciseDelete(): JsonResponse
    {
        return $this->json($this->useCase->deleteExercise());
    }

    private function userId(): int
    {
        $user = $this->getUser();
        if (!$user instanceof User || $user->getId() === null) {
            throw $this->createAccessDeniedException();
        }

        return (int) $user->getId();
    }

    /** @return array<string, mixed> corps JSON ; un corps vide vaut {} */
    private function body(Request $request): array
    {
        $content = trim((string) $request->getContent());
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
