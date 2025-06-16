<?php
namespace App\Services\CollectionDashboardService;

use App\Entity\Collections;
use App\Entity\CollectionStatistiques;
use App\Dto\DashboardCollectionDTO;
use App\Services\TenantEntityManagerProvider; 

class CollectionStatistiquesService
{
    // MODIFICATION 1 : Le service ne dépend plus que du provider
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getLatestStatistiqueForCollection(Collections $collection): ?CollectionStatistiques
    {

        $em = $this->emProvider->getEntityManager();
        $repo = $em->getRepository(CollectionStatistiques::class);

        return $repo->findLatestByCollection($collection);
    }

    public function createAndSaveMetrics(Collections $collection, DashboardCollectionDTO $metricsDTO): CollectionStatistiques
    {
        $em = $this->emProvider->getEntityManager();

        $collectionStatistiques = new CollectionStatistiques();
        $collectionStatistiques->setCollection($collection);
        
        // ... (toute votre logique de set... est inchangée)
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
    
       
        $em->persist($collectionStatistiques);
        $em->flush();
    
        return $collectionStatistiques;
    }
}