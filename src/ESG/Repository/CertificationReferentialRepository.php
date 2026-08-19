<?php

namespace App\ESG\Repository;

use App\ESG\Entity\CertificationReferential;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<CertificationReferential>
 */
class CertificationReferentialRepository extends EntityRepository
{
    /**
     * @return CertificationReferential[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true], ['name' => 'ASC']);
    }

    public function findByCode(string $code): ?CertificationReferential
    {
        // Finds active referential with specified code
        return $this->findOneBy(['code' => $code, 'isActive' => true]);
    }

    /**
     * @return CertificationReferential[]
     */
    public function findActiveByTerritory(string $territory): array
    {
        // Searches for active referentials where the territory JSON contains the specified territory code.
        return $this->createQueryBuilder('r')
            ->where('r.isActive = :active')
            ->andWhere('r.territory LIKE :territory')
            ->setParameter('active', true)
            ->setParameter('territory', '%' . $territory . '%')
            ->getQuery()
            ->getResult();
    }

    public function save(CertificationReferential $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(CertificationReferential $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
