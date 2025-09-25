<?php

namespace App\Repository;

use App\Entity\Carrier;
use Doctrine\ORM\EntityRepository; 


class CarrierRepository extends EntityRepository
{
  
    public function findAllByLocale(string $locale): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.translations', 't')
            ->addSelect('t')
            ->andWhere('t.language = :locale') 
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();
    }


    public function findByIdAndLocale(int $id, string $locale): ?Carrier
    {
        return $this->createQueryBuilder('c')
            ->where('c.id = :id')
            ->setParameter('id', $id)
            ->leftJoin('c.translations', 't')
            ->addSelect('t')
            ->andWhere('t.language = :locale')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getOneOrNullResult();
    }


    public function save(Carrier $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Carrier $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}