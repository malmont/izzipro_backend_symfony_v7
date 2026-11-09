<?php

namespace App\UseCase\FraisUseCase;

use App\Services\FraisService\FraisService;
use DateTime;

class GetTotalFraisUseCase
{
    private $fraisService;

    public function __construct(FraisService $fraisService)
    {
        $this->fraisService = $fraisService;
    }

    public function execute(): array
    {
        // Année et semaine courantes du magasin (lot G3, 09/11/2026)
        $periods = $this->fraisService->currentPeriods();

        return [
            'total_frais_annee' => $this->fraisService->getTotalFraisForYear($periods['year']),
            'frais_par_mois' => $this->fraisService->getTotalFraisByMonth($periods['year']),
            'frais_par_jour' => $this->fraisService->getTotalFraisByDay($periods['weekStart'], $periods['weekEnd']),
        ];
    }
}
