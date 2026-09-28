<?php

namespace App\Repository;

use App\Entity\SharedMedia;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<SharedMedia>
 */
class SharedMediaRepository extends EntityRepository
{
    /**
     * Recherche un média par sa clé d'accès sécurisée.
     */
    public function findOneByAccessKey(string $key): ?SharedMedia
    {
        return $this->createQueryBuilder('m')
            ->where('m.accessKey = :key')
            ->setParameter('key', $key)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne tous les médias publics actifs.
     *
     * @return SharedMedia[]
     */
    public function findPublicMedia(): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.visibility = :visibility')
            ->setParameter('visibility', SharedMedia::VISIBILITY_PUBLIC)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
