<?php

namespace App\UseCase\GestionUseCase;

use App\Services\GestionService\GestionNotificationService;

/** Préférences et journal des notifications de la gestion (lot N1, `docs/notifications-gestion.md`). */
class GestionNotificationUseCase
{
    public function __construct(private readonly GestionNotificationService $notifications)
    {
    }

    public function preferences(int $userId, string $locale): array
    {
        return $this->notifications->preferences($userId, $locale);
    }

    public function updatePreferences(int $userId, array $body, string $locale): array
    {
        $this->notifications->updatePreferences($userId, $body);

        return $this->notifications->preferences($userId, $locale);
    }

    /** @return list<array<string, mixed>> */
    public function journal(int $userId, int $limit): array
    {
        return $this->notifications->journal($userId, $limit);
    }
}
