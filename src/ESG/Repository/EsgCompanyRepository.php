<?php

namespace App\ESG\Repository;

use App\ESG\Entity\EsgCompany;
use App\ESG\Enum\TerritoryEnum;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<EsgCompany>
 */
class EsgCompanyRepository extends EntityRepository
{
    public function findBySlug(string $slug): ?EsgCompany
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return EsgCompany[]
     */
    public function findActiveByTerritory(TerritoryEnum $territory): array
    {
        return $this->findBy([
            'territory' => $territory,
            'isActive' => true,
        ]);
    }

    public function save(EsgCompany $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(EsgCompany $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
