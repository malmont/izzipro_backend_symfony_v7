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

    public function execute(int $typeId, int $statusId,?int $orderSource = null): array
    {
        return $this->statistiqueCommandeService->getDailyOrderCountForCurrentWeek($typeId, $statusId, $orderSource);
    }
}