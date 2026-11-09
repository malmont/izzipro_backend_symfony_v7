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
    private DashboardCollectionUseCase $dashboardCollectionUseCase;
    private TenantEntityManagerProvider $emProvider;

    public function __construct(
        CollectionStatistiquesService $collectionStatistiquesService,
        CommandeStatistiquesService $commandeStatistiquesService,
        DashboardCommandeUseCase $dashboardCommandeUseCase,
        DashboardCollectionUseCase $dashboardCollectionUseCase, // manquait jusqu'au 09/11/2026 : la clôture d'une collection échouait toujours (propriété indéfinie)
        TenantEntityManagerProvider $emProvider,
        private readonly ?\App\Services\GestionService\PeriodCalendar $calendar = null // périodes (lot P1, 10/11/2026)
    ) {
        $this->collectionStatistiquesService = $collectionStatistiquesService;
        $this->commandeStatistiquesService = $commandeStatistiquesService;
        $this->dashboardCommandeUseCase = $dashboardCommandeUseCase;
        $this->dashboardCollectionUseCase = $dashboardCollectionUseCase;
        $this->emProvider = $emProvider;
    }

    public function execute(Collections $collection): \App\Dto\DashboardCollectionDTO
    {
        if ($this->calendar ? $this->calendar->isClosed($collection) : $collection->getIsClosed()) {
            throw new \App\Services\GestionService\GestionConflictException('ALREADY_CLOSED', 'La collection est déjà figée.'); // 409 (lot G2, 09/11/2026 ; période fermée par la date : lot P1)
        }
        
        // On récupère l'EM du tenant une seule fois au début de l'opération
        $em = $this->emProvider->getEntityManager();

        // Les appels aux autres services sont corrects
        $metricsDTO = $this->dashboardCollectionUseCase->execute($collection);
        $this->collectionStatistiquesService->createAndSaveMetrics($collection, $metricsDTO);

        // période : ses commandes sont celles datées dedans ; collection manuelle : celles rattachées (lot P1, 10/11/2026)
        $commandes = $collection->isPeriod()
            ? $em->getRepository(\App\Entity\Commande::class)->createQueryBuilder('c')->where('c.date >= :from AND c.date < :to')->setParameter('from', $collection->getStartDateCollection()->format('Y-m-d 00:00:00'))->setParameter('to', \DateTimeImmutable::createFromInterface($collection->getEndDateCollection())->modify('+1 day')->format('Y-m-d 00:00:00'))->getQuery()->getResult()
            : $collection->getCommandes();
        foreach ($commandes as $commande) {
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

        $collection->setIsClosed(true)->setClosedAt(new \DateTime())->setReopenedAt(null); // clôture manuelle datée (lot P1)
        // On utilise l'EM du tenant
        $em->persist($collection);

        // Le flush final sauvegarde toutes les modifications dans la BDD du tenant
        $em->flush();

        return $metricsDTO; // chiffres figés rendus par la route (lot G2)
    }
}