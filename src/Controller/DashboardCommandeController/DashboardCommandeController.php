<?php
namespace App\Controller\DashboardCommandeController;

use App\Entity\Commande;
use App\UseCase\CommandeDashboardUseCase\DashboardCommandeUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class DashboardCommandeController extends AbstractController
{
    private DashboardCommandeUseCase $dashboardCommandeUseCase;

    public function __construct(DashboardCommandeUseCase $dashboardCommandeUseCase)
    {
        $this->dashboardCommandeUseCase = $dashboardCommandeUseCase;
    }

    #[Route('/api/dashboard/commande/{id}', name: 'dashboard_commande', methods: ['GET'])]
    public function getCommandeMetrics(Commande $commande): JsonResponse
    {
        $metricsDTO = $this->dashboardCommandeUseCase->execute($commande);
        return $this->json($metricsDTO);
    }
}
