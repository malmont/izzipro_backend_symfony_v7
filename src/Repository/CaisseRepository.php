<?php

namespace App\Repository;

use App\Entity\Caisse;
use Doctrine\ORM\EntityRepository; // MODIFIÉ : On utilise le repository de base


class CaisseRepository extends EntityRepository
{
 
    public function getLastClosedCaisse(): ?Caisse
    {
        return $this->createQueryBuilder('c')
            ->where('c.isOpen = :isOpen')
            ->setParameter('isOpen', false)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC') // Critère secondaire
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}