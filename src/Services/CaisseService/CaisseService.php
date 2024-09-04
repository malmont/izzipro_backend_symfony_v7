<?php
namespace App\Services\CaisseService;


use App\Entity\Caisse;
use App\Repository\CaisseRepository;

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
}