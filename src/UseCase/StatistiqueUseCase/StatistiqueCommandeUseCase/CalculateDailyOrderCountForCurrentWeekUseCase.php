<?php

namespace App\UseCase\StatistiqueUseCase\StatistiqueCommandeUseCase;

use App\Services\StatistiqueService\StatistiqueCommandeService;

class CalculateDailyOrderCountForCurrentWeekUseCase
{
    private $statistiqueCommandeService;

    public function __construct(StatistiqueCommandeService $statistiqueCommandeService)
    {
        $this->statistiqueCommandeService = $statistiqueCommandeService;
    }

    public function execute(int $typeId, int $statusId): array
    {
        return $this->statistiqueCommandeService->getDailyOrderCountForCurrentWeek($typeId, $statusId);
    }
}