<?php

namespace App\MemoiresVivantes\Repository;

use App\MemoiresVivantes\Entity\BookPrintOrder;
use Doctrine\ORM\EntityRepository;

class BookPrintOrderRepository extends EntityRepository
{
    /**
     * @return BookPrintOrder[]
     */
    public function findByBook(string $bookId): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.book = :bookId')
            ->setParameter('bookId', $bookId)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByLuluJobId(string $jobId): ?BookPrintOrder
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.luluPrintJobId = :jobId')
            ->setParameter('jobId', $jobId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
