<?php
namespace App\UseCase\CollectionDashboardUseCase;

use App\Entity\Collections;
use App\Services\CollectionDashboardService\CollectionStatistiquesService;
use Doctrine\ORM\EntityManagerInterface;
use App\Dto\DashboardCollectionDTO;

class CloseCollectionUseCase
{
    private CollectionStatistiquesService $collectionStatistiquesService;
    private DashboardCollectionUseCase $dashboardCollectionUseCase;
    private EntityManagerInterface $entityManager;

    public function __construct(
        CollectionStatistiquesService $collectionStatistiquesService,
        DashboardCollectionUseCase $dashboardCollectionUseCase,
        EntityManagerInterface $entityManager
    ) {
        $this->collectionStatistiquesService = $collectionStatistiquesService;
        $this->dashboardCollectionUseCase = $dashboardCollectionUseCase;
        $this->entityManager = $entityManager;
    }

    public function execute(Collections $collection): void
    {
        if ($collection->isClosed()) {
            throw new \Exception('La collection est déjà terminée.');
        }

        $metricsDTO = $this->dashboardCollectionUseCase->execute($collection);
        $this->collectionStatistiquesService->createAndSaveMetrics($collection, $metricsDTO);

        $collection->setClosed(true);
        $this->entityManager->flush();
    }
}
