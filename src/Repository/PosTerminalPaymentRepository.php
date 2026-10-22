<?php

namespace App\Repository;

use App\Entity\CashRegister;
use App\Entity\PosTerminalPayment;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<PosTerminalPayment> */
class PosTerminalPaymentRepository extends EntityRepository
{
    public function findByIntent(string $paymentIntentId): ?PosTerminalPayment
    {
        return $this->findOneBy(['stripePaymentIntentId' => $paymentIntentId]);
    }

    /** Paiements encore en vie (intention créée, carte attendue, autorisation non capturée) */
    public function findPending(): array
    {
        return $this->createQueryBuilder('p')->where('p.status IN (:pending)')->setParameter('pending', PosTerminalPayment::PENDING)
            ->orderBy('p.createdAt', 'ASC')->getQuery()->getResult();
    }

    public function hasPending(): bool
    {
        return (bool) $this->createQueryBuilder('p')->select('1')->where('p.status IN (:pending)')->setParameter('pending', PosTerminalPayment::PENDING)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** Paiement en cours sur un lecteur (une seule opération à la fois) */
    public function findActiveOnReader(string $readerId): ?PosTerminalPayment
    {
        return $this->createQueryBuilder('p')->where('p.readerId = :reader')->andWhere('p.status IN (:active)')
            ->setParameter('reader', $readerId)->setParameter('active', [PosTerminalPayment::CREATED, PosTerminalPayment::WAITING_FOR_CARD, PosTerminalPayment::PROCESSING])
            ->orderBy('p.createdAt', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** Débités ou autorisés, créés depuis plus de N minutes et toujours sans vente (paiements sans vente) */
    public function findOrphans(int $olderThanMinutes, ?CashRegister $register = null): array
    {
        $qb = $this->createQueryBuilder('p')->where('p.orderId IS NULL')->andWhere('p.status IN (:kept)')
            ->andWhere('p.createdAt < :limit')
            ->setParameter('kept', [PosTerminalPayment::CAPTURED, PosTerminalPayment::AUTHORIZED])
            ->setParameter('limit', new \DateTimeImmutable('-' . max(0, $olderThanMinutes) . ' minutes'))
            ->orderBy('p.createdAt', 'DESC');
        if ($register !== null) {
            $qb->andWhere('p.cashRegister = :register')->setParameter('register', $register);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return PosTerminalPayment[] paiements rattachés aux commandes données */
    public function findByOrderIds(array $orderIds): array
    {
        return $orderIds ? $this->createQueryBuilder('p')->where('p.orderId IN (:ids)')->setParameter('ids', $orderIds)->getQuery()->getResult() : [];
    }

    public function findOneByOrder(int $orderId): ?PosTerminalPayment
    {
        return $this->findOneBy(['orderId' => $orderId], ['id' => 'DESC']);
    }
}
