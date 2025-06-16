<?php

namespace App\Repository;

use App\Entity\Collections;
use Doctrine\ORM\EntityRepository; 


class CollectionsRepository extends EntityRepository
{

    public function save(Collections $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Collections $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}