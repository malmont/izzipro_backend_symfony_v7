<?php
namespace App\Controller\DashboardCommandeController;

use App\Entity\Commande;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class DashboardCommandeController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/api/dashboard/commande/{id}', name: 'dashboard_commande', methods: ['GET'])]
    public function getCommandeMetrics(Commande $commande): JsonResponse
    {
        // Calculate the general coefficient (average of multipliers)
        $totalMultiplier = 0;
        $totalItems = 0;
        $totalItemCost = 0;
        $modelCount = 0;

        foreach ($commande->getProducts() as $product) {
            $totalMultiplier += $product->getCoefficientMultiplier() * $product->getQuantity();
            $totalItems += $product->getQuantity();
            $totalItemCost += $product->getPrice() * $product->getQuantity();
            $modelCount++;
        }

        $averageMultiplier = $totalItems ? $totalMultiplier / $totalItems : 0;

        // Budget calculations
        $generalBudget = $commande->getBudget();
        $usedBudget = $totalItemCost;

        // Include shipping cost if any
        if ($commande->getFraisDePort()) {
            $usedBudget += $commande->getFraisDePort()->getPrice();
        }

        $remainingBudget = $generalBudget - $usedBudget;

        // Revenue calculation (stock value)
        $stockValue = $totalItemCost * $averageMultiplier;

        // Margin calculation
        $marge = $stockValue - $usedBudget;

        // Transporter information
        $shippingCost = 0;
        $transporteur = null;
        if ($commande->getFraisDePort()) {
            $shippingCost = $commande->getFraisDePort()->getPrice();
            $transporteur = $commande->getFraisDePort()->getTransporteur() ? [
                'name' => $commande->getFraisDePort()->getTransporteur()->getName(),
                'logo' => $commande->getFraisDePort()->getTransporteur()->getLogo(),
                'contact' => $commande->getFraisDePort()->getTransporteur()->getContact(),
            ] : null;
        }

        // Construct the JSON response
        $data = [
            'averageMultiplier' => $averageMultiplier,
            'BudgetGeneral' => [
                'generalBudget' => $generalBudget,
                'usedBudget' => $usedBudget,
                'remainingBudget' => $remainingBudget,
            ],
            'totalItemCost' => $totalItemCost,
          
            'totalFraisDePort' => $shippingCost,
            
            'Statistics' => [
                'itemCount' => $totalItems,
                'modelCount' => $modelCount,
            ],
            'ValeurStock' => [
                'stockValue' => $stockValue,
                'marge' => $marge,
            ],
            'TauxMarge' => [
                'tauxMarge' => ($stockValue > 0) ? (($stockValue - $usedBudget) / $stockValue) * 100 : 0,
                'tauxMarque'=>(($stockValue - $usedBudget)/$usedBudget)*100,
            ],
            'Transporteur' => $transporteur,
        ];

        return $this->json($data, JsonResponse::HTTP_OK);
    }
}
