<?php

namespace App\UseCase\CommandeDashboardUseCase;

use App\Entity\Commande;
use App\Entity\CommandeStatistiques;
use App\Services\CommandeDashboardService\CommandeStatistiquesService;

class GetLatestCommandeStatistiquesUseCase
{
    private CommandeStatistiquesService $commandeStatistiquesService;

    public function __construct(CommandeStatistiquesService $commandeStatistiquesService)
    {
        $this->commandeStatistiquesService = $commandeStatistiquesService;
    }

    public function execute(Commande $commande): ?CommandeStatistiques
    {
        return $this->commandeStatistiquesService->getLatestCommandeStatistiques($commande);
    }
}
