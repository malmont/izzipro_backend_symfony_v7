<?php
namespace App\UseCase\CommandeDashboardUseCase;

use App\Entity\Commande;
use App\Services\CommandeDashboardService\CommandeStatistiquesService;
use App\Dto\DashboardCommandeDTO;
use App\Services\TenantEntityManagerProvider; 

class FreezeCommandeMetricsUseCase
{
    private CommandeStatistiquesService $commandeStatistiquesService;
    private DashboardCommandeUseCase $dashboardCommandeUseCase;
    private TenantEntityManagerProvider $emProvider; 

    public function __construct(
        CommandeStatistiquesService $commandeStatistiquesService,
        DashboardCommandeUseCase $dashboardCommandeUseCase,
        TenantEntityManagerProvider $emProvider
    ) {
        $this->commandeStatistiquesService = $commandeStatistiquesService;
        $this->dashboardCommandeUseCase = $dashboardCommandeUseCase;
        $this->emProvider = $emProvider;
    }

    public function execute(Commande $commande): void
    {
        if ($commande->getIsClosed()) {
            throw new \Exception('La commande est déjà figée.');
        }

        // Ces appels sont corrects, en supposant que les services appelés
        // ont aussi été migrés pour être "tenant-aware".
        $metricsDTO = $this->dashboardCommandeUseCase->execute($commande);
        $this->commandeStatistiquesService->createAndSaveMetrics($commande, $metricsDTO);

        // On récupère l'EM du tenant juste avant de l'utiliser
        $em = $this->emProvider->getEntityManager();

        // Marquer la commande comme fermée
        $commande->setIsClosed(true);

        // Garde-fou : on s'assure que l'entité Commande est bien gérée par l'EM actuel
        $em->persist($commande);
        
        // On utilise l'EM du tenant pour sauvegarder le changement
        $em->flush();
    }
}