<?php

namespace App\Controller\DashboardCommandeController;

use App\Entity\Commande;
use App\UseCase\CommandeDashboardUseCase\FreezeCommandeMetricsUseCase;
use App\UseCase\CommandeDashboardUseCase\DashboardCommandeUseCase;
use App\UseCase\CommandeDashboardUseCase\GetLatestCommandeStatistiquesUseCase;
use App\Dto\DashboardCommandeDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Cache\CacheTags;
use App\Services\TenantCacheService;

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
     */
    #[Route('/api/dashboard/commande/{id}/close', name: 'close_command', methods: ['POST'])]
    public function closeCommand(Commande $commande): JsonResponse
    {
        // 200 avec le tableau de bord figé, 409 ALREADY_CLOSED si déjà fermée (lot G2, 09/11/2026 ; avant : {"message"} / 400)
        return new JsonResponse($this->freezeCommandeMetricsUseCase->execute($commande)->toArray(), JsonResponse::HTTP_OK);
    }

    /**
     * Récupérer les métriques d'une commande pour le dashboard
     */
    #[Route('/api/dashboard/commande/{id}', name: 'dashboard_commande', methods: ['GET'])]
    public function getCommandeMetrics(Commande $commande): JsonResponse
    {
        $cacheKey = 'dashboard_commande_' . $commande->getId() . ($commande->getIsClosed() ? '_closed' : '_open');

        // tableau de bord d'une commande fournisseur : la commande, ses frais de port, ses produits et leurs ventes
        $data = $this->cache->get(
            $cacheKey,
            function () use ($commande) {
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
            $commande->getIsClosed() ? 3600 : 60,
            [...CacheTags::itemTags(CacheTags::COMMANDES, $commande->getId()), ...CacheTags::listTags(CacheTags::PRODUCTS), ...CacheTags::listTags(CacheTags::ORDERS)]
        );

        return new JsonResponse($data, JsonResponse::HTTP_OK);
    }
}
