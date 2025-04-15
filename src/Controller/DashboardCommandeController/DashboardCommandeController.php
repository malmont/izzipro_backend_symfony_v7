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
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class DashboardCommandeController extends AbstractController
{
    private FreezeCommandeMetricsUseCase $freezeCommandeMetricsUseCase;
    private DashboardCommandeUseCase $dashboardCommandeUseCase;
    private GetLatestCommandeStatistiquesUseCase $getLatestCommandeStatistiquesUseCase;
    private CacheInterface $cache;

    public function __construct(
        FreezeCommandeMetricsUseCase $freezeCommandeMetricsUseCase,
        DashboardCommandeUseCase $dashboardCommandeUseCase,
        GetLatestCommandeStatistiquesUseCase $getLatestCommandeStatistiquesUseCase,
        CacheInterface $cache
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
        // On construit une clé de cache basée sur l'ID de la commande et son statut (fermée ou ouverte)
        $cacheKey = 'dashboard_commande_' . $commande->getId() . ($commande->getIsClosed() ? '_closed' : '_open');

        // Utilisation du cache avec un TTL adapté et ajout du tag "dashboard_commande"
        $data = $this->cache->get($cacheKey, function (ItemInterface $item) use ($commande) {
            // Définir le TTL : 1 heure si la commande est fermée, 1 minute sinon
            $ttl = $commande->getIsClosed() ? 3600 : 60;
            $item->expiresAfter($ttl);
            // Ajouter le tag pour pouvoir invalider toutes les métriques du dashboard
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
        });

        return new JsonResponse($data);
    }
}
