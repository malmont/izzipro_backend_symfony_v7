<?php

namespace App\Controller\AppKeyController;

use App\Services\AppKeyService\AppKeyException;
use App\UseCase\AppKeyUseCase\ActivateAppUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Activation d'une application mobile par sa clé (15/10/2026). Route publique, sans X-Tenant-Host (c'est elle qui
 * donne le site), limitée en débit par IP (PublicEndpointRateLimitSubscriber). La clé identifie le site et
 * l'application ; l'utilisateur se connecte ensuite par POST /api/login. Contrat : docs/architecture.md.
 */
class AppActivationController extends AbstractController
{
    public function __construct(private readonly ActivateAppUseCase $activate)
    {
    }

    #[Route('/api/app/activate', name: 'api_app_activate', methods: ['POST'])]
    public function activate(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'APP_KEY_MISSING', 'message' => 'Corps JSON attendu : {"key", "app"?, "deviceName"?}'], 400);
        }
        try {
            return $this->json($this->activate->execute($data));
        } catch (AppKeyException $e) {
            return $this->json($e->toBody(), $e->status);
        }
    }
}
