<?php

namespace App\ESG\Repository;

use App\ESG\Entity\CertificationReferential;
use App\ESG\Entity\SubsidyProgram;
use App\ESG\Enum\TerritoryEnum;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<SubsidyProgram>
 */
class SubsidyProgramRepository extends EntityRepository
{
    /**
     * @return SubsidyProgram[]
     */
    public function findActiveByTerritory(TerritoryEnum $territory): array
    {
        return $this->findBy([
            'territory' => $territory,
            'isActive' => true,
        ]);
    }

    /**
     * @return SubsidyProgram[]
     */
    public function findByCertificationAndTerritory(CertificationReferential $cert, TerritoryEnum $territory): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.referentials', 'r')
            ->where('r.id = :certId')
            ->andWhere('p.territory = :territory')
            ->andWhere('p.isActive = :active')
            ->setParameter('certId', $cert->getId())
            ->setParameter('territory', $territory)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }

    public function save(SubsidyProgram $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(SubsidyProgram $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
