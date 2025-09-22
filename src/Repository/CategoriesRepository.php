<?php

namespace App\Repository;

use App\Entity\Categories;
use Doctrine\ORM\EntityRepository; 


class CategoriesRepository extends EntityRepository
{

    public function save(Categories $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Categories $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
    public function findAllByLocale(string $locale): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.translations', 't', 'WITH', 't.language = :locale')
            ->addSelect('t')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();
    }
}