<?php

namespace App\ESG\Repository;

use App\ESG\Entity\EsgUser;
use App\ESG\Entity\EsgCompany;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<EsgUser>
 */
class EsgUserRepository extends EntityRepository
{
    public function findByEmail(string $email): ?EsgUser
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * @return EsgUser[]
     */
    public function findActiveByCompany(EsgCompany $company): array
    {
        return $this->findBy([
            'company' => $company,
            'isActive' => true,
        ]);
    }

    public function save(EsgUser $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(EsgUser $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
