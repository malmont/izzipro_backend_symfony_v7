<?php

namespace App\MemoiresVivantes\UseCase\Admin;

use App\MemoiresVivantes\Services\RevenueOverviewService;

/** GET /api/memoires/admin/revenue : vue d'ensemble des revenus par livre, web et mobile */
class GetRevenueOverviewUseCase
{
    public function __construct(private readonly RevenueOverviewService $revenue)
    {
    }

    /** @throws \InvalidArgumentException paramètre invalide */
    public function execute(array $query): array
    {
        return $this->revenue->overview($query);
    }
}
