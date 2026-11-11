<?php

namespace App\Controller\DashboardListCollectionController;

use App\Entity\Collections;
use App\UseCase\CollectionDashboardUseCase\DashboardCollectionUseCase;
use App\Dto\DashboardCollectionDTO;
use App\UseCase\CollectionDashboardUseCase\CloseCollectionUseCase;
use App\UseCase\CollectionDashboardUseCase\ReopenCollectionUseCase;
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
        TenantCacheService $cache,
        private readonly ?\App\Gestion\Services\PeriodCalendar $calendar = null, // périodes (lot P1, 10/11/2026)
        private readonly ?ReopenCollectionUseCase $reopenCollectionUseCase = null
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
        $closed = $this->calendar ? $this->calendar->isClosed($collection) : (bool) $collection->getIsClosed(); // période fermée par la date comprise (lot P1, 10/11/2026)
        $cacheKey = 'dashboard_collection_' . $collection->getId() . ($closed ? '_closed' : '_open');
        $ttl = $closed ? 3600 : 60;

        // tableau de bord d'une collection : ses commandes fournisseurs, notes de frais, produits et leurs ventes
        $data = $this->cache->get(
            $cacheKey,
            function () use ($collection, $closed) {
                if ($closed) {
                    // chiffres figés, calculés à la première lecture d'une période fermée par la date (lot P1)
                    return $this->getCollectionStatistiquesUseCase->executeOrFreeze($collection)->toArray();
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
    public function getCombinedCollectionsData(\Symfony\Component\HttpFoundation\Request $request): JsonResponse
    {
        // ?kind=manual (défaut : collections manuelles) | period (périodes du calendrier), jamais les deux ensemble (lot P1, 10/11/2026)
        $kind = strtolower((string) $request->query->get('kind', 'manual'));
        if (!in_array($kind, ['manual', 'period'], true)) {
            throw new \App\Gestion\Services\GestionValidationException(['kind' => 'INVALID_VALUE'], 'kind : manual ou period attendu');
        }
        if ($kind === 'period') {
            $this->calendar?->ensurePeriods();
        }
        // cache 60 s comme les autres tableaux de bord (lot G2, 09/11/2026) : suit les écritures des collections, commandes, notes de frais et produits
        $combinedData = $this->cache->get(
            'dashboard_collections_combined_' . $kind,
            fn () => $this->getCombinedCollectionsDataUseCase->execute($kind),
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

    /** Réouverture manuelle (lot P1, 10/11/2026) : 200 tableau de bord vivant ; 409 NOT_CLOSED si déjà ouverte */
    #[Route('/api/dashboard/collection/{id}/reopen', name: 'reopen_collection', methods: ['POST'])]
    public function reopenCollection(Collections $collection): JsonResponse
    {
        return new JsonResponse($this->reopenCollectionUseCase->execute($collection)->toArray(), JsonResponse::HTTP_OK);
    }
}
