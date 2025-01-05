<?php
namespace App\Services\CaisseService;

use App\Entity\Caisse;
use App\Repository\CaisseRepository;
use App\Repository\TransactionCaisseRepository;
use DateTime;

class CaisseService
{
    private $caisseRepository;
    private $transactionCaisseRepository;

    public function __construct(CaisseRepository $caisseRepository, TransactionCaisseRepository $transactionCaisseRepository)
    {
        $this->caisseRepository = $caisseRepository;
        $this->transactionCaisseRepository = $transactionCaisseRepository;
    }

    public function getOpenCaisse(): ?Caisse
    {
        return $this->caisseRepository->findOneBy(['isOpen' => true]);
    }

    public function getLastClosedCaisse(): ?Caisse
    {
        return $this->caisseRepository->getLastClosedCaisse();
    }

    public function getCaisse(?int $days = null)
    {
        if ($days) {
            $date = new \DateTime();
            $date->modify("-$days days");

            return $this->caisseRepository->createQueryBuilder('o')
                ->where('o.createdAt >= :date')
                ->setParameter('date', $date)
                ->getQuery()
                ->getResult();
        }

        return $this->caisseRepository->findAll();
    }

    public function getTransactionsForOpenCaisse(): array
    {
        $openCaisse = $this->getOpenCaisse();
        if ($openCaisse) {
            return $this->transactionCaisseRepository->findBy(['caisse' => $openCaisse]);
        }
        return [];
    }
}
