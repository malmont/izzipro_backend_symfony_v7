<?php

namespace App\MemoiresVivantes\Repository;

use App\Entity\User;
use App\MemoiresVivantes\Entity\Book;
use Doctrine\ORM\EntityRepository;

class BookRepository extends EntityRepository
{
    /**
     * @return Book[]
     */
    public function findWithChaptersAndPhotosByUser(User $user): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.chapters', 'c')
            ->addSelect('c')
            ->leftJoin('c.photos', 'p')
            ->addSelect('p')
            ->leftJoin('b.contributors', 'cb')
            ->addSelect('cb')
            ->where('b.user = :user')
            ->setParameter('user', $user)
            ->orderBy('b.createdAt', 'DESC')
            ->addOrderBy('c.position', 'ASC')
            ->addOrderBy('cb.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Book[]
     */
    public function findAllWithChaptersAndPhotos(?int $userId = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->leftJoin('b.user', 'u')
            ->addSelect('u')
            ->leftJoin('b.chapters', 'c')
            ->addSelect('c')
            ->leftJoin('c.photos', 'p')
            ->addSelect('p')
            ->leftJoin('b.contributors', 'cb')
            ->addSelect('cb')
            ->orderBy('b.createdAt', 'DESC')
            ->addOrderBy('c.position', 'ASC')
            ->addOrderBy('cb.sortOrder', 'ASC');

        if ($userId !== null) {
            $qb->andWhere('b.user = :userId')->setParameter('userId', $userId);
        }

        return $qb->getQuery()->getResult();
    }
}
