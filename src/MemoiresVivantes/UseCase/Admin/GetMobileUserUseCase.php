<?php

namespace App\MemoiresVivantes\UseCase\Admin;

use App\MemoiresVivantes\Services\MobileUserStatsService;

/** GET /api/memoires/admin/mobile-users/{userId} : détail d'un utilisateur de l'application mobile (null : inconnu ou hors population) */
class GetMobileUserUseCase
{
    public function __construct(private readonly MobileUserStatsService $stats)
    {
    }

    public function execute(int $userId): ?array
    {
        return $this->stats->detail($userId);
    }
}
