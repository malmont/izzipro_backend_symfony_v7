<?php

namespace App\Controller\AppMembershipController;

use App\Entity\User;
use App\Security\AppFamilies;
use App\Services\AppMembershipService\AppMembershipException;
use App\Services\MediaUrlResolver;
use App\UseCase\AppMembershipUseCase\GetAppTermsUseCase;
use App\UseCase\AppMembershipUseCase\JoinAppUseCase;
use App\UseCase\AppMembershipUseCase\LeaveAppUseCase;
use App\UseCase\AppMembershipUseCase\ListMyMembershipsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Familles d'applications à membres, routes de lecture (lot APPS-MEMBRES M1, 14/11/2026). Contrat : `docs/apps-membres.md`.
 * Les conditions d'une famille se lisent sans connexion (access_control `^/api/apps/[^/]+/terms$`, GET) : l'inscription et l'écran
 * « Rejoindre » les affichent. Les appartenances sont celles du compte connecté.
 */
class AppMembershipController extends AbstractController
{
    public function __construct(private readonly GetAppTermsUseCase $terms, private readonly ListMyMembershipsUseCase $mine, private readonly ?MediaUrlResolver $mediaUrlResolver = null,
        private readonly ?JoinAppUseCase $join = null, private readonly ?LeaveAppUseCase $leave = null)
    {
    }

    /**
     * `{ joinToken, termsVersion, accepted: true }` → la session, comme une connexion (lot M3). Public (access_control `^/api/apps/join$`,
     * limité en débit comme la connexion) : le jeton de passage, rendu avec le refus `APP_MEMBERSHIP_REQUIRED`, tient lieu de preuve.
     */
    #[Route('/api/apps/join', name: 'api_app_join', methods: ['POST'])]
    public function join(Request $request): Response
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body) || !isset($body['joinToken'])) {
            return $this->json(['error' => 'FIELDS_REQUIRED', 'code' => 'FIELDS_REQUIRED', 'message' => 'joinToken attendu.', 'params' => ['fields' => ['joinToken']]], 400);
        }
        try {
            return $this->join->execute($body, $request);
        } catch (AppMembershipException $e) {
            return $this->json($e->body(), $e->status);
        }
    }

    /** Le client quitte une famille sans supprimer son compte (lot M3) → 200 `{ team, memberships }` : ce qu'il reste */
    #[Route('/api/account/memberships/{app}', name: 'api_account_membership_leave', methods: ['DELETE'], requirements: ['app' => '[a-zA-Z0-9_-]{1,30}'])]
    public function leave(string $app): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized', 'code' => 'UNAUTHORIZED', 'message' => 'Unauthorized'], 401);
        }
        try {
            return $this->json($this->leave->execute($user, $app));
        } catch (AppMembershipException $e) {
            return $this->json($e->body(), $e->status);
        }
    }

    #[Route('/api/apps/{app}/terms', name: 'api_app_terms', methods: ['GET'], requirements: ['app' => '[a-zA-Z0-9_-]{1,30}'])]
    public function terms(string $app, Request $request): JsonResponse
    {
        $host = $this->mediaUrlResolver?->getPublicHost($request->getSchemeAndHttpHost()) ?? $request->getSchemeAndHttpHost();
        $result = $this->terms->execute($app, $host, (string) $request->query->get('locale', 'fr'));
        if ($result === null) {
            return $this->json(['error' => 'APP_UNKNOWN', 'code' => 'APP_UNKNOWN', 'message' => sprintf('Application inconnue : « %s »', $app), 'params' => ['apps' => array_keys(AppFamilies::APPS), 'families' => AppFamilies::ALL]], 404);
        }

        return $this->json($result);
    }

    #[Route('/api/account/memberships', name: 'api_account_memberships', methods: ['GET'])]
    public function memberships(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized', 'code' => 'UNAUTHORIZED', 'message' => 'Unauthorized'], 401);
        }

        return $this->json($this->mine->execute($user));
    }
}
