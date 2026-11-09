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
        // cache 60 s comme les autres tableaux de bord (lot G2, 09/11/2026) : suit les écritures des collections, commandes, notes de frais et produits
        $combinedData = $this->cache->get(
            'dashboard_collections_combined',
            fn () => $this->getCombinedCollectionsDataUseCase->execute(),
            60,
            [...CacheTags::listTags(CacheTags::COLLECTIONS), ...CacheTags::listTags(CacheTags::COMMANDES), ...CacheTags::listTags(CacheTags::NOTES_DE_FRAIS), ...CacheTags::listTags(CacheTags::PRODUCTS)]
        );
        return new JsonResponse($combinedData, JsonResponse::HTTP_OK);
    }

    #[Route('/api/dashboard/collection/{id}/close', name: 'close_collection', methods: ['POST'])]
    public function closeCollection(Collections $collection): JsonResponse
    {
        // 200 avec le tableau de bord figé, 409 ALREADY_CLOSED si déjà fermée (lot G2, 09/11/2026 ; avant : {"message"} / 400)
        return new JsonResponse($this->closeCollectionUseCase->execute($collection)->toArray(), JsonResponse::HTTP_OK);
    }
}
