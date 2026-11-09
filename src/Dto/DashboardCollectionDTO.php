<?php
namespace App\Dto;

use App\Entity\CollectionStatistiques;

class DashboardCollectionDTO
{
    public float $averageMultiplier;
    public array $budgetGeneral;
    public array $collectionDuration;
    public int $totalItemCost; // cents (lot G1)
    public int $purchaseCost = 0; // = totalItemCost, nom explicite (lot G2)
    public array $generalExpenses;
    public array $statistics;
    public array $valeurStock;
    public array $tauxMarge;

    public function __construct(
        float $averageMultiplier,
        array $budgetGeneral,
        array $collectionDuration,
        int $totalItemCost,
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
        $dto = new self(
            $data['averageMultiplier'],
            $data['BudgetGeneral'],
            $data['CollectionDuration'],
            (int) $data['totalItemCost'],
            $data['GeneralExpenses'],
            $data['Statistics'],
            $data['ValeurStock'],
            $data['TauxMarge']
        );
        $dto->purchaseCost = (int) ($data['purchaseCost'] ?? $data['totalItemCost']);

        return $dto;
    }

    // Nouvelle méthode pour créer un DashboardCollectionDTO à partir de CollectionStatistiques
    public static function fromCollectionStatistiques(CollectionStatistiques $collectionStatistiques): self
    {
        $dto = new self(
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
            (int) $collectionStatistiques->getTotalItemCost(),
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
        $dto->purchaseCost = (int) $collectionStatistiques->getTotalItemCost();

        return $dto;
    }

    public function toArray(): array
    {
        return [
            'averageMultiplier' => $this->averageMultiplier,
            'BudgetGeneral' => $this->budgetGeneral,
            'CollectionDuration' => $this->collectionDuration,
            'purchaseCost' => $this->purchaseCost,
            'totalItemCost' => $this->totalItemCost,
            'GeneralExpenses' => $this->generalExpenses,
            'Statistics' => $this->statistics,
            'ValeurStock' => $this->valeurStock,
            'TauxMarge' => $this->tauxMarge,
        ];
    }
}
