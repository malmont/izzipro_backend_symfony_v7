<?php

namespace App\Repository;

use App\Entity\SaleUnit;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<SaleUnit>
 *
 * @method SaleUnit|null find($id, $lockMode = null, $lockVersion = null)
 * @method SaleUnit|null findOneBy(array $criteria, array $orderBy = null)
 * @method SaleUnit[]    findAll()
 * @method SaleUnit[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SaleUnitRepository extends EntityRepository
{
    public function save(SaleUnit $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(SaleUnit $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
