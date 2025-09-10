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
   
}
