<?php

namespace App\Repository;

use App\Entity\Team;
use Doctrine\ORM\EntityRepository;

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<Team>
 */
class TeamRepository extends EntityRepository
{
    public function findAllByLocale(string $locale): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.translations', 'tr', 'WITH', 'tr.language = :locale')
            ->addSelect('tr')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();
    }

    public function findByIdAndLocale(int $id, string $locale): ?Team
    {
        return $this->createQueryBuilder('t')
            ->where('t.id = :id')
            ->setParameter('id', $id)
            ->leftJoin('t.translations', 'tr', 'WITH', 'tr.language = :locale')
            ->addSelect('tr')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
