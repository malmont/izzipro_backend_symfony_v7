<?php
namespace App\Services\FraisService;

use App\Entity\FraisDePort;
use App\Entity\NoteDeFrais;
use App\Repository\FraisDePortRepository;
use App\Repository\NoteDeFraisRepository;
use App\Services\TenantEntityManagerProvider;
use DateTime;

class FraisService
{
    /**
     * MODIFICATION 1 : Le service ne dépend plus que du provider.
     */
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    /**
     * MODIFICATION 2 : On crée des méthodes privées pour récupérer les repositories du tenant.
     */
    private function getFraisDePortRepository(): FraisDePortRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(FraisDePort::class);
    }

    private function getNoteDeFraisRepository(): NoteDeFraisRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(NoteDeFrais::class);
    }
    
    // Récupérer le total des frais pour l'année sous forme d'objet structuré
    public function getTotalFraisForYear(int $year): array
    {
        // MODIFICATION 3 : On utilise nos nouvelles méthodes privées
        $noteDeFraisRepository = $this->getNoteDeFraisRepository();
        $fraisDePortRepository = $this->getFraisDePortRepository();

        $totalNoteDeFraisAnnee = $noteDeFraisRepository->getTotalFraisByYear($year);
        $totalFraisDePortAnnee = $fraisDePortRepository->getTotalFraisByYear($year);
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
        $noteDeFraisRepository = $this->getNoteDeFraisRepository();
        $fraisDePortRepository = $this->getFraisDePortRepository();
        $fraisParMois = [];
        $moisNoms = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
        ];

        foreach ($moisNoms as $month => $nom) {
            $totalNoteDeFrais = $noteDeFraisRepository->getTotalFraisByMonth($year, $month);
            $totalFraisDePort = $fraisDePortRepository->getTotalFraisByMonth($year, $month);
            $totalFrais = $totalNoteDeFrais + $totalFraisDePort;

            $fraisParMois[] = [
                'month' => $nom,
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
        $noteDeFraisRepository = $this->getNoteDeFraisRepository();
        $fraisDePortRepository = $this->getFraisDePortRepository();
        $fraisParJour = [];
        $currentDay = clone $weekStart;

        while ($currentDay <= $weekEnd) {
            $totalNoteDeFrais = $noteDeFraisRepository->getTotalFraisByDay($currentDay);
            $totalFraisDePort = $fraisDePortRepository->getTotalFraisByDay($currentDay);
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