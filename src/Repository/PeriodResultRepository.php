<?php

namespace App\Repository;

use App\Entity\Collections;
use App\Entity\PeriodResult;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<PeriodResult> */
class PeriodResultRepository extends EntityRepository
{
    public function findLatestByCollection(Collections $collection): ?PeriodResult
    {
        return $this->findOneBy(['collection' => $collection], ['computedAt' => 'DESC', 'id' => 'DESC']);
    }

    /** Résultats figés d'une période supprimés (réouverture) ; nombre de lignes effacées */
    public function deleteByCollection(Collections $collection): int
    {
        return (int) $this->getEntityManager()->createQuery('DELETE FROM App\Entity\PeriodResult r WHERE r.collection = :c')->setParameter('c', $collection)->execute();
    }
}
