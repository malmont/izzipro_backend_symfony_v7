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
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;

class DashboardCommandeController extends AbstractController
{
    private FreezeCommandeMetricsUseCase $freezeCommandeMetricsUseCase;
    private DashboardCommandeUseCase $dashboardCommandeUseCase;
    private GetLatestCommandeStatistiquesUseCase $getLatestCommandeStatistiquesUseCase;
    private TenantCacheService $cache;

    public function __construct(
        FreezeCommandeMetricsUseCase $freezeCommandeMetricsUseCase,
        DashboardCommandeUseCase $dashboardCommandeUseCase,
        GetLatestCommandeStatistiquesUseCase $getLatestCommandeStatistiquesUseCase,
        TenantCacheService $cache
    ) {
        $this->freezeCommandeMetricsUseCase = $freezeCommandeMetricsUseCase;
        $this->dashboardCommandeUseCase = $dashboardCommandeUseCase;
        $this->getLatestCommandeStatistiquesUseCase = $getLatestCommandeStatistiquesUseCase;
        $this->cache = $cache;
    }

    /**
     * Fermer une commande
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

    /**
     * Récupérer les métriques d'une commande pour le dashboard
     * @Route("/api/dashboard/commande/{id}", name="dashboard_commande", methods={"GET"})
     */
    public function getCommandeMetrics(Commande $commande): JsonResponse
    {
        $cacheKey = 'dashboard_commande_' . $commande->getId() . ($commande->getIsClosed() ? '_closed' : '_open');

        $data = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($commande) {
                $ttl = $commande->getIsClosed() ? 3600 : 60;
                $item->expiresAfter($ttl);
                $item->tag(['dashboard_commande']);
                if ($commande->getIsClosed()) {
                    $frozenMetrics = $this->getLatestCommandeStatistiquesUseCase->execute($commande);
                    if (!$frozenMetrics) {
                        throw new \RuntimeException('Aucune statistique figée trouvée pour cette commande');
                    }
                    $metricsDTO = DashboardCommandeDTO::fromEntity($frozenMetrics);
                } else {
                    $metricsDTO = $this->dashboardCommandeUseCase->execute($commande);
                }
                return $metricsDTO->toArray();
            },
        );

        return new JsonResponse($data, JsonResponse::HTTP_OK);
    }
}
