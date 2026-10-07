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
     * Images et vidéos de la médiathèque pour l'éditeur des landing pages, du plus récent au plus ancien, liens expirés
     * exclus. $query : filtre sur le titre (contient, sans casse).
     *
     * @param list<string> $types
     * @return array{0: SharedMedia[], 1: int} page demandée, total
     */
    public function searchForEditor(array $types, ?string $query, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.mediaType IN (:types)')
            ->andWhere('m.expiresAt IS NULL OR m.expiresAt > :now')
            ->setParameter('types', $types)
            ->setParameter('now', new \DateTimeImmutable());
        if ($query !== null && $query !== '') {
            $qb->andWhere('LOWER(m.titre) LIKE :q')
                ->setParameter('q', '%' . addcslashes(mb_strtolower($query), '%_\\') . '%');
        }
        $total = (int) (clone $qb)->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->orderBy('m.createdAt', 'DESC')->addOrderBy('m.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)
            ->getQuery()->getResult();

        return [$items, $total];
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
