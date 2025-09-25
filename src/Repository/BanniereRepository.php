<?php

namespace App\Repository;

use App\Entity\Banniere;
use Doctrine\ORM\EntityRepository; 

class BanniereRepository extends EntityRepository
{

    public function findAllByLocale(string $locale): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.translations', 't')
            ->addSelect('t')
            ->andWhere('t.language = :locale') 
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();
    }

    public function findByIdAndLocale(int $id, string $locale): ?Banniere
    {
        return $this->createQueryBuilder('b')
            ->where('b.id = :id')
            ->setParameter('id', $id)
            ->leftJoin('b.translations', 't')
            ->addSelect('t')
            ->andWhere('t.language = :locale')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getOneOrNullResult();
    }
}