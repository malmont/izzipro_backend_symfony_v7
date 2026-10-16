<?php

namespace App\MemoiresVivantes\UseCase\Admin;

use App\MemoiresVivantes\Services\MobileUserStatsService;

/** GET /api/memoires/admin/mobile-users : utilisateurs de l'application mobile, paginés, avec leur activité */
class ListMobileUsersUseCase
{
    public function __construct(private readonly MobileUserStatsService $stats)
    {
    }

    public function execute(?string $search, mixed $page, mixed $perPage): array
    {
        return $this->stats->list((string) $search, is_numeric($page) ? (int) $page : 1, is_numeric($perPage) ? (int) $perPage : MobileUserStatsService::DEFAULT_PER_PAGE);
    }
}
