<?php

namespace App\Repository;

use App\Entity\Carrier;
use Doctrine\ORM\EntityRepository; 


class CarrierRepository extends EntityRepository
{
  
    public function findAllByLocale(string $locale): array
    {
        // Un transporteur sans traduction dans la langue demandée reste proposé avec son nom de base (07/11/2026 : avant, il disparaissait
        // de la liste publique, comme le retrait en magasin fourni d'office) ; la jointure porte la condition, pas le filtre
        return $this->createQueryBuilder('c')
            ->leftJoin('c.translations', 't', 'WITH', 't.language = :locale')
            ->addSelect('t')
            ->setParameter('locale', $locale)
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }


    public function findByIdAndLocale(int $id, string $locale): ?Carrier
    {
        return $this->createQueryBuilder('c')
            ->where('c.id = :id')
            ->setParameter('id', $id)
            ->leftJoin('c.translations', 't')
            ->addSelect('t')
            ->andWhere('t.language = :locale')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getOneOrNullResult();
    }


    public function save(Carrier $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Carrier $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}