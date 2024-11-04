<?php

namespace App\Controller\DashboardCommandeController;

use App\Entity\Commande;
use App\UseCase\CommandeDashboardUseCase\FreezeCommandeMetricsUseCase;
use App\UseCase\CommandeDashboardUseCase\DashboardCommandeUseCase;
use App\UseCase\CommandeDashboardUseCase\GetLatestCommandeStatistiquesUseCase;
use App\Dto\DashboardCommandeDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class DashboardCommandeController extends AbstractController
{
    private FreezeCommandeMetricsUseCase $freezeCommandeMetricsUseCase;
    private DashboardCommandeUseCase $dashboardCommandeUseCase;
    private GetLatestCommandeStatistiquesUseCase $getLatestCommandeStatistiquesUseCase;

    public function __construct(
        FreezeCommandeMetricsUseCase $freezeCommandeMetricsUseCase,
        DashboardCommandeUseCase $dashboardCommandeUseCase,
        GetLatestCommandeStatistiquesUseCase $getLatestCommandeStatistiquesUseCase
    ) {
        $this->freezeCommandeMetricsUseCase = $freezeCommandeMetricsUseCase;
        $this->dashboardCommandeUseCase = $dashboardCommandeUseCase;
        $this->getLatestCommandeStatistiquesUseCase = $getLatestCommandeStatistiquesUseCase;
    }

    /**
     * @Route("/api/dashboard/commande/{id}/close", name="close_command", methods={"POST"})
     */
    public function closeCommand(Commande $commande): JsonResponse
    {
        try {
            $this->freezeCommandeMetricsUseCase->execute($commande);
            return new JsonResponse(['message' => 'La commande a été figée avec succès.']);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/api/dashboard/commande/{id}', name: 'dashboard_commande', methods: ['GET'])]
    public function getCommandeMetrics(Commande $commande): JsonResponse
    {
        if ($commande->getIsClosed()) {
            $frozenMetrics = $this->getLatestCommandeStatistiquesUseCase->execute($commande);

            if (!$frozenMetrics) {
                return $this->json(['error' => 'Aucune statistique figée trouvée pour cette commande'], 404);
            }

            $metricsDTO = DashboardCommandeDTO::fromEntity($frozenMetrics);
            return $this->json($metricsDTO->toArray());
        } else {
            $metricsDTO = $this->dashboardCommandeUseCase->execute($commande);
            return $this->json($metricsDTO->toArray());
        }
    }
}
