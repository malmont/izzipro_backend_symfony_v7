<?php
namespace App\Services\StatistiqueService;

use App\Entity\OrderTax; // <-- On importe l'entité
use App\Repository\OrderTaxRepository; // <-- On importe le repository pour le type-hint
use App\Services\TenantEntityManagerProvider; // <-- On importe notre provider

class TaxService
{
    // MODIFICATION 1 : Le service ne dépend plus que du provider
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    /**
     * MODIFICATION 2 : On crée une méthode privée pour récupérer le repository du tenant.
     */
    private function getOrderTaxRepository(): OrderTaxRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(OrderTax::class);
    }

    /**
     * Récupère les taxes totales par mois pour l'année en cours.
     *
     * @param int $year
     * @return array
     */
    public function getMonthlyTaxesForYear(int $year): array
    {
        // MODIFICATION 3 : On utilise notre nouvelle méthode privée
        $orderTaxRepository = $this->getOrderTaxRepository();
        
        $taxesParMois = [];
        $moisNoms = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
        ];

        foreach ($moisNoms as $month => $nom) {
            // On utilise la variable locale $orderTaxRepository
            $totalTax = $orderTaxRepository->getTotalTaxByMonth($year, $month);
            $taxesParMois[] = $this->transformToMonthlyTaxObject($nom, $totalTax);
        }

        return ['monthly_taxes_for_current_year' => $taxesParMois];
    }

    /**
     * INCHANGÉ : Cette méthode privée est une logique pure, pas de modification nécessaire.
     */
    private function transformToMonthlyTaxObject(string $month, float $totalTax): array
    {
        return [
            'month' => $month,
            'total_tax' => $totalTax
        ];
    }
}