<?php

namespace App\Services\CollectionDashboardService;

use App\Entity\Collections;
use App\Services\CollectionDashboardService\CollectionDashboardService;
use App\Services\TenantEntityManagerProvider;

class CombinedCollectionDashboardService
{
    private TenantEntityManagerProvider $emProvider;
    private CollectionDashboardService $collectionDashboardService;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        CollectionDashboardService $collectionDashboardService
    ) {
        $this->emProvider = $emProvider;
        $this->collectionDashboardService = $collectionDashboardService;
    }

    public function getCombinedCollectionsData(): array
    {
        $em = $this->emProvider->getEntityManager();
        $collectionRepository = $em->getRepository(Collections::class);

        $collections = $collectionRepository->findAll();

        $combinedData = [
            'averageMultiplier' => 0,
            'BudgetGeneral' => [
                'generalBudget' => 0,
                'usedBudget' => 0,
                'remainingBudget' => 0,
            ],
            'CollectionDuration' => [
                'startDate' => null,
                'endDate' => null,
                'days' => 0,
            ],
            'totalItemCost' => 0,
            'GeneralExpenses' => [
                'totalShippingCost' => 0,
                'totalExpenseCost' => 0,
                'totalGeneralExpenses' => 0,
            ],
            'Statistics' => [
                'orderCount' => 0,
                'itemCount' => 0,
                'modelCount' => 0,
            ],
            'ValeurStock' => [
                'stockValue' => 0,
                'marge' => 0,
            ],
            'TauxMarge' => [
                'tauxMarge' => 0,
                'tauxMarque' => 0,
            ],
        ];

        $totalCollections = count($collections);
        $totalDurationDays = 0;

        foreach ($collections as $collection) {
            $dashboardMetrics = $this->collectionDashboardService->calculateDashboardMetrics($collection);
            $data = $dashboardMetrics->toArray();

            $combinedData['averageMultiplier'] += $data['averageMultiplier'];
            $combinedData['BudgetGeneral']['generalBudget'] += $data['BudgetGeneral']['generalBudget'];
            $combinedData['BudgetGeneral']['usedBudget'] += $data['BudgetGeneral']['usedBudget'];
            $combinedData['BudgetGeneral']['remainingBudget'] += $data['BudgetGeneral']['remainingBudget'];
            $combinedData['totalItemCost'] += $data['totalItemCost'];
            $combinedData['GeneralExpenses']['totalShippingCost'] += $data['GeneralExpenses']['totalShippingCost'];
            $combinedData['GeneralExpenses']['totalExpenseCost'] += $data['GeneralExpenses']['totalExpenseCost'];
            $combinedData['GeneralExpenses']['totalGeneralExpenses'] += $data['GeneralExpenses']['totalGeneralExpenses'];
            $combinedData['Statistics']['orderCount'] += $data['Statistics']['orderCount'];
            $combinedData['Statistics']['itemCount'] += $data['Statistics']['itemCount'];
            $combinedData['Statistics']['modelCount'] += $data['Statistics']['modelCount'];
            $combinedData['ValeurStock']['stockValue'] += $data['ValeurStock']['stockValue'];
            $combinedData['ValeurStock']['marge'] += $data['ValeurStock']['marge'];
            $combinedData['TauxMarge']['tauxMarge'] += $data['TauxMarge']['tauxMarge'];
            $combinedData['TauxMarge']['tauxMarque'] += $data['TauxMarge']['tauxMarque'];
            $totalDurationDays += $data['CollectionDuration']['days'];

            $collectionStartDate = new \DateTime($data['CollectionDuration']['startDate']);
            $collectionEndDate = new \DateTime($data['CollectionDuration']['endDate']);
            
            if (!$combinedData['CollectionDuration']['startDate'] || $collectionStartDate < new \DateTime($combinedData['CollectionDuration']['startDate'])) {
                $combinedData['CollectionDuration']['startDate'] = $collectionStartDate->format('Y-m-d');
            }
            if (!$combinedData['CollectionDuration']['endDate'] || $collectionEndDate > new \DateTime($combinedData['CollectionDuration']['endDate'])) {
                $combinedData['CollectionDuration']['endDate'] = $collectionEndDate->format('Y-m-d');
            }
        }

        if ($totalCollections > 0) {
            $combinedData['averageMultiplier'] = $combinedData['averageMultiplier'] / $totalCollections;
        }
        if ($combinedData['ValeurStock']['stockValue'] > 0) {
            $combinedData['TauxMarge']['tauxMarge'] = ($combinedData['ValeurStock']['marge'] / $combinedData['ValeurStock']['stockValue']) * 100;
        }
        if ($combinedData['BudgetGeneral']['usedBudget'] > 0) {
            $combinedData['TauxMarge']['tauxMarque'] = ($combinedData['ValeurStock']['marge'] / $combinedData['BudgetGeneral']['usedBudget']) * 100;
        }
        $combinedData['CollectionDuration']['days'] = $totalDurationDays;

        return $combinedData;
    }
}