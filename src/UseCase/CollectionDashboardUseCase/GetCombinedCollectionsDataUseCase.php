<?php

namespace App\UseCase\CollectionDashboardUseCase;

use App\Services\CollectionDashboardService\CombinedCollectionDashboardService;

class GetCombinedCollectionsDataUseCase
{
    private CombinedCollectionDashboardService $combinedCollectionDashboardService;

    public function __construct(CombinedCollectionDashboardService $combinedCollectionDashboardService)
    {
        $this->combinedCollectionDashboardService = $combinedCollectionDashboardService;
    }

    public function execute(string $kind = 'manual'): array
    {
        return $this->combinedCollectionDashboardService->getCombinedCollectionsData($kind);
    }
}
