<?php

namespace App\Controller\NotificationController;

use App\Entity\User;
use App\Gestion\Services\GestionValidationException;
use App\UseCase\NotificationUseCase\PushDeviceUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Appareils inscrits aux notifications sur téléphone (lot N1, 11/11/2026, `docs/notifications-gestion.md`), pour toute application :
 * utilisateur connecté ; le jeton est rattaché au site appelant (`X-Tenant-Host`), un retrait ne touche que ce site.
 */
#[Route('/api/notifications/appareils')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class PushDeviceController extends AbstractController
{
    public function __construct(private readonly PushDeviceUseCase $useCase)
    {
    }

    #[Route('', name: 'notifications_appareils_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            throw new GestionValidationException(['body' => 'INVALID_JSON']);
        }
        $result = $this->useCase->register($user instanceof User ? (int) $user->getId() : 0, $user?->getRoles() ?? [], $body);

        return $this->json($result['device'], $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route('/{token}', name: 'notifications_appareils_delete', methods: ['DELETE'], requirements: ['token' => '.{1,512}'])]
    public function unregister(string $token): Response
    {
        $user = $this->getUser();
        $this->useCase->unregister($token, $user instanceof User ? (int) $user->getId() : 0);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
