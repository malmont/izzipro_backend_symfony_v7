<?php

namespace App\Repository;

use App\Entity\CategorieMarque;
use Doctrine\ORM\EntityRepository;

/**
 * @extends ServiceEntityRepository<CategorieMarque>
 */
class CategorieMarqueRepository extends EntityRepository
{

    public function findAllWithMarques(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.marques', 'm')
            ->addSelect('m') 
            ->orderBy('c.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByIdAndLocale(int $id, string $locale): ?CategorieMarque
    {
        return $this->createQueryBuilder('c')
            ->where('c.id = :id')
            ->setParameter('id', $id)
            ->leftJoin('c.marques', 'm')
            ->addSelect('m')
            ->leftJoin('c.translations', 't')
            ->addSelect('t')
            ->andWhere('t.language = :locale')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getOneOrNullResult();
    }
   public function findAllByLocale(string $locale): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.marques', 'm')
            ->addSelect('m')
            ->leftJoin('c.translations', 't')
            ->addSelect('t')
            ->andWhere('t.language = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('c.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
