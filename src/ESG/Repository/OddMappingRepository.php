<?php

namespace App\ESG\Repository;

use App\ESG\Entity\CertificationReferential;
use App\ESG\Entity\OddMapping;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<OddMapping>
 */
class OddMappingRepository extends EntityRepository
{
    /**
     * @return OddMapping[]
     */
    public function findByReferential(CertificationReferential $referential): array
    {
        return $this->findBy(
            ['referential' => $referential],
            ['displayOrder' => 'ASC']
        );
    }

    public function save(OddMapping $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(OddMapping $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
