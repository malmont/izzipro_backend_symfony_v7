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
     * Les livres d'un biographe (14/11/2026) : ceux qu'il possède et ceux dont il est le biographe affecté.
     *
     * @return Book[]
     */
    public function findWithChaptersAndPhotosOfBiographer(User $biographer): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.chapters', 'c')
            ->addSelect('c')
            ->leftJoin('c.photos', 'p')
            ->addSelect('p')
            ->leftJoin('b.contributors', 'cb')
            ->addSelect('cb')
            ->where('b.user = :user OR b.biographer = :user')
            ->setParameter('user', $biographer)
            ->orderBy('b.createdAt', 'DESC')
            ->addOrderBy('c.position', 'ASC')
            ->addOrderBy('cb.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Book[]
     */
    public function findAllWithChaptersAndPhotos(?int $userId = null, ?int $biographerId = null, bool $unassigned = false): array
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
        // Biographe affecté (MV-AFFECTATION, 13/11/2026) : un compte, ou les livres sans biographe
        if ($unassigned) {
            $qb->andWhere('b.biographer IS NULL');
        } elseif ($biographerId !== null) {
            $qb->andWhere('b.biographer = :biographerId')->setParameter('biographerId', $biographerId);
        }

        return $qb->getQuery()->getResult();
    }
}
