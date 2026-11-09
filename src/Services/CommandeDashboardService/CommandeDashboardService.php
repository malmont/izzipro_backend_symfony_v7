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
        // devise d'achat (lot G6, 10/11/2026) : budget ramené dans la devise du site ; montants d'origine dans `purchase`
        $rate = $commande->rate();
        $generalBudget = (int) round((int) $commande->getBudget() * $rate);
        $transporteur = $commande->getFraisDePort()?->getTransporteur();

        return DashboardCommandeDTO::fromArray(PurchaseMetricsCalculator::blocks($totals, $generalBudget, $usedBudget) + [
            'purchase' => $commande->getCurrency() === null ? null : ['currency' => $commande->getCurrency(), 'exchangeRate' => $rate, 'generalBudget' => (int) $commande->getBudget(), 'purchaseCost' => (int) round($totals['purchaseCost'] / $rate), 'shippingCost' => (int) round($totals['shippingCost'] / $rate)],
            'totalItemCost' => $totals['purchaseCost'],
            'totalFraisDePort' => $totals['shippingCost'],
            'Statistics' => ['itemCount' => $totals['itemCount'], 'modelCount' => $totals['modelCount']],
            'Transporteur' => $transporteur ? ['name' => $transporteur->getName(), 'logo' => $transporteur->getLogo(), 'contact' => $transporteur->getContact()] : null,
        ]);
    }
}
