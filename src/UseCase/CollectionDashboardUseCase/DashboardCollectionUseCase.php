<?php
namespace App\UseCase\CollectionDashboardUseCase;

use App\Services\CollectionDashboardService\CollectionDashboardService;
use App\Entity\Collections;
use App\Dto\DashboardCollectionDTO;

class DashboardCollectionUseCase
{
    private CollectionDashboardService $dashboardService;

    public function __construct(CollectionDashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    public function execute(Collections $collection): DashboardCollectionDTO
    {
        return $this->dashboardService->calculateDashboardMetrics($collection);
    }
}
