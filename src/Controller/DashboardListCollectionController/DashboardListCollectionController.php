<?php

namespace App\Controller\DashboardListCollectionController;

use App\Entity\Collections;
use App\UseCase\CollectionDashboardUseCase\DashboardCollectionUseCase;
use App\Dto\DashboardCollectionDTO;
use App\UseCase\CollectionDashboardUseCase\CloseCollectionUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use App\UseCase\CollectionDashboardUseCase\GetCombinedCollectionsDataUseCase;
use App\UseCase\CollectionDashboardUseCase\GetCollectionStatistiquesUseCase;
use App\Services\CollectionDashboardService\CollectionStatistiquesService;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class DashboardListCollectionController extends AbstractController
{
    private DashboardCollectionUseCase $dashboardCollectionUseCase;
    private GetCombinedCollectionsDataUseCase $getCombinedCollectionsDataUseCase;
    private CloseCollectionUseCase $closeCollectionUseCase;
    private CollectionStatistiquesService $collectionStatistiquesService;
    private GetCollectionStatistiquesUseCase $getCollectionStatistiquesUseCase;
    private CacheInterface $cache;

    public function __construct(
        DashboardCollectionUseCase $dashboardCollectionUseCase,
        GetCombinedCollectionsDataUseCase $getCombinedCollectionsDataUseCase,
        CloseCollectionUseCase $closeCollectionUseCase,
        CollectionStatistiquesService $collectionStatistiquesService,
        GetCollectionStatistiquesUseCase $getCollectionStatistiquesUseCase,
        CacheInterface $cache
    ) {
        $this->dashboardCollectionUseCase = $dashboardCollectionUseCase;
        $this->getCombinedCollectionsDataUseCase = $getCombinedCollectionsDataUseCase;
        $this->closeCollectionUseCase = $closeCollectionUseCase;
        $this->collectionStatistiquesService = $collectionStatistiquesService;
        $this->getCollectionStatistiquesUseCase = $getCollectionStatistiquesUseCase;
        $this->cache = $cache;
    }

    #[Route('/api/dashboard/collection/{id}', name: 'dashboard_collection', methods: ['GET'])]
    public function dashboardCollection(Collections $collection): JsonResponse
    {
        // Construction d'une clé de cache basée sur l'ID de la collection et son statut (fermée ou non)
        $cacheKey = 'dashboard_collection_' . $collection->getId() . ($collection->getIsClosed() ? '_closed' : '_open');

        $data = $this->cache->get($cacheKey, function (ItemInterface $item) use ($collection) {
            // Pour une collection fermée, on peut allonger le TTL (par exemple, 1 heure), sinon 1 minute
            $ttl = $collection->getIsClosed() ? 3600 : 60;
            $item->expiresAfter($ttl);
            // Ajout du tag "dashboard_collection" pour faciliter l'invalidation
             $item->tag(['dashboard_collection']);

            if ($collection->getIsClosed()) {
                $frozenMetricsDTO = $this->getCollectionStatistiquesUseCase->execute($collection);
                if ($frozenMetricsDTO === null) {
                    throw new \RuntimeException('Aucune statistique figée trouvée pour cette collection');
                }
                return $frozenMetricsDTO->toArray();
            } else {
                $metricsDTO = $this->dashboardCollectionUseCase->execute($collection);
                // Supposons que $metricsDTO est déjà au format approprié ou possède une méthode toArray()
                return method_exists($metricsDTO, 'toArray') ? $metricsDTO->toArray() : $metricsDTO;
            }
        });

        return new JsonResponse($data, JsonResponse::HTTP_OK);
    }

    #[Route('/api/dashboard/collections-combined', name: 'dashboard_collections_combined', methods: ['GET'])]
    public function getCombinedCollectionsData(): JsonResponse
    {
        $combinedData = $this->getCombinedCollectionsDataUseCase->execute();
        return new JsonResponse($combinedData, JsonResponse::HTTP_OK);
    }

    #[Route('/api/dashboard/collection/{id}/close', name: 'close_collection', methods: ['POST'])]
    public function closeCollection(Collections $collection): JsonResponse
    {
        try {
            $this->closeCollectionUseCase->execute($collection);
            return new JsonResponse(['message' => 'La collection a été figée avec succès.'], JsonResponse::HTTP_OK);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
    }
}
