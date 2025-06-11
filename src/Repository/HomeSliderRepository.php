<?php

namespace App\Repository;

use App\Entity\HomeSlider;
use Doctrine\ORM\EntityRepository; // IMPORTANT : On utilise le repository de base de Doctrine

/**
 * N'HÉRITE PLUS DE ServiceEntityRepository.
 * Ce repository n'est plus un service Symfony, mais une simple classe que
 * l'EntityManager saura créer et utiliser.
 */
class HomeSliderRepository extends EntityRepository
{
    // LE CONSTRUCTEUR A ÉTÉ COMPLÈTEMENT SUPPRIMÉ.
    // L'EntityManager se chargera lui-même de l'instancier.


    /**
     * EXEMPLE DE MÉTHODE PERSONNALISÉE :
     * Vous pouvez toujours ajouter vos propres méthodes de recherche.
     * Elles fonctionneront maintenant car `$this->createQueryBuilder()`
     * utilisera le bon EntityManager (celui du tenant).
     *
     * @return HomeSlider[]
     */
    public function findAllActiveOrderedByPosition(): array
    {
        return $this->createQueryBuilder('h') // 'h' est l'alias pour HomeSlider
            ->andWhere('h.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('h.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
    
    // Note : Les méthodes `save` et `remove` que vous aviez ne sont généralement
    // pas nécessaires dans un repository car c'est le rôle de l'EntityManager,
    // mais si vous voulez les garder pour des raisons de raccourci, elles
    // fonctionneront aussi car $this->getEntityManager() retournera le bon.
    public function save(HomeSlider $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(HomeSlider $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}