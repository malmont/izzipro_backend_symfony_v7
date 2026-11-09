<?php

namespace App\Services\CommandeDashboardService;

use App\Dto\DashboardCommandeDTO;
use App\Entity\Commande;
use App\Services\GestionService\PurchaseMetricsCalculator;

/**
 * Tableau de bord d'une commande fournisseur (lot G2, 09/11/2026) : chiffres calculés par `PurchaseMetricsCalculator`
 * (agrégats SQL, cents entiers, taux arrondis), mêmes clés JSON qu'avant. Jusqu'au 09/11/2026, le calcul parcourait les
 * produits (une requête par commande) et, avant le lot « rattachement », lisait le prix de vente au lieu du coût d'achat.
 */
class CommandeDashboardService
{
    public function __construct(private readonly PurchaseMetricsCalculator $calculator)
    {
    }

    public function calculateCommandeMetrics(Commande $commande): DashboardCommandeDTO
    {
        $totals = $this->calculator->commandeTotals((int) $commande->getId());
        $usedBudget = $totals['purchaseCost'] + $totals['shippingCost'];
        $transporteur = $commande->getFraisDePort()?->getTransporteur();

        return DashboardCommandeDTO::fromArray(PurchaseMetricsCalculator::blocks($totals, (int) $commande->getBudget(), $usedBudget) + [
            'totalItemCost' => $totals['purchaseCost'],
            'totalFraisDePort' => $totals['shippingCost'],
            'Statistics' => ['itemCount' => $totals['itemCount'], 'modelCount' => $totals['modelCount']],
            'Transporteur' => $transporteur ? ['name' => $transporteur->getName(), 'logo' => $transporteur->getLogo(), 'contact' => $transporteur->getContact()] : null,
        ]);
    }
}
