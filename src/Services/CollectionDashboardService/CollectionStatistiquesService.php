<?php

namespace App\Services\CollectionDashboardService;

use App\Entity\Collections;
use App\Entity\CollectionStatistiques;
use App\Dto\DashboardCollectionDTO;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\CollectionStatistiquesRepository;

class CollectionStatistiquesService
{
    private EntityManagerInterface $entityManager;
    private CollectionStatistiquesRepository $collectionStatistiquesRepository;

    public function __construct(EntityManagerInterface $entityManager, CollectionStatistiquesRepository $collectionStatistiquesRepository)
    
    {
        $this->entityManager = $entityManager;
        $this->collectionStatistiquesRepository = $collectionStatistiquesRepository;
    }

    public function getLatestStatistiqueForCollection(Collections $collection): ?CollectionStatistiques
    {
        return $this->collectionStatistiquesRepository->findLatestByCollection($collection);
    }


    public function createAndSaveMetrics(Collections $collection, DashboardCollectionDTO $metricsDTO): CollectionStatistiques
    {
        $collectionStatistiques = new CollectionStatistiques();
        $collectionStatistiques->setCollection($collection);
        $collectionStatistiques->setGeneralBudget($metricsDTO->budgetGeneral['generalBudget']);
        $collectionStatistiques->setUsedBudget($metricsDTO->budgetGeneral['usedBudget']);
        $collectionStatistiques->setRemainingBudget($metricsDTO->budgetGeneral['remainingBudget']);
        $collectionStatistiques->setTotalItemCost($metricsDTO->totalItemCost);
        $collectionStatistiques->setTotalShippingCost($metricsDTO->generalExpenses['totalShippingCost']);
        $collectionStatistiques->setTotalExpenseCost($metricsDTO->generalExpenses['totalExpenseCost']);
        $collectionStatistiques->setOrderCount($metricsDTO->statistics['orderCount']);
        $collectionStatistiques->setItemCount($metricsDTO->statistics['itemCount']);
        $collectionStatistiques->setModelCount($metricsDTO->statistics['modelCount']);
        $collectionStatistiques->setStockValue($metricsDTO->valeurStock['stockValue']);
        $collectionStatistiques->setMargin($metricsDTO->valeurStock['marge']);
        $collectionStatistiques->setTauxMarge($metricsDTO->tauxMarge['tauxMarge']);
        $collectionStatistiques->setTauxMarque($metricsDTO->tauxMarge['tauxMarque']);
        $collectionStatistiques->setAverageMultiplier($metricsDTO->averageMultiplier);
        $collectionStatistiques->setStartDate(new \DateTime($metricsDTO->collectionDuration['startDate']));
        $collectionStatistiques->setEndDate(new \DateTime($metricsDTO->collectionDuration['endDate']));
        $collectionStatistiques->setDurationDays($metricsDTO->collectionDuration['days']);
    
        $this->entityManager->persist($collectionStatistiques);
        $this->entityManager->flush();
    
        return $collectionStatistiques;
    }
    
}
