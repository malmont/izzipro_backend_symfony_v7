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
            $taxesParMois[$nom] = $this->orderTaxRepository->getTotalTaxByMonth($year, $month);
        }

        return ['monthly_taxe_for_current_year' => $taxesParMois];
    }
}
