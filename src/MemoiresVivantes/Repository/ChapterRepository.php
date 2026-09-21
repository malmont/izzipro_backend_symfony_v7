<?php

namespace App\MemoiresVivantes\Repository;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use Doctrine\ORM\EntityRepository;

class ChapterRepository extends EntityRepository
{
    /**
     * @return Chapter[]
     */
    public function findByBookWithPhotos(Book $book): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.photos', 'p')
            ->addSelect('p')
            ->where('c.book = :book')
            ->setParameter('book', $book)
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('p.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
