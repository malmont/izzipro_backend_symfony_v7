<?php

namespace App\Services\FraisService;

use App\Repository\FraisDePortRepository;
use App\Repository\NoteDeFraisRepository;
use DateTime;

class FraisService
{
    private $fraisDePortRepository;
    private $noteDeFraisRepository;

    public function __construct(FraisDePortRepository $fraisDePortRepository, NoteDeFraisRepository $noteDeFraisRepository)
    {
        $this->fraisDePortRepository = $fraisDePortRepository;
        $this->noteDeFraisRepository = $noteDeFraisRepository;
    }

    public function getTotalFraisForYear(int $year): array
    {
        $totalNoteDeFraisAnnee = $this->noteDeFraisRepository->getTotalFraisByYear($year);
        $totalFraisDePortAnnee = $this->fraisDePortRepository->getTotalFraisByYear($year);
        $totalFraisAnnee = $totalNoteDeFraisAnnee + $totalFraisDePortAnnee;

        return [
            'total_frais_annee' => $totalFraisAnnee,
            'total_Note_de_frais_annee' => $totalNoteDeFraisAnnee,
            'total_fraisdeport_annee' => $totalFraisDePortAnnee,
        ];
    }

    public function getTotalFraisByMonth(int $year): array
    {
        $fraisParMois = [];
        $noteDeFraisParMois = [];
        $fraisDePortParMois = [];

        for ($month = 1; $month <= 12; $month++) {
            $totalNoteDeFrais = $this->noteDeFraisRepository->getTotalFraisByMonth($year, $month);
            $totalFraisDePort = $this->fraisDePortRepository->getTotalFraisByMonth($year, $month);
            $fraisParMois[$month] = $totalNoteDeFrais + $totalFraisDePort;
            $noteDeFraisParMois[$month] = $totalNoteDeFrais;
            $fraisDePortParMois[$month] = $totalFraisDePort;
        }

        return [
            'frais_par_mois' => $fraisParMois,
            'notedefrais_par_mois' => $noteDeFraisParMois,
            'frais_de_port_par_mois' => $fraisDePortParMois,
        ];
    }

    public function getTotalFraisByDay(DateTime $weekStart, DateTime $weekEnd): array
    {
        $fraisParJour = [];
        $noteDeFraisParJour = [];
        $fraisDePortParJour = [];
        $currentDay = clone $weekStart;

        while ($currentDay <= $weekEnd) {
            $totalNoteDeFrais = $this->noteDeFraisRepository->getTotalFraisByDay($currentDay);
            $totalFraisDePort = $this->fraisDePortRepository->getTotalFraisByDay($currentDay);
            $dateFormatted = $currentDay->format('Y-m-d');

            $fraisParJour[$dateFormatted] = $totalNoteDeFrais + $totalFraisDePort;
            $noteDeFraisParJour[$dateFormatted] = $totalNoteDeFrais;
            $fraisDePortParJour[$dateFormatted] = $totalFraisDePort;

            $currentDay->modify('+1 day');
        }

        return [
            'frais_par_jour' => $fraisParJour,
            'notedefrais_par_jour' => $noteDeFraisParJour,
            'frais_de_port_par_jour' => $fraisDePortParJour,
        ];
    }
}
