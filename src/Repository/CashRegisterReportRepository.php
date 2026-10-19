<?php

namespace App\Repository;

use App\Entity\Caisse;
use App\Entity\CashRegister;
use App\Entity\CashRegisterReport;
use Doctrine\ORM\EntityRepository;

/** Rapports Z ; obtenu par l'EntityManager du tenant (TenantEntityManagerProvider), jamais injecté */
class CashRegisterReportRepository extends EntityRepository
{
    /** Numéro suivant du poste : à appeler sous verrou de la ligne du poste (fermeture), d'où une suite sans trou */
    public function nextNumber(CashRegister $register): int
    {
        return 1 + (int) $this->createQueryBuilder('z')->select('MAX(z.number)')
            ->andWhere('z.cashRegister = :register')->setParameter('register', $register)
            ->getQuery()->getSingleScalarResult();
    }

    public function findOneBySession(Caisse $session): ?CashRegisterReport
    {
        return $this->findOneBy(['session' => $session]);
    }

    public function findLastForRegister(CashRegister $register): ?CashRegisterReport
    {
        return $this->findOneBy(['cashRegister' => $register], ['number' => 'DESC']);
    }

    /** @return list<CashRegisterReport> du poste, les plus récents d'abord, fermés entre $from et $to (inclus) */
    public function findForRegister(CashRegister $register, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('z')->andWhere('z.cashRegister = :register')->setParameter('register', $register)
            ->orderBy('z.number', 'DESC');
        if ($from) {
            $qb->andWhere('z.closedAt >= :from')->setParameter('from', $from);
        }
        if ($to) {
            $qb->andWhere('z.closedAt <= :to')->setParameter('to', $to);
        }

        return $qb->getQuery()->getResult();
    }
}
