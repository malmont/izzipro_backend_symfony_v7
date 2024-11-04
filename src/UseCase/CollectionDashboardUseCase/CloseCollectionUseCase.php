<?php
namespace App\UseCase\CollectionDashboardUseCase;

use App\Entity\Collections;
use App\Services\CollectionDashboardService\CollectionStatistiquesService;
use App\Services\CommandeDashboardService\CommandeStatistiquesService;
use App\UseCase\CommandeDashboardUseCase\DashboardCommandeUseCase; 
use Doctrine\ORM\EntityManagerInterface;
use App\Dto\DashboardCollectionDTO;

class CloseCollectionUseCase
{
    private CollectionStatistiquesService $collectionStatistiquesService;
    private CommandeStatistiquesService $commandeStatistiquesService;
    private DashboardCollectionUseCase $dashboardCollectionUseCase;
    private EntityManagerInterface $entityManager;
    private DashboardCommandeUseCase $dashboardCommandeUseCase; 

    public function __construct(
        CollectionStatistiquesService $collectionStatistiquesService,
        CommandeStatistiquesService $commandeStatistiquesService,
        DashboardCollectionUseCase $dashboardCollectionUseCase,
        EntityManagerInterface $entityManager,
        DashboardCommandeUseCase $dashboardCommandeUseCase,
    ) {
        $this->collectionStatistiquesService = $collectionStatistiquesService;
        $this->commandeStatistiquesService = $commandeStatistiquesService;
        $this->dashboardCollectionUseCase = $dashboardCollectionUseCase;
        $this->entityManager = $entityManager;
        $this->dashboardCommandeUseCase = $dashboardCommandeUseCase; 
    }

    public function execute(Collections $collection): void
    {
        if ($collection->getIsClosed()) {
            throw new \Exception('La collection est déjà figée.');
        }

        $metricsDTO = $this->dashboardCollectionUseCase->execute($collection);
        $this->collectionStatistiquesService->createAndSaveMetrics($collection, $metricsDTO);

        foreach ($collection->getCommandes() as $commande) {
            if (!$commande->getIsClosed()) {
                $commandeMetricsDTO = $this->dashboardCommandeUseCase->execute($commande);
                $this->commandeStatistiquesService->createAndSaveMetrics($commande, $commandeMetricsDTO);
                
                $commande->setIsClosed(true);
                $this->entityManager->persist($commande);
            }
        }

        $collection->setIsClosed(true);
        $this->entityManager->persist($collection);

        $this->entityManager->flush();
    }
}
