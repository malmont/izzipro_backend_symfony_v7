<?php
namespace App\Controller\DashboardListCollectionController;

use App\Entity\Collections;
use App\UseCase\CollectionDashboardUseCase\DashboardCollectionUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use App\UseCase\CollectionDashboardUseCase\GetCombinedCollectionsDataUseCase;

class DashboardListCollectionController extends AbstractController
{
    private GetCombinedCollectionsDataUseCase $getCombinedCollectionsDataUseCase;
    private DashboardCollectionUseCase $dashboardCollectionUseCase;

    public function __construct(DashboardCollectionUseCase $dashboardCollectionUseCase,GetCombinedCollectionsDataUseCase $getCombinedCollectionsDataUseCase)
    {
        $this->dashboardCollectionUseCase = $dashboardCollectionUseCase;
        $this->getCombinedCollectionsDataUseCase = $getCombinedCollectionsDataUseCase;
    }

    #[Route('/api/dashboard/collection/{id}', name: 'dashboard_collection', methods: ['GET'])]
    public function dashboardCollection(Collections $collection): JsonResponse
    {
        $metricsDTO = $this->dashboardCollectionUseCase->execute($collection);
        return $this->json($metricsDTO);
    }

     /**
     * @Route("/api/dashboard/collections-combined", name="dashboard_collections_combined", methods={"GET"})
     */
    public function getCombinedCollectionsData(): JsonResponse
    {
        $combinedData = $this->getCombinedCollectionsDataUseCase->execute();
        return $this->json($combinedData);
    }
}
