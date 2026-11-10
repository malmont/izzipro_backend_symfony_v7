<?php

namespace App\UseCase\NotificationUseCase;

use App\Services\NotificationService\PushDeviceService;

/** Inscription et retrait d'un appareil aux notifications (lot N1, `docs/notifications-gestion.md`). */
class PushDeviceUseCase
{
    public function __construct(private readonly PushDeviceService $devices)
    {
    }

    /** @return array{created: bool, device: array<string, mixed>} */
    public function register(int $userId, array $roles, array $body): array
    {
        return $this->devices->register($userId, $roles, $body);
    }

    public function unregister(string $token, int $userId): void
    {
        $this->devices->unregister($token, $userId);
    }
}
