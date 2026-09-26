<?php

namespace App\MemoiresVivantes\Repository;

use App\MemoiresVivantes\Entity\BookType;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<BookType>
 */
class BookTypeRepository extends EntityRepository
{
    public function findOneByCode(string $code): ?BookType
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * @return BookType[]
     */
    public function findAllOrdered(bool $onlyActive = true): array
    {
        $qb = $this->createQueryBuilder('t');

        if ($onlyActive) {
            $qb->andWhere('t.isActive = :active')
               ->setParameter('active', true);
        }

        return $qb->orderBy('t.displayOrder', 'ASC')
            ->addOrderBy('t.label', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
