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


class DashboardListCollectionController extends AbstractController
{
    private GetCombinedCollectionsDataUseCase $getCombinedCollectionsDataUseCase;
    private CollectionStatistiquesService $collectionStatistiquesService;
    private DashboardCollectionUseCase $dashboardCollectionUseCase;
    private CloseCollectionUseCase $closeCollectionUseCase;
    private GetCollectionStatistiquesUseCase $getCollectionStatistiquesUseCase;
    public function __construct(DashboardCollectionUseCase $dashboardCollectionUseCase,GetCombinedCollectionsDataUseCase $getCombinedCollectionsDataUseCase,
     CloseCollectionUseCase $closeCollectionUseCase,
     CollectionStatistiquesService $collectionStatistiquesService,
     GetCollectionStatistiquesUseCase $getCollectionStatistiquesUseCase)
    {
        $this->dashboardCollectionUseCase = $dashboardCollectionUseCase;
        $this->getCombinedCollectionsDataUseCase = $getCombinedCollectionsDataUseCase;
        $this->closeCollectionUseCase = $closeCollectionUseCase;
        $this->collectionStatistiquesService = $collectionStatistiquesService;
        $this->getCollectionStatistiquesUseCase = $getCollectionStatistiquesUseCase;
    }

    #[Route('/api/dashboard/collection/{id}', name: 'dashboard_collection', methods: ['GET'])]
    public function dashboardCollection(Collections $collection): JsonResponse {
        if ($collection->getIsClosed()) {
            $frozenMetricsDTO = $this->getCollectionStatistiquesUseCase->execute($collection);
    
            if ($frozenMetricsDTO === null) {
                return $this->json(['error' => 'Aucune statistique figée trouvée pour cette collection'], 404);
            }
    
            return $this->json($frozenMetricsDTO->toArray());
        } else {
            $metricsDTO = $this->dashboardCollectionUseCase->execute($collection);
            return $this->json($metricsDTO);
        }
    }
    

     /**
     * @Route("/api/dashboard/collections-combined", name="dashboard_collections_combined", methods={"GET"})
     */
    public function getCombinedCollectionsData(): JsonResponse
    {
        $combinedData = $this->getCombinedCollectionsDataUseCase->execute();
        return $this->json($combinedData);
    }

    #[Route('/api/dashboard/collection/{id}/close', name: 'close_collection', methods: ['POST'])]
    public function closeCollection(Collections $collection): JsonResponse
        {
            try {
                $this->closeCollectionUseCase->execute($collection);
                return new JsonResponse(['message' => 'La collection a été figée avec succès.']);
            } catch (\Exception $e) {
                // Gère les exceptions et retourne un message d'erreur avec le statut HTTP 400
                return new JsonResponse(['error' => $e->getMessage()], 400);
            }
        }
}
