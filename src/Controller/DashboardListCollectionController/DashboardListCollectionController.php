<?php
namespace App\Controller\DashboardListCollectionController;

use App\Entity\Collections;
use App\UseCase\CollectionDashboardUseCase\DashboardCollectionUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class DashboardListCollectionController extends AbstractController
{
    private DashboardCollectionUseCase $dashboardCollectionUseCase;

    public function __construct(DashboardCollectionUseCase $dashboardCollectionUseCase)
    {
        $this->dashboardCollectionUseCase = $dashboardCollectionUseCase;
    }

    #[Route('/api/dashboard/collection/{id}', name: 'dashboard_collection', methods: ['GET'])]
    public function dashboardCollection(Collections $collection): JsonResponse
    {
        $metricsDTO = $this->dashboardCollectionUseCase->execute($collection);
        return $this->json($metricsDTO);
    }
}
