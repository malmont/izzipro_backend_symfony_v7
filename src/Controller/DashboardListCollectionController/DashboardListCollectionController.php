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
use App\Cache\CacheTags;
use App\Services\TenantCacheService;

class DashboardListCollectionController extends AbstractController
{
    private DashboardCollectionUseCase $dashboardCollectionUseCase;
    private GetCombinedCollectionsDataUseCase $getCombinedCollectionsDataUseCase;
    private CloseCollectionUseCase $closeCollectionUseCase;
    private CollectionStatistiquesService $collectionStatistiquesService;
    private GetCollectionStatistiquesUseCase $getCollectionStatistiquesUseCase;
    private TenantCacheService $cache;

    public function __construct(
        DashboardCollectionUseCase $dashboardCollectionUseCase,
        GetCombinedCollectionsDataUseCase $getCombinedCollectionsDataUseCase,
        CloseCollectionUseCase $closeCollectionUseCase,
        CollectionStatistiquesService $collectionStatistiquesService,
        GetCollectionStatistiquesUseCase $getCollectionStatistiquesUseCase,
        TenantCacheService $cache
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
        $cacheKey = 'dashboard_collection_' . $collection->getId() . ($collection->getIsClosed() ? '_closed' : '_open');
        $ttl = $collection->getIsClosed() ? 3600 : 60;

        // tableau de bord d'une collection : ses commandes fournisseurs, notes de frais, produits et leurs ventes
        $data = $this->cache->get(
            $cacheKey,
            function () use ($collection) {
                if ($collection->getIsClosed()) {
                    $frozenMetricsDTO = $this->getCollectionStatistiquesUseCase->execute($collection);
                    if ($frozenMetricsDTO === null) {
                        throw new \RuntimeException('Aucune statistique figée trouvée pour cette collection');
                    }
                    return $frozenMetricsDTO->toArray();
                } else {
                    $metricsDTO = $this->dashboardCollectionUseCase->execute($collection);
                    return method_exists($metricsDTO, 'toArray') ? $metricsDTO->toArray() : $metricsDTO;
                }
            },
            $ttl,
            [...CacheTags::itemTags(CacheTags::COLLECTIONS, $collection->getId()), ...CacheTags::listTags(CacheTags::COMMANDES),
                ...CacheTags::listTags(CacheTags::NOTES_DE_FRAIS), ...CacheTags::listTags(CacheTags::PRODUCTS), ...CacheTags::listTags(CacheTags::ORDERS)]
        );

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
