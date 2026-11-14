<?php

namespace App\Controller\AppMembershipController;

use App\Entity\User;
use App\Security\AppFamilies;
use App\Services\MediaUrlResolver;
use App\UseCase\AppMembershipUseCase\GetAppTermsUseCase;
use App\UseCase\AppMembershipUseCase\ListMyMembershipsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Familles d'applications à membres, routes de lecture (lot APPS-MEMBRES M1, 14/11/2026). Contrat : `docs/apps-membres.md`.
 * Les conditions d'une famille se lisent sans connexion (access_control `^/api/apps/[^/]+/terms$`, GET) : l'inscription et l'écran
 * « Rejoindre » les affichent. Les appartenances sont celles du compte connecté.
 */
class AppMembershipController extends AbstractController
{
    public function __construct(private readonly GetAppTermsUseCase $terms, private readonly ListMyMembershipsUseCase $mine, private readonly ?MediaUrlResolver $mediaUrlResolver = null)
    {
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
