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
        $currentYear = (new DateTime())->format('Y');
        $currentWeekStart = (new DateTime())->modify('monday this week');
        $currentWeekEnd = (new DateTime())->modify('sunday this week');

        $totalFraisAnnee = $this->fraisService->getTotalFraisForYear($currentYear);
        $fraisParMois = $this->fraisService->getTotalFraisByMonth($currentYear);
        $fraisParJour = $this->fraisService->getTotalFraisByDay($currentWeekStart, $currentWeekEnd);

        return [
            'total_frais_annee' => $totalFraisAnnee,
            'frais_par_mois' => $fraisParMois,
            'frais_par_jour' => $fraisParJour,
        ];
    }
}
