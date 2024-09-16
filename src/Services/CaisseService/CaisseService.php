<?php
namespace App\Services\CaisseService;


use App\Entity\Caisse;
use App\Repository\CaisseRepository;
use DateTime;


class CaisseService
{
    private $caisseRepository;

    public function __construct(CaisseRepository $caisseRepository)
    {
        $this->caisseRepository = $caisseRepository;
    }

    public function getOpenCaisse(): ?Caisse
    {
        return $this->caisseRepository->findOneBy(['isOpen' => true]);
    }

    public function getLastClosedCaisse(): ?Caisse
    {
        return $this->caisseRepository->findOneBy(['isOpen' => false], ['createdAt' => 'DESC']);
    }
    public function getCaisse(?int $days = null)
    {
        if ($days) {
            $date = new \DateTime();
            $date->modify("-$days days");

            return $this->caisseRepository->createQueryBuilder('o')
                ->where('o.createdAt >= :date') // Filtres par date de création
                ->setParameter('date', $date)
                ->getQuery()
                ->getResult();
        }

        // Retourne toutes les caisses si aucun jour n'est fourni
        return $this->caisseRepository->findAll();
    }

}