<?php

namespace App\Controller\PlatformController;

use App\Services\Platform\PlatformModeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages de la plateforme (console de pilotage, 04/11/2026), super administrateur : GET → { mode, updatedAt, updatedBy, shopUrl } ;
 * PUT { mode?: demo | live, shopUrl?: https… | null } → même réponse ; 422 VALIDATION_FAILED. Contrat : docs/console-pilotage.md.
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
        $data = is_array($data) ? $data : [];
        if (!array_key_exists('mode', $data) && !array_key_exists('shopUrl', $data)) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'mode ou shopUrl attendu', 'errors' => [['path' => '', 'message' => 'mode ou shopUrl attendu']]], 422);
        }
        if (array_key_exists('mode', $data)) {
            $mode = $data['mode'];
            if (!is_string($mode) || !in_array($mode, PlatformModeService::MODES, true)) {
                return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'mode : demo ou live attendu', 'errors' => [['path' => 'mode', 'message' => 'demo ou live attendu']]], 422);
            }
            $this->mode->setMode($mode, $this->getUser()?->getUserIdentifier());
        }
        if (array_key_exists('shopUrl', $data)) {
            try {
                $this->mode->setShopUrl(is_string($data['shopUrl']) ? $data['shopUrl'] : null, $this->getUser()?->getUserIdentifier());
            } catch (\InvalidArgumentException $e) {
                return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => [['path' => 'shopUrl', 'message' => 'adresse https attendue, ou vide']]], 422);
            }
        }

        return $this->json($this->mode->settings());
    }
}
