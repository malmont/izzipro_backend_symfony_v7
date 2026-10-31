<?php

namespace App\Controller\AppProfileController;

use App\Entity\AppProfile;
use App\UseCase\AppProfileUseCase\GetAppThemeUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Thème de l'application e-commerce mobile (31/10/2026), sans connexion (access_control `^/api/mobile/app-theme$`,
 * GET), site de X-Tenant-Host. Route dédiée : GET /api/app-profile/{app} et POST /api/app/activate ne changent pas.
 * `?app=` (défaut `mobile`) lit le thème d'un autre code d'application. Contrat : docs/architecture.md,
 * « Thème de l'application mobile ».
 */
class AppThemeController extends AbstractController
{
    public function __construct(private readonly GetAppThemeUseCase $theme)
    {
    }

    #[Route('/api/mobile/app-theme', name: 'api_mobile_app_theme', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        $app = (string) $request->query->get('app', 'mobile');
        $result = $this->theme->execute($app);
        if ($result === null) {
            return $this->json(['error' => 'APP_UNKNOWN', 'message' => sprintf('Application inconnue : « %s »', $app), 'apps' => array_keys(AppProfile::APPS)], 404);
        }

        return $this->json($result);
    }
}
