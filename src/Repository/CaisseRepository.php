<?php

namespace App\Repository;

use App\Entity\Caisse;
use App\Entity\CashRegister;
use Doctrine\ORM\EntityRepository; // dépôt de base : obtenu par l'EntityManager du tenant

class CaisseRepository extends EntityRepository
{
    /** Session ouverte du poste (au plus une : index unique partiel) */
    public function findOpenSession(CashRegister $register): ?Caisse
    {
        return $this->findOneBy(['cashRegister' => $register, 'isOpen' => true]);
    }

    /** Dernière session fermée du poste : son montant est reporté à l'ouverture suivante */
    public function getLastClosedCaisse(CashRegister $register): ?Caisse
    {
        return $this->createQueryBuilder('c')
            ->where('c.isOpen = :isOpen')
            ->andWhere('c.cashRegister = :register')
            ->setParameter('isOpen', false)
            ->setParameter('register', $register)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC') // Critère secondaire
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Caisse> sessions (toutes, ou d'un poste) ; $days : créées depuis N jours */
    public function findSessions(?int $days, ?CashRegister $register): array
    {
        $qb = $this->createQueryBuilder('c')->orderBy('c.id', 'ASC'); // même ordre qu'avant (findAll)
        if ($days) {
            $qb->andWhere('c.createdAt >= :date')->setParameter('date', (new \DateTime())->modify("-$days days"));
        }
        if ($register) {
            $qb->andWhere('c.cashRegister = :register')->setParameter('register', $register);
        }

        return $qb->getQuery()->getResult();
    }
}
