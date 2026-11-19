<?php

namespace App\Abo\Controller;

use App\Abo\Services\AboException;
use App\Abo\UseCase\AboFollowUpUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Suivi des abonnements (lot ABO-0 ; lot ABO-1 : gestes du quotidien, sous le verrou de l'abonnement, 18/11/2026, `docs/plan-abo-universel.md`) pour la gestion web ET l'application de gestion mobile, mêmes
 * routes : `ROLE_ADMIN` (`access_control` `/api/site/abonnements`), module Boutique (`ModuleAccessService`), lecture seule. Un refus rend
 * `{ error: CODE, code, message }`. Langue : `?locale=` (fr par défaut).
 */
#[IsGranted('ROLE_ADMIN')]
class AboFollowUpController extends AbstractController
{
    public function __construct(private readonly AboFollowUpUseCase $useCase, private readonly ?\App\Abo\UseCase\AboDailyUseCase $daily = null)
    {
    }

    /** Lot ABO-1 : marque utilisé le droit qui expire le premier → 201 { use, subscription } ; 409 BALANCE_EMPTY */
    #[Route('/api/site/abonnements/{id}/utilisations', name: 'site_abo_use', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function use(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn () => $this->daily->use($id, self::body($request), $this->by(), self::locale($request)), 201);
    }

    /** Annule une utilisation dans les 24 heures → 200, la fiche ; 409 USE_UNDO_EXPIRED */
    #[Route('/api/site/abonnements/{id}/utilisations/{occurrenceId}', name: 'site_abo_use_undo', requirements: ['id' => '\d+', 'occurrenceId' => '\d+'], methods: ['DELETE'])]
    public function undoUse(Request $request, int $id, int $occurrenceId): JsonResponse
    {
        return $this->guard(fn () => $this->daily->undoUse($id, $occurrenceId, $this->by(), self::locale($request)));
    }

    /** Offre 1 à 10 droits, motif obligatoire → 201, la fiche */
    #[Route('/api/site/abonnements/{id}/droits', name: 'site_abo_gift', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function gift(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn () => $this->daily->gift($id, self::body($request), $this->by(), self::locale($request)), 201);
    }

    /** Pause datée `{ until: AAAA-MM-JJ }` (heure du magasin), dans la limite de la formule → 200, la fiche */
    #[Route('/api/site/abonnements/{id}/pause', name: 'site_abo_pause', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function pause(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn () => $this->daily->pause($id, self::body($request), $this->by(), self::locale($request)));
    }

    #[Route('/api/site/abonnements/{id}/reprise', name: 'site_abo_resume', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function resume(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn () => $this->daily->resume($id, $this->by(), self::locale($request)));
    }

    /** Résiliation en fin de période `{ reason? }` (motif exigé pendant l'engagement minimal) → 200, la fiche */
    #[Route('/api/site/abonnements/{id}/resiliation', name: 'site_abo_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn () => $this->daily->cancel($id, self::body($request), $this->by(), self::locale($request)));
    }

    /** Les formules du site, leurs prestations et leurs compteurs d'abonnés par statut → 200 { plans: [...] } */
    #[Route('/api/site/abonnements/formules', name: 'site_abo_plans', methods: ['GET'])]
    public function plans(Request $request): JsonResponse
    {
        return $this->json($this->useCase->plans(self::locale($request)));
    }

    /** Les abonnés d'une formule, paginés (`page`, `limit` 25 par défaut, 100 au plus), filtre `status` → 200 */
    #[Route('/api/site/abonnements/formules/{id}/abonnes', name: 'site_abo_plan_subscribers', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function subscribers(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn () => $this->useCase->subscribers($id, $request->query->get('status') !== null ? (string) $request->query->get('status') : null,
            $request->query->getInt('page', 1), $request->query->getInt('limit', 0), self::locale($request)));
    }

    /** La fiche d'un abonné → 200 ; 404 SUBSCRIPTION_NOT_FOUND */
    #[Route('/api/site/abonnements/{id}', name: 'site_abo_subscription', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function subscription(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn () => $this->useCase->subscription($id, self::locale($request)));
    }

    private function guard(callable $read, int $status = 200): JsonResponse
    {
        try {
            return $this->json($read(), $status);
        } catch (AboException $e) {
            return $this->json($e->toArray(), $e->status);
        }
    }

    /** @return array<string, mixed> */
    private static function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);

        return is_array($body) ? $body : [];
    }

    private function by(): ?string
    {
        $user = $this->getUser();

        return $user instanceof \App\Entity\User ? (trim((string) $user->getFirstname()) ?: $user->getUserIdentifier()) : null;
    }

    private static function locale(Request $request): string
    {
        return $request->query->get('locale') === 'en' ? 'en' : 'fr';
    }
}
