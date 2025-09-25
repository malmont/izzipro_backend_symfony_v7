<?php

namespace App\Repository;

use App\Entity\Emploi;
use Doctrine\ORM\EntityRepository;
/**
 * @extends ServiceEntityRepository<Emploi>
 */
class EmploiRepository extends EntityRepository
{
   public function findAllByLocale(string $locale): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.translations', 't', 'WITH', 't.language = :locale')
            ->addSelect('t')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();
    }
}
