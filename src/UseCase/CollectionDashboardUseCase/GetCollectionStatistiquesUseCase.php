<?php

namespace App\UseCase\CollectionDashboardUseCase;

use App\Entity\Collections;
use App\Services\CollectionDashboardService\CollectionStatistiquesService;
use App\Dto\DashboardCollectionDTO;

class GetCollectionStatistiquesUseCase
{
    private CollectionStatistiquesService $collectionStatistiquesService;

    public function __construct(CollectionStatistiquesService $collectionStatistiquesService, private readonly ?DashboardCollectionUseCase $dashboard = null)
    {
        $this->collectionStatistiquesService = $collectionStatistiquesService;
    }

    public function execute(Collections $collection): ?DashboardCollectionDTO
    {
        $collectionStatistiques = $this->collectionStatistiquesService->getLatestStatistiqueForCollection($collection);

        if (!$collectionStatistiques) {
            return null;
        }

        return DashboardCollectionDTO::fromCollectionStatistiques($collectionStatistiques);
    }

    /** Chiffres figés, calculés et enregistrés à la première lecture d'une collection fermée par la date (lot P1, 10/11/2026) */
    public function executeOrFreeze(Collections $collection): DashboardCollectionDTO
    {
        $frozen = $this->execute($collection);
        if ($frozen !== null) {
            return $frozen;
        }
        $metrics = $this->dashboard->execute($collection);
        $this->collectionStatistiquesService->createAndSaveMetrics($collection, $metrics);

        return $metrics;
    }
}
