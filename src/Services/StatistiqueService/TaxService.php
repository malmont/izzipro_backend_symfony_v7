<?php

namespace App\Services\StatistiqueService;

use App\Repository\OrderTaxRepository;

class TaxService
{
    private $orderTaxRepository;

    public function __construct(OrderTaxRepository $orderTaxRepository)
    {
        $this->orderTaxRepository = $orderTaxRepository;
    }

    /**
     * Récupère les taxes totales par mois pour l'année en cours.
     *
     * @param int $year
     * @return array
     */
    public function getMonthlyTaxesForYear(int $year): array
    {
        $taxesParMois = [];
        $moisNoms = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
        ];

        foreach ($moisNoms as $month => $nom) {
            $totalTax = $this->orderTaxRepository->getTotalTaxByMonth($year, $month);
            $taxesParMois[] = $this->transformToMonthlyTaxObject($nom, $totalTax);
        }

        return ['monthly_taxes_for_current_year' => $taxesParMois];
    }

    /**
     * Transforme les données de taxe mensuelle en un objet structuré.
     *
     * @param string $month Nom du mois
     * @param float $totalTax Total des taxes pour le mois
     * @return array
     */
    private function transformToMonthlyTaxObject(string $month, float $totalTax): array
    {
        return [
            'month' => $month,
            'total_tax' => $totalTax
        ];
    }
}
