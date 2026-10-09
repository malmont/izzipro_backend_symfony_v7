<?php

namespace App\Repository;

use App\Entity\Feature;
use Doctrine\ORM\EntityRepository;

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<Feature>
 */
class FeatureRepository extends EntityRepository
{
    public function findAllByLocale(string $locale): array
    {
        return $this->createQueryBuilder('f') // 'f' est l'alias pour Feature
            ->leftJoin('f.translations', 't', 'WITH', 't.language = :locale')
            ->addSelect('t')
            ->setParameter('locale', $locale)
            ->orderBy('f.position', 'ASC')->addOrderBy('f.id', 'ASC') // ordre choisi dans l'éditeur (09/10/2026)
            ->getQuery()
            ->getResult();
    }

}