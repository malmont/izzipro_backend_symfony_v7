<?php
namespace App\UseCase\CommandeDashboardUseCase;

use App\Services\CommandeDashboardService\CommandeDashboardService;
use App\Entity\Commande;
use App\Dto\DashboardCommandeDTO;

class DashboardCommandeUseCase
{
    private CommandeDashboardService $commandeDashboardService;

    public function __construct(CommandeDashboardService $commandeDashboardService)
    {
        $this->commandeDashboardService = $commandeDashboardService;
    }

    public function execute(Commande $commande): DashboardCommandeDTO
    {
        return $this->commandeDashboardService->calculateCommandeMetrics($commande);
    }
}
