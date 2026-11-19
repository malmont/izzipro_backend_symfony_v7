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
 * Suivi des abonnements (lot ABO-0, 18/11/2026, `docs/plan-abo-universel.md`) pour la gestion web ET l'application de gestion mobile, mêmes
 * routes : `ROLE_ADMIN` (`access_control` `/api/site/abonnements`), module Boutique (`ModuleAccessService`), lecture seule. Un refus rend
 * `{ error: CODE, code, message }`. Langue : `?locale=` (fr par défaut).
 */
#[IsGranted('ROLE_ADMIN')]
class AboFollowUpController extends AbstractController
{
    public function __construct(private readonly AboFollowUpUseCase $useCase)
    {
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

    private function guard(callable $read): JsonResponse
    {
        try {
            return $this->json($read());
        } catch (AboException $e) {
            return $this->json($e->toArray(), $e->status);
        }
    }

    private static function locale(Request $request): string
    {
        return $request->query->get('locale') === 'en' ? 'en' : 'fr';
    }
}
