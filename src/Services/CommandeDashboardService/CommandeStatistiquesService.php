<?php

namespace App\Services\CommandeDashboardService;

use App\Entity\Commande;
use App\Entity\CommandeStatistiques;
use App\Dto\DashboardCommandeDTO;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\CommandeStatistiquesRepository;

class CommandeStatistiquesService
{
    private EntityManagerInterface $entityManager;
    private CommandeStatistiquesRepository $commandeStatistiquesRepository;

    public function __construct(EntityManagerInterface $entityManager,CommandeStatistiquesRepository $commandeStatistiquesRepository)
    {
        $this->entityManager = $entityManager;
        $this->commandeStatistiquesRepository = $commandeStatistiquesRepository;
    }

    public function getLatestCommandeStatistiques(Commande $commande): ?CommandeStatistiques
    {
        return $this->commandeStatistiquesRepository->findLatestByCommande($commande);
    }

    public function createAndSaveMetrics(Commande $commande, DashboardCommandeDTO $metricsDTO): CommandeStatistiques
    {
        $commandeStatistiques = new CommandeStatistiques();
        $commandeStatistiques->setCommande($commande);
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

        if ($commande->getFraisDePort() && $commande->getFraisDePort()->getTransporteur()) {
            $commandeStatistiques->setTransporteur($commande->getFraisDePort()->getTransporteur());
        }

        $this->entityManager->persist($commandeStatistiques);
        $this->entityManager->flush();

        return $commandeStatistiques;
    }
}
