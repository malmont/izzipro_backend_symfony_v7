<?php

namespace App\UseCase\CommandeDashboardUseCase;

use App\Entity\Commande;
use App\Services\CommandeDashboardService\CommandeStatistiquesService;
use App\Dto\DashboardCommandeDTO;
use Doctrine\ORM\EntityManagerInterface;

class FreezeCommandeMetricsUseCase
{
    private CommandeStatistiquesService $commandeStatistiquesService;
    private DashboardCommandeUseCase $dashboardCommandeUseCase;
    private EntityManagerInterface $entityManager;

    public function __construct(
        CommandeStatistiquesService $commandeStatistiquesService,
        DashboardCommandeUseCase $dashboardCommandeUseCase,
        EntityManagerInterface $entityManager
    ) {
        $this->commandeStatistiquesService = $commandeStatistiquesService;
        $this->dashboardCommandeUseCase = $dashboardCommandeUseCase;
        $this->entityManager = $entityManager;
    }

    public function execute(Commande $commande): void
    {
        if ($commande->getIsClosed()) {
            throw new \Exception('La commande est déjà figée.');
        }

        $metricsDTO = $this->dashboardCommandeUseCase->execute($commande);

        $this->commandeStatistiquesService->createAndSaveMetrics($commande, $metricsDTO);

        // Marquer la commande comme fermée
        $commande->setIsClosed(true);
        $this->entityManager->flush();
    }
}
