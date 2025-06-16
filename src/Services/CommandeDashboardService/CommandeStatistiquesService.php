<?php
namespace App\Services\CommandeDashboardService;

use App\Dto\DashboardCommandeDTO;
use App\Entity\Commande;
use App\Entity\CommandeStatistiques;
use App\Services\TenantEntityManagerProvider; 

class CommandeStatistiquesService
{

    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getLatestCommandeStatistiques(Commande $commande): ?CommandeStatistiques
    {
        // MODIFICATION 2 : On récupère l'EM et le repository ici
        $em = $this->emProvider->getEntityManager();
        $commandeStatistiquesRepository = $em->getRepository(CommandeStatistiques::class);
        
        return $commandeStatistiquesRepository->findLatestByCommande($commande);
    }

    public function createAndSaveMetrics(Commande $commande, DashboardCommandeDTO $metricsDTO): CommandeStatistiques
    {
        $em = $this->emProvider->getEntityManager();

        $commandeStatistiques = new CommandeStatistiques();
        $commandeStatistiques->setCommande($commande);
        
        // ... (toute votre logique de set... est inchangée)
        $commandeStatistiques->setAverageMultiplier($metricsDTO->averageMultiplier);
        $commandeStatistiques->setGeneralBudget($metricsDTO->budgetGeneral['generalBudget']);
        $commandeStatistiques->setUsedBudget($metricsDTO->budgetGeneral['usedBudget']);
        $commandeStatistiques->setRemainingBudget($metricsDTO->budgetGeneral['remainingBudget']);
        $commandeStatistiques->setTotalItemCost($metricsDTO->totalItemCost);
        $commandeStatistiques->setTotalFraisDePort($metricsDTO->totalFraisDePort);
        $commandeStatistiques->setItemCount($metricsDTO->statistics['itemCount']);
        $commandeStatistiques->setModelCount($metricsDTO->statistics['modelCount']);
        $commandeStatistiques->setStockValue($metricsDTO->valeurStock['stockValue']);
        $commandeStatistiques->setMarge($metricsDTO->valeurStock['marge']);
        $commandeStatistiques->setTauxMarge($metricsDTO->tauxMarge['tauxMarge']);
        $commandeStatistiques->setTauxMarque($metricsDTO->tauxMarge['tauxMarque']);

        $transporteur = $commande->getFraisDePort()?->getTransporteur();
        if ($transporteur) {
            $commandeStatistiques->setTransporteur($transporteur);
        }

        $em->persist($commandeStatistiques);
        $em->flush();

        return $commandeStatistiques;
    }
}