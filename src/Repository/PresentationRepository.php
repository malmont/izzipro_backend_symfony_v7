<?php

namespace App\Repository;

use App\Entity\Presentation;
use Doctrine\ORM\EntityRepository; 

/**
 * @extends ServiceEntityRepository<Presentation>
 */
class PresentationRepository extends EntityRepository
{
     public function findAllByLocale(string $locale): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.translations', 't', 'WITH', 't.language = :locale')
            ->addSelect('t')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();
    }


    public function findByIdAndLocale(int $id, string $locale): ?Presentation
    {
        return $this->createQueryBuilder('p')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->leftJoin('p.translations', 't', 'WITH', 't.language = :locale')
            ->addSelect('t')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getOneOrNullResult();
    }

}
