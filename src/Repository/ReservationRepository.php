<?php

namespace App\Repository;

use App\Entity\Reservation;
use Doctrine\ORM\EntityRepository;

/**
 * Repository multi-tenant pour Reservation.
 * @extends EntityRepository<Reservation>
 */
class ReservationRepository extends EntityRepository
{
    public function save(Reservation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Reservation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
