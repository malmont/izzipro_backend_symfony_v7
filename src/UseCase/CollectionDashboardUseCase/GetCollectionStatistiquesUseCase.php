<?php

namespace App\UseCase\CollectionDashboardUseCase;

use App\Entity\Collections;
use App\Services\CollectionDashboardService\CollectionStatistiquesService;
use App\Dto\DashboardCollectionDTO;

class GetCollectionStatistiquesUseCase
{
    private CollectionStatistiquesService $collectionStatistiquesService;

    public function __construct(CollectionStatistiquesService $collectionStatistiquesService)
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
}
