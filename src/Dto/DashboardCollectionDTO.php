<?php
namespace App\Dto;

use App\Entity\CollectionStatistiques;

class DashboardCollectionDTO
{
    public float $averageMultiplier;
    public array $budgetGeneral;
    public array $collectionDuration;
    public float $totalItemCost;
    public array $generalExpenses;
    public array $statistics;
    public array $valeurStock;
    public array $tauxMarge;

    public function __construct(
        float $averageMultiplier,
        array $budgetGeneral,
        array $collectionDuration,
        float $totalItemCost,
        array $generalExpenses,
        array $statistics,
        array $valeurStock,
        array $tauxMarge
    ) {
        $this->averageMultiplier = $averageMultiplier;
        $this->budgetGeneral = $budgetGeneral;
        $this->collectionDuration = $collectionDuration;
        $this->totalItemCost = $totalItemCost;
        $this->generalExpenses = $generalExpenses;
        $this->statistics = $statistics;
        $this->valeurStock = $valeurStock;
        $this->tauxMarge = $tauxMarge;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['averageMultiplier'],
            $data['BudgetGeneral'],
            $data['CollectionDuration'],
            $data['totalItemCost'],
            $data['GeneralExpenses'],
            $data['Statistics'],
            $data['ValeurStock'],
            $data['TauxMarge']
        );
    }

    // Nouvelle méthode pour créer un DashboardCollectionDTO à partir de CollectionStatistiques
    public static function fromCollectionStatistiques(CollectionStatistiques $collectionStatistiques): self
    {
        return new self(
            $collectionStatistiques->getAverageMultiplier(),
            [
                'generalBudget' => $collectionStatistiques->getGeneralBudget(),
                'usedBudget' => $collectionStatistiques->getUsedBudget(),
                'remainingBudget' => $collectionStatistiques->getRemainingBudget(),
            ],
            [
                'startDate' => $collectionStatistiques->getStartDate()->format('Y-m-d'),
                'endDate' => $collectionStatistiques->getEndDate()->format('Y-m-d'),
                'days' => $collectionStatistiques->getDurationDays(),
            ],
            $collectionStatistiques->getTotalItemCost(),
            [
                'totalShippingCost' => $collectionStatistiques->getTotalShippingCost(),
                'totalExpenseCost' => $collectionStatistiques->getTotalExpenseCost(),
                'totalGeneralExpenses' => $collectionStatistiques->getTotalShippingCost() + $collectionStatistiques->getTotalExpenseCost(),
            ],
            [
                'orderCount' => $collectionStatistiques->getOrderCount(),
                'itemCount' => $collectionStatistiques->getItemCount(),
                'modelCount' => $collectionStatistiques->getModelCount(),
            ],
            [
                'stockValue' => $collectionStatistiques->getStockValue(),
                'marge' => $collectionStatistiques->getMargin(),
            ],
            [
                'tauxMarge' => $collectionStatistiques->getTauxMarge(),
                'tauxMarque' => $collectionStatistiques->getTauxMarque(),
            ]
        );
    }

    public function toArray(): array
    {
        return [
            'averageMultiplier' => $this->averageMultiplier,
            'BudgetGeneral' => $this->budgetGeneral,
            'CollectionDuration' => $this->collectionDuration,
            'totalItemCost' => $this->totalItemCost,
            'GeneralExpenses' => $this->generalExpenses,
            'Statistics' => $this->statistics,
            'ValeurStock' => $this->valeurStock,
            'TauxMarge' => $this->tauxMarge,
        ];
    }
}
