<?php

namespace App\Controller\GestionController;

use App\Entity\User;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\GestionService\GestionValidationException;
use App\UseCase\GestionUseCase\GestionNotificationUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Notifications sur téléphone de la gestion (lot N1, 11/11/2026, `docs/notifications-gestion.md`), `ROLE_ADMIN` (`access_control`
 * `/api/gestion`) : préférences par gérant et journal de ses notifications.
 */
#[Route('/api/gestion/notifications')]
class GestionNotificationController extends AbstractController
{
    public function __construct(private readonly GestionNotificationUseCase $useCase, private readonly ?EmailLocale $siteLocale = null)
    {
    }

    #[Route('/preferences', name: 'gestion_notifications_preferences', methods: ['GET'])]
    public function preferences(Request $request): JsonResponse
    {
        return $this->json($this->useCase->preferences($this->userId(), $this->locale($request->query->get('locale'))));
    }

    #[Route('/preferences', name: 'gestion_notifications_preferences_update', methods: ['PATCH'])]
    public function updatePreferences(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            throw new GestionValidationException(['body' => 'INVALID_JSON']);
        }

        return $this->json($this->useCase->updatePreferences($this->userId(), $body, $this->locale($request->query->get('locale'))));
    }

    #[Route('/journal', name: 'gestion_notifications_journal', methods: ['GET'])]
    public function journal(Request $request): JsonResponse
    {
        return $this->json($this->useCase->journal($this->userId(), (int) $request->query->get('limit', 20)));
    }

    private function userId(): int
    {
        $user = $this->getUser();

        return $user instanceof User ? (int) $user->getId() : 0;
    }

    private function locale(mixed $asked): string
    {
        if (is_string($asked) && in_array(strtolower($asked), ['fr', 'en'], true)) {
            return strtolower($asked);
        }

        return $this->siteLocale?->language(null) ?? 'fr';
    }
}
