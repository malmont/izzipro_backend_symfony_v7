<?php

namespace App\Repository;

use App\Entity\RentalPack;
use Doctrine\ORM\EntityRepository;

/**
 * @method RentalPack|null find($id, $lockMode = null, $lockVersion = null)
 * @method RentalPack|null findOneBy(array $criteria, array $orderBy = null)
 * @method RentalPack[]    findAll()
 * @method RentalPack[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class RentalPackRepository extends EntityRepository
{
    /**
     * Finding a pack associated with a specific category.
     */
    public function findFirstPackForCategory(\App\Entity\Categories $category): ?RentalPack
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.categories', 'c')
            ->where('c.id = :categoryId')
            ->setParameter('categoryId', $category->getId())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
