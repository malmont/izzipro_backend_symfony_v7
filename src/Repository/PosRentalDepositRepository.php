<?php

namespace App\Repository;

use App\Entity\PosRentalDeposit;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<PosRentalDeposit> Cautions de location au comptoir (09/11/2026). */
class PosRentalDepositRepository extends EntityRepository
{
    public function heldForOrder(int $orderId): ?PosRentalDeposit
    {
        return $this->findOneBy(['orderId' => $orderId, 'status' => PosRentalDeposit::HELD], ['id' => 'ASC']);
    }

    /** @return list<PosRentalDeposit> */
    public function forOrder(int $orderId): array
    {
        return $this->findBy(['orderId' => $orderId], ['id' => 'ASC']);
    }

    /**
     * Bloc du rapport X / Z : cautions encaissées et restituées pendant la session (espèces à part), retenues, et cautions en cours du site.
     *
     * @return array{collected: array{count: int, total: int, cash: int}, returned: array{count: int, total: int, cash: int}, retained: array{count: int, total: int}, outstanding: array{count: int, total: int}}
     */
    public function totalsForSession(int $sessionId): array
    {
        $db = $this->getEntityManager()->getConnection();
        $collected = $db->fetchAssociative("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total, COALESCE(SUM(CASE WHEN method = 'cash' THEN amount ELSE 0 END), 0) AS cash FROM pos_rental_deposit WHERE collected_session_id = ?", [$sessionId]) ?: [];
        $returned = $db->fetchAssociative("SELECT COUNT(*) AS n, COALESCE(SUM(returned_amount), 0) AS total, COALESCE(SUM(CASE WHEN return_method = 'cash' THEN returned_amount ELSE 0 END), 0) AS cash, COALESCE(SUM(CASE WHEN retained_amount > 0 THEN 1 ELSE 0 END), 0) AS retained_n, COALESCE(SUM(retained_amount), 0) AS retained FROM pos_rental_deposit WHERE return_session_id = ?", [$sessionId]) ?: [];
        $outstanding = $db->fetchAssociative("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM pos_rental_deposit WHERE status = 'held'") ?: [];

        return [
            'collected' => ['count' => (int) ($collected['n'] ?? 0), 'total' => (int) ($collected['total'] ?? 0), 'cash' => (int) ($collected['cash'] ?? 0)],
            'returned' => ['count' => (int) ($returned['n'] ?? 0), 'total' => (int) ($returned['total'] ?? 0), 'cash' => (int) ($returned['cash'] ?? 0)],
            'retained' => ['count' => (int) ($returned['retained_n'] ?? 0), 'total' => (int) ($returned['retained'] ?? 0)],
            'outstanding' => ['count' => (int) ($outstanding['n'] ?? 0), 'total' => (int) ($outstanding['total'] ?? 0)],
        ];
    }
}
