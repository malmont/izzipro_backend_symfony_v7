<?php

namespace App\Repository;

use App\Entity\Video;
use Doctrine\ORM\EntityRepository;
/**
 * @extends ServiceEntityRepository<Video>
 */
class VideoRepository extends EntityRepository
{
     public function findAllByLocale(string $locale): array
    {
        return $this->createQueryBuilder('v') // 'v' est l'alias pour Video
            ->leftJoin('v.translations', 't', 'WITH', 't.language = :locale')
            ->addSelect('t')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();
    }

    public function findByIdAndLocale(int $id, string $locale): ?Video
    {
        return $this->createQueryBuilder('v')
            ->where('v.id = :id')
            ->setParameter('id', $id)
            ->leftJoin('v.translations', 't', 'WITH', 't.language = :locale')
            ->addSelect('t')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getOneOrNullResult();
    }

}
