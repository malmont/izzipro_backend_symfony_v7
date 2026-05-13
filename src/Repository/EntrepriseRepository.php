<?php

namespace App\Repository;

use App\Entity\Entreprise;
use Doctrine\ORM\EntityRepository;

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<Entreprise>
 */
class EntrepriseRepository extends EntityRepository
{
    public function findByIdAndLocale(int $id, string $locale): ?Entreprise
    {
        return $this->createQueryBuilder('e')
            ->where('e.id = :id')
            ->setParameter('id', $id)
            ->leftJoin('e.translations', 't', 'WITH', 't.language = :locale')
            ->leftJoin('e.addressEntreprise', 'ae')
            ->addSelect('t', 'ae')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getOneOrNullResult();
    }

}