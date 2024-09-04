<?php
namespace App\Dto;

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
}
