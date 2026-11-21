<?php

namespace App\Repository;

use App\Entity\VehicleProduct;
use Doctrine\ORM\EntityRepository;

/**
 * Lectures publiques des produits véhicules (boutique), reprises d'API Platform au lot APIP-RETRAIT (20/11/2026).
 * Une seule requête par page : les deux OneToOne côté inverse de Product (configuration de réservation, livraison), que Doctrine
 * chargerait sinon une par une même sans les lire, sont joints (API Platform en faisait 26 requêtes pour 24 éléments).
 *
 * @extends EntityRepository<VehicleProduct>
 */
class VehicleProductRepository extends EntityRepository
{
    /** @return VehicleProduct[] page $page (à partir de 1), ordre croissant des identifiants, comme API Platform */
    public function findPage(int $page, int $perPage): array
    {
        return $this->createQueryBuilder('vp')
            ->leftJoin('vp.bookingConfiguration', 'bc')->addSelect('bc')
            ->leftJoin('vp.productShipping', 'ps')->addSelect('ps')
            ->orderBy('vp.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()->getResult();
    }

    public function findOneWithBooking(int $id): ?VehicleProduct
    {
        return $this->createQueryBuilder('vp')
            ->leftJoin('vp.bookingConfiguration', 'bc')->addSelect('bc')
            ->leftJoin('vp.productShipping', 'ps')->addSelect('ps')
            ->andWhere('vp.id = :id')->setParameter('id', $id)
            ->getQuery()->getOneOrNullResult();
    }
}
