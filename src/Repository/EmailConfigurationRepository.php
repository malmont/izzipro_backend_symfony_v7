<?php

namespace App\Repository;

use App\Entity\EmailConfiguration;
use Doctrine\ORM\EntityRepository; 


class EmailConfigurationRepository extends EntityRepository
{
    public function findOneByLocale(string $locale): ?EmailConfiguration
        {
            return $this->createQueryBuilder('ec')
                ->leftJoin('ec.translations', 't', 'WITH', 't.language = :locale')
                ->addSelect('t')
                ->setParameter('locale', $locale)
                ->getQuery()
                ->getOneOrNullResult();
        }

}