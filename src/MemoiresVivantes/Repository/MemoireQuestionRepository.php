<?php

namespace App\MemoiresVivantes\Repository;

use App\MemoiresVivantes\Entity\MemoireQuestion;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<MemoireQuestion>
 */
class MemoireQuestionRepository extends EntityRepository
{

    /**
     * @return MemoireQuestion[]
     */
    public function findByTheme(string $theme, bool $onlyActive = true): array
    {
        $qb = $this->createQueryBuilder('q')
            ->andWhere('q.theme = :theme')
            ->setParameter('theme', $theme);

        if ($onlyActive) {
            $qb->andWhere('q.isActive = :active')
               ->setParameter('active', true);
        }

        return $qb->orderBy('q.displayOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return MemoireQuestion[]
     */
    public function findByBookType(string $bookType, bool $onlyActive = true): array
    {
        $qb = $this->createQueryBuilder('q')
            ->andWhere('q.bookType = :bookType')
            ->setParameter('bookType', $bookType);

        if ($onlyActive) {
            $qb->andWhere('q.isActive = :active')
               ->setParameter('active', true);
        }

        return $qb->orderBy('q.theme', 'ASC')
            ->addOrderBy('q.displayOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
