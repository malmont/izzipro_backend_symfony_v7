<?php
namespace App\Dto;
use App\Entity\CommandeStatistiques;

class DashboardCommandeDTO
{
    public float $averageMultiplier;
    public array $budgetGeneral;
    public float $totalItemCost;
    public float $totalFraisDePort;
    public array $statistics;
    public array $valeurStock;
    public array $tauxMarge;
    public ?array $transporteur;

    public function __construct(
        float $averageMultiplier,
        array $budgetGeneral,
        float $totalItemCost,
        float $totalFraisDePort,
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
        return new self(
            $data['averageMultiplier'],
            $data['BudgetGeneral'],
            $data['totalItemCost'],
            $data['totalFraisDePort'],
            $data['Statistics'],
            $data['ValeurStock'],
            $data['TauxMarge'],
            $data['Transporteur']
        );
    }

    public static function fromEntity(CommandeStatistiques $statistiques): self
    {
        return new self(
            $statistiques->getAverageMultiplier(),
            [
                'generalBudget' => $statistiques->getGeneralBudget(),
                'usedBudget' => $statistiques->getUsedBudget(),
                'remainingBudget' => $statistiques->getRemainingBudget(),
            ],
            $statistiques->getTotalItemCost(),
            $statistiques->getTotalFraisDePort(),
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
    }

    public function toArray(): array
    {
        return [
            'averageMultiplier' => $this->averageMultiplier,
            'BudgetGeneral' => $this->budgetGeneral,
            'totalItemCost' => $this->totalItemCost,
            'totalFraisDePort' => $this->totalFraisDePort,
            'Statistics' => $this->statistics,
            'ValeurStock' => $this->valeurStock,
            'TauxMarge' => $this->tauxMarge,
            'Transporteur' => $this->transporteur,
        ];
    }
}
