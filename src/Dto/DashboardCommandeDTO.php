<?php
namespace App\Dto;
use App\Entity\CommandeStatistiques;

class DashboardCommandeDTO
{
    public float $averageMultiplier;
    public array $budgetGeneral;
    public int $totalItemCost; // cents (lot G1)
    public int $purchaseCost = 0; // = totalItemCost, nom explicite (lot G2)
    public int $totalFraisDePort;
    public array $statistics;
    public array $valeurStock;
    public array $tauxMarge;
    public ?array $transporteur;

    public function __construct(
        float $averageMultiplier,
        array $budgetGeneral,
        int $totalItemCost,
        int $totalFraisDePort,
        array $statistics,
        array $valeurStock,
        array $tauxMarge,
        ?array $transporteur
    ) {
        $this->averageMultiplier = $averageMultiplier;
        $this->budgetGeneral = $budgetGeneral;
        $this->totalItemCost = $totalItemCost;
        $this->totalFraisDePort = $totalFraisDePort;
        $this->statistics = $statistics;
        $this->valeurStock = $valeurStock;
        $this->tauxMarge = $tauxMarge;
        $this->transporteur = $transporteur;
    }

    public static function fromArray(array $data): self
    {
        $dto = new self(
            $data['averageMultiplier'],
            $data['BudgetGeneral'],
            (int) $data['totalItemCost'],
            (int) $data['totalFraisDePort'],
            $data['Statistics'],
            $data['ValeurStock'],
            $data['TauxMarge'],
            $data['Transporteur']
        );
        $dto->purchaseCost = (int) ($data['purchaseCost'] ?? $data['totalItemCost']);

        return $dto;
    }

    public static function fromEntity(CommandeStatistiques $statistiques): self
    {
        $dto = new self(
            $statistiques->getAverageMultiplier(),
            [
                'generalBudget' => $statistiques->getGeneralBudget(),
                'usedBudget' => $statistiques->getUsedBudget(),
                'remainingBudget' => $statistiques->getRemainingBudget(),
            ],
            (int) $statistiques->getTotalItemCost(),
            (int) $statistiques->getTotalFraisDePort(),
            [
                'itemCount' => $statistiques->getItemCount(),
                'modelCount' => $statistiques->getModelCount(),
            ],
            [
                'stockValue' => $statistiques->getStockValue(),
                'marge' => $statistiques->getMarge(),
            ],
            [
                'tauxMarge' => $statistiques->getTauxMarge(),
                'tauxMarque' => $statistiques->getTauxMarque(),
            ],
            $statistiques->getTransporteur() ? [
                'name' => $statistiques->getTransporteur()->getName(),
                'logo' => $statistiques->getTransporteur()->getLogo(),
                'contact' => $statistiques->getTransporteur()->getContact(),
            ] : null
        );
        $dto->purchaseCost = (int) $statistiques->getTotalItemCost();

        return $dto;
    }

    public function toArray(): array
    {
        return [
            'averageMultiplier' => $this->averageMultiplier,
            'BudgetGeneral' => $this->budgetGeneral,
            'purchaseCost' => $this->purchaseCost,
            'totalItemCost' => $this->totalItemCost,
            'totalFraisDePort' => $this->totalFraisDePort,
            'Statistics' => $this->statistics,
            'ValeurStock' => $this->valeurStock,
            'TauxMarge' => $this->tauxMarge,
            'Transporteur' => $this->transporteur,
        ];
    }
}
