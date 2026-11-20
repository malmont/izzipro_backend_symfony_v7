<?php

namespace App\Repository;

use App\Entity\Reservation;
use Doctrine\ORM\EntityRepository;

/**
 * Repository multi-tenant pour Reservation.
 * @extends EntityRepository<Reservation>
 */
class ReservationRepository extends EntityRepository
{
    public function save(Reservation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Reservation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
    /**
     * Lot CMD-WEB-1 : un rendez-vous de la BOUTIQUE (prestation de la vitrine) ; jamais un entretien de Mémoires Vivantes. Le critère d'un
     * entretien est celui du courriel (`ReservationMailerService::isWritingSession` : livre, biographe ou numéro de séance), plus le chapitre ;
     * un biographe supprimé (SET NULL) ne fait pas d'une séance un rendez-vous de la boutique.
     */
    public const SHOP_WHERE = 'r.bookId IS NULL AND r.biographer IS NULL AND r.stepNumber IS NULL AND r.chapterId IS NULL';

    /**
     * Lot CMD-WEB-1 : une page des rendez-vous de la boutique : à venir d'abord (le plus proche en tête), puis les passés (le plus récent en
     * tête), jugés sur `$today`, le jour du MAGASIN (pas celui de la base) ; filtres facultatifs : état, bornes du jour (Y-m-d, jour du
     * magasin : la colonne est une date sans heure).
     *
     * @return array{items: list<Reservation>, total: int}
     */
    public function shopPage(?string $status, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, string $today, int $offset, int $limit): array
    {
        $qb = $this->createQueryBuilder('r')->where(self::SHOP_WHERE);
        if ($status !== null) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }
        if ($from !== null) {
            $qb->andWhere('r.reservationDate >= :from')->setParameter('from', $from->format('Y-m-d'));
        }
        if ($to !== null) {
            $qb->andWhere('r.reservationDate <= :to')->setParameter('to', $to->format('Y-m-d'));
        }
        $total = (int) (clone $qb)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->addSelect('CASE WHEN r.reservationDate < :today THEN 1 ELSE 0 END AS HIDDEN past')
            ->addSelect('ABS(DATE_DIFF(r.reservationDate, :today)) AS HIDDEN distance')->setParameter('today', $today)
            ->orderBy('past', 'ASC')->addOrderBy('distance', 'ASC')->addOrderBy('r.reservationSlot', 'ASC')->addOrderBy('r.id', 'ASC')
            ->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /** Lot CMD-WEB-1 : la ligne verrouillée (FOR UPDATE) le temps de la transaction en cours ; `$shopOnly` : null pour un entretien MV */
    public function lockForStatus(int $id, bool $shopOnly): ?Reservation
    {
        $qb = $this->createQueryBuilder('r')->where('r.id = :id')->setParameter('id', $id);
        if ($shopOnly) {
            $qb->andWhere(self::SHOP_WHERE);
        }

        return $qb->getQuery()->setLockMode(\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
    }

    /** Lot CMD-WEB-1 : un rendez-vous de la boutique, sans verrou ; null pour un entretien MV ou un numéro inconnu */
    public function findShop(int $id): ?Reservation
    {
        return $this->createQueryBuilder('r')->where('r.id = :id')->andWhere(self::SHOP_WHERE)->setParameter('id', $id)->getQuery()->getOneOrNullResult();
    }
}
