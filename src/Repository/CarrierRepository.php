<?php

namespace App\Repository;

use App\Entity\Carrier;
use Doctrine\ORM\EntityRepository; 


class CarrierRepository extends EntityRepository
{

    public function save(Carrier $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Carrier $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}