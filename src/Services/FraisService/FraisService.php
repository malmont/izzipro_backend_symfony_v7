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

    // Récupérer le total des frais pour l'année sous forme d'objet structuré
    public function getTotalFraisForYear(int $year): array
    {
        $totalNoteDeFraisAnnee = $this->noteDeFraisRepository->getTotalFraisByYear($year);
        $totalFraisDePortAnnee = $this->fraisDePortRepository->getTotalFraisByYear($year);
        $totalFraisAnnee = $totalNoteDeFraisAnnee + $totalFraisDePortAnnee;

        return [
            'year' => $year,
            'total_frais' => [
                'total' => $totalFraisAnnee,
                'note_de_frais' => $totalNoteDeFraisAnnee,
                'frais_de_port' => $totalFraisDePortAnnee,
            ],
        ];
    }

    // Récupérer le total des frais pour chaque mois de l'année sous forme d'objet structuré
    public function getTotalFraisByMonth(int $year): array
    {
        $fraisParMois = [];

        for ($month = 1; $month <= 12; $month++) {
            $totalNoteDeFrais = $this->noteDeFraisRepository->getTotalFraisByMonth($year, $month);
            $totalFraisDePort = $this->fraisDePortRepository->getTotalFraisByMonth($year, $month);
            $totalFrais = $totalNoteDeFrais + $totalFraisDePort;

            $fraisParMois[] = [
                'month' => DateTime::createFromFormat('!m', $month)->format('F'),
                'total_frais' => [
                    'total' => $totalFrais,
                    'note_de_frais' => $totalNoteDeFrais,
                    'frais_de_port' => $totalFraisDePort,
                ]
            ];
        }

        return $fraisParMois;
    }

    // Récupérer le total des frais pour chaque jour d'une semaine sous forme d'objet structuré
    public function getTotalFraisByDay(DateTime $weekStart, DateTime $weekEnd): array
    {
        $fraisParJour = [];
        $currentDay = clone $weekStart;

        while ($currentDay <= $weekEnd) {
            $totalNoteDeFrais = $this->noteDeFraisRepository->getTotalFraisByDay($currentDay);
            $totalFraisDePort = $this->fraisDePortRepository->getTotalFraisByDay($currentDay);
            $totalFrais = $totalNoteDeFrais + $totalFraisDePort;

            $fraisParJour[] = [
                'date' => $currentDay->format('Y-m-d'),
                'total_frais' => [
                    'total' => $totalFrais,
                    'note_de_frais' => $totalNoteDeFrais,
                    'frais_de_port' => $totalFraisDePort,
                ]
            ];

            $currentDay->modify('+1 day');
        }

        return $fraisParJour;
    }
}
