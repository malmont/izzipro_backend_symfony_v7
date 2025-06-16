<?php
namespace App\UseCase\CollectionDashboardUseCase;

use App\Entity\Collections;
use App\Services\CollectionDashboardService\CollectionStatistiquesService;
use App\Services\CommandeDashboardService\CommandeStatistiquesService;
use App\UseCase\CommandeDashboardUseCase\DashboardCommandeUseCase; 
use App\Services\TenantEntityManagerProvider; 

class CloseCollectionUseCase
{
    private CollectionStatistiquesService $collectionStatistiquesService;
    private CommandeStatistiquesService $commandeStatistiquesService;
    private DashboardCommandeUseCase $dashboardCommandeUseCase;
    private TenantEntityManagerProvider $emProvider; 

    public function __construct(
        CollectionStatistiquesService $collectionStatistiquesService,
        CommandeStatistiquesService $commandeStatistiquesService,
        DashboardCommandeUseCase $dashboardCommandeUseCase,
        TenantEntityManagerProvider $emProvider 
    ) {
        $this->collectionStatistiquesService = $collectionStatistiquesService;
        $this->commandeStatistiquesService = $commandeStatistiquesService;
        $this->dashboardCommandeUseCase = $dashboardCommandeUseCase;
        $this->emProvider = $emProvider;
    }

    public function execute(Collections $collection): void
    {
        if ($collection->getIsClosed()) {
            throw new \Exception('La collection est déjà figée.');
        }
        
        // On récupère l'EM du tenant une seule fois au début de l'opération
        $em = $this->emProvider->getEntityManager();

        // Les appels aux autres services sont corrects
        $metricsDTO = $this->dashboardCollectionUseCase->execute($collection);
        $this->collectionStatistiquesService->createAndSaveMetrics($collection, $metricsDTO);

        foreach ($collection->getCommandes() as $commande) {
            if (!$commande->getIsClosed()) {
                $commandeMetricsDTO = $this->dashboardCommandeUseCase->execute($commande);
                $this->commandeStatistiquesService->createAndSaveMetrics($commande, $commandeMetricsDTO);
                
                $commande->setIsClosed(true);
                // On utilise l'EM du tenant
                $em->persist($commande);

                foreach ($commande->getProducts() as $product) {
                    $product->setFreezeQuantity($product->getQuantity());
                    // On utilise l'EM du tenant
                    $em->persist($product);
                }
            }
        }

        $collection->setIsClosed(true);
        // On utilise l'EM du tenant
        $em->persist($collection);

        // Le flush final sauvegarde toutes les modifications dans la BDD du tenant
        $em->flush();
    }
}