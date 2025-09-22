<?php

namespace App\Repository;

use App\Entity\ExploreCard;
use Doctrine\ORM\EntityRepository; 

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<ExploreCard>
 */
class ExploreCardRepository extends EntityRepository
{
    public function findAllByLocale(string $locale): array
    {
        return $this->createQueryBuilder('ec') // 'ec' pour ExploreCard
            ->leftJoin('ec.translations', 't', 'WITH', 't.language = :locale')
            ->addSelect('t')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();
    }
}