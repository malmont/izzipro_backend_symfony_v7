<?php

namespace App\Gestion\Controller;

use App\Gestion\Services\GestionValidationException;
use App\Gestion\UseCase\GestionPriceSettingsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Réglages de prix du site (lot IA-ACT-5, 13/11/2026, `docs/prix-gestion.md` § 5), `ROLE_ADMIN` (`access_control` `/api/gestion`) :
 * `{ defaultCoefficient, minCoefficient, rounding, roundings, stored }`. Une valeur jamais réglée rend sa valeur par défaut, et `stored`
 * dit ce qui est réellement enregistré.
 */
#[Route('/api/gestion/reglages/prix')]
class GestionPriceSettingsController extends AbstractController
{
    public function __construct(private readonly GestionPriceSettingsUseCase $useCase)
    {
    }

    #[Route('', name: 'gestion_reglages_prix', methods: ['GET'])]
    public function settings(): JsonResponse
    {
        return $this->json($this->useCase->settings());
    }

    /** `{ defaultCoefficient?, minCoefficient?, rounding? }` ; `null` remet la valeur par défaut. 422 `VALIDATION_FAILED { fields }`. */
    #[Route('', name: 'gestion_reglages_prix_put', methods: ['PUT'])]
    public function put(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            throw new GestionValidationException(['body' => 'INVALID_JSON']);
        }

        return $this->json($this->useCase->put($body));
    }
}
