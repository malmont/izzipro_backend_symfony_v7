<?php

namespace App\Repository;

use App\Entity\CashRegister;
use Doctrine\ORM\EntityRepository;

/** Postes de caisse ; obtenu par l'EntityManager du tenant (TenantEntityManagerProvider), jamais injecté */
class CashRegisterRepository extends EntityRepository
{
    /** @return list<CashRegister> */
    public function findAllOrdered(bool $activeOnly): array
    {
        $qb = $this->createQueryBuilder('r')->orderBy('r.id', 'ASC');
        if ($activeOnly) {
            $qb->andWhere('r.isActive = true');
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneActive(int $id): ?CashRegister
    {
        return $this->findOneBy(['id' => $id, 'isActive' => true]);
    }

    public function findOneByName(string $name): ?CashRegister
    {
        return $this->createQueryBuilder('r')->andWhere('LOWER(r.name) = LOWER(:name)')->setParameter('name', $name)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }
}
