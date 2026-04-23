<?php

namespace App\Repository;

use App\Entity\RentalPackTranslation;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<RentalPackTranslation>
 */
class RentalPackTranslationRepository extends EntityRepository
{
    public function save(RentalPackTranslation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(RentalPackTranslation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
