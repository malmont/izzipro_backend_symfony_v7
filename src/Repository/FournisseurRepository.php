<?php

namespace App\Repository;

use App\Entity\Fournisseur;
use Doctrine\ORM\EntityRepository; // MODIFIÉ : On utilise le repository de base

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<Fournisseur>
 */
class FournisseurRepository extends EntityRepository
{
    /**
     * SUPPRIMÉ : Le constructeur n'est plus nécessaire.
     */
    // public function __construct(ManagerRegistry $registry) { ... }

    /**
     * Liste filtrée (lot G5, 10/11/2026) : `q` sur le nom ou la ville, pagination facultative.
     *
     * @return array{items: list<Fournisseur>, total: int}
     */
    public function search(?string $q, ?int $page, ?int $limit): array
    {
        // ordre stable : nom sans tenir compte de la casse, puis identifiant (lot EDIT-JOURNAL, 12/11/2026 ; avant : casse de la base)
        $qb = $this->createQueryBuilder('f')->addSelect('LOWER(f.name) AS HIDDEN sortName')->orderBy('sortName', 'ASC')->addOrderBy('f.id', 'ASC');
        if ($q !== null && $q !== '') {
            $qb->andWhere('LOWER(f.name) LIKE :q OR LOWER(f.ville) LIKE :q')->setParameter('q', '%' . mb_strtolower($q) . '%');
        }
        $total = (int) (clone $qb)->select('COUNT(f.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        if ($page !== null && $limit !== null) {
            $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
        }

        return ['items' => $qb->getQuery()->getResult(), 'total' => $total];
    }
}
