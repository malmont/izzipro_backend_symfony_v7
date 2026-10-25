<?php

namespace App\Repository;

use App\Entity\PosManufacturerCoupon;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<PosManufacturerCoupon> */
class PosManufacturerCouponRepository extends EntityRepository
{
    /** @return list<PosManufacturerCoupon> */
    public function forOrder(int $orderId): array
    {
        return $this->findBy(['orderId' => $orderId], ['id' => 'ASC']);
    }

    /** Même coupon (fabricant + offre + numéro de série) déjà accepté sur le site et toujours actif (non annulé par un retour) */
    public function findActiveSerial(string $companyPrefix, string $offerCode, string $serial): ?PosManufacturerCoupon
    {
        return $this->createQueryBuilder('c')->where('c.companyPrefix = :p')->andWhere('c.offerCode = :o')->andWhere('c.serial = :s')->andWhere('c.status IN (:active)')
            ->setParameter('p', $companyPrefix)->setParameter('o', $offerCode)->setParameter('s', $serial)->setParameter('active', [PosManufacturerCoupon::REDEEMED, PosManufacturerCoupon::CLAIMED])
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** @return array{count: int, total: int, voided: int} coupons acceptés sur les ventes de la session, et annulés par les retours de la session */
    public function totalsForSession(int $caisseId): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $row = $conn->fetchAssociative('SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM pos_manufacturer_coupon WHERE caisse_id = ?', [$caisseId]);
        $voided = (int) $conn->fetchOne("SELECT COALESCE(SUM(amount), 0) FROM pos_manufacturer_coupon WHERE voided_caisse_id = ? AND status IN ('voided', 'returned_after_claim')", [$caisseId]);

        return ['count' => (int) $row['n'], 'total' => (int) $row['total'], 'voided' => $voided];
    }

    /**
     * Coupons à réclamer (ou déjà réclamés, ou retournés après réclamation) par fabricant et période d'acceptation.
     *
     * @return list<PosManufacturerCoupon>
     */
    public function claims(\DateTimeInterface $from, \DateTimeInterface $to, ?string $manufacturer, array $statuses): array
    {
        $qb = $this->createQueryBuilder('c')->where('c.redeemedAt >= :from')->andWhere('c.redeemedAt < :to')->andWhere('c.status IN (:statuses)')
            ->setParameter('from', $from)->setParameter('to', $to)->setParameter('statuses', $statuses)->orderBy('c.manufacturer', 'ASC')->addOrderBy('c.redeemedAt', 'ASC');
        if ($manufacturer !== null && $manufacturer !== '') {
            $qb->andWhere('LOWER(c.manufacturer) = :m')->setParameter('m', mb_strtolower($manufacturer));
        }

        return $qb->getQuery()->getResult();
    }
}
