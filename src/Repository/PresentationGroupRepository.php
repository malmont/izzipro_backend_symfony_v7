<?php

namespace App\Repository;

use App\Entity\PresentationGroup;
use Doctrine\ORM\EntityRepository; 


class PresentationGroupRepository extends EntityRepository
{
    public function findAllByLocale(string $locale): array
    {
        return $this->createQueryBuilder('pg')
            ->leftJoin('pg.translations', 'pgt', 'WITH', 'pgt.language = :locale')
            ->addSelect('pgt')
            ->leftJoin('pg.presentations', 'p')
            ->addSelect('p')
            ->leftJoin('p.translations', 'pt', 'WITH', 'pt.language = :locale')
            ->addSelect('pt')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();
    }
    public function findByIdAndLocale(int $id, string $locale): ?PresentationGroup
    {
        return $this->createQueryBuilder('pg')
            ->where('pg.id = :id')
            ->setParameter('id', $id)
            ->leftJoin('pg.translations', 'pgt', 'WITH', 'pgt.language = :locale')
            ->addSelect('pgt')
            ->leftJoin('pg.presentations', 'p')
            ->addSelect('p')
            ->leftJoin('p.translations', 'pt', 'WITH', 'pt.language = :locale')
            ->addSelect('pt')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getOneOrNullResult();
    }

}
