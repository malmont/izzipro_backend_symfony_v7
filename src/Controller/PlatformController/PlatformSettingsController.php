<?php

namespace App\Controller\PlatformController;

use App\Services\Platform\PlatformModeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages de la plateforme (console de pilotage, 04/11/2026), super administrateur : GET → { mode, updatedAt, updatedBy } ;
 * PUT { mode: demo | live } → même réponse ; 422 VALIDATION_FAILED. Contrat : docs/console-pilotage.md.
 */
#[Route('/api/platform/settings')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class PlatformSettingsController extends AbstractController
{
    public function __construct(private readonly PlatformModeService $mode)
    {
    }

    #[Route('', name: 'api_platform_settings_get', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->json($this->mode->settings());
    }

    #[Route('', name: 'api_platform_settings_put', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $mode = is_array($data) ? ($data['mode'] ?? null) : null;
        if (!is_string($mode) || !in_array($mode, PlatformModeService::MODES, true)) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'mode : demo ou live attendu', 'errors' => [['path' => 'mode', 'message' => 'demo ou live attendu']]], 422);
        }
        $this->mode->setMode($mode, $this->getUser()?->getUserIdentifier());

        return $this->json($this->mode->settings());
    }
}
