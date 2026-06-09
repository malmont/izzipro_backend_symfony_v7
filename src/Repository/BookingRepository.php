<?php
// src/Repository/BookingRepository.php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\Product;
use DateTimeInterface;
use Doctrine\ORM\EntityRepository; 

class BookingRepository extends EntityRepository
{

    public function countReservedQuantityBetween(Product $product, DateTimeInterface $start, DateTimeInterface $end): int
    {
        $startUtc = \DateTimeImmutable::createFromInterface($start)->setTimezone(new \DateTimeZone('UTC'));
        $endUtc = \DateTimeImmutable::createFromInterface($end)->setTimezone(new \DateTimeZone('UTC'));

        $qb = $this->createQueryBuilder('b');
        
        $qb->select('SUM(b.quantity)')
           ->where('b.product = :product')
           ->andWhere('b.status NOT IN (:cancelledStatuses)')
           ->andWhere('b.startAt < :end')
           ->andWhere('b.endAt > :start')
           
           ->setParameter('product', $product)
           ->setParameter('start', $startUtc)
           ->setParameter('end', $endUtc)
           ->setParameter('cancelledStatuses', ['CANCELLED', 'REFUNDED', 'CART_ABANDONED']); 
        return (int) $qb->getQuery()->getSingleScalarResult();
    }
    /**
     * Récupère TOUTES les réservations qui touchent à une période donnée.
     * Optimisé pour ne faire qu'une seule requête.
     * @return Booking[]
     */
    public function findBookingsOverlapping(Product $product, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $startUtc = \DateTimeImmutable::createFromInterface($start)->setTimezone(new \DateTimeZone('UTC'));
        $endUtc = \DateTimeImmutable::createFromInterface($end)->setTimezone(new \DateTimeZone('UTC'));

        return $this->createQueryBuilder('b')
            ->where('b.product = :product')
            ->andWhere('b.status NOT IN (:cancelledStatuses)')
            ->andWhere('b.startAt < :end')
            ->andWhere('b.endAt > :start')
            
            ->setParameter('product', $product)
            ->setParameter('start', $startUtc)
            ->setParameter('end', $endUtc)
            ->setParameter('cancelledStatuses', ['CANCELLED', 'REFUNDED', 'CART_ABANDONED'])
            
            ->getQuery()
            ->getResult();
    }
}