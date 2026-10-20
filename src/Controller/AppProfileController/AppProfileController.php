<?php

namespace App\Controller\AppProfileController;

use App\Entity\AppProfile;
use App\Services\MediaUrlResolver;
use App\UseCase\AppProfileUseCase\GetAppProfileUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Profil public d'une application du site (20/10/2026), sans connexion (access_control `^/api/app-profile/`, GET).
 * Le site est celui de X-Tenant-Host, comme partout. Contrat : docs/architecture.md, « Profils des applications ».
 */
class AppProfileController extends AbstractController
{
    public function __construct(private readonly GetAppProfileUseCase $profile, private readonly ?MediaUrlResolver $mediaUrlResolver = null)
    {
    }

    #[Route('/api/app-profile/{app}', name: 'api_app_profile', methods: ['GET'], requirements: ['app' => '[a-zA-Z0-9_-]{1,30}'])]
    public function show(string $app, Request $request): JsonResponse
    {
        $host = $this->mediaUrlResolver?->getPublicHost($request->getSchemeAndHttpHost()) ?? $request->getSchemeAndHttpHost();
        $result = $this->profile->execute($app, $host, (string) $request->query->get('locale', 'fr'));
        if ($result === null) {
            return $this->json(['error' => 'APP_UNKNOWN', 'message' => sprintf('Application inconnue : « %s »', $app), 'apps' => array_keys(AppProfile::APPS)], 404);
        }

        return $this->json($result);
    }
}
