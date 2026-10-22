<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\PosReturn;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<PosReturn> */
class PosReturnRepository extends EntityRepository
{
    /** @return PosReturn[] retours d'une vente, les plus anciens d'abord */
    public function findForOrder(Order $order): array
    {
        return $this->findBy(['order' => $order], ['id' => 'ASC']);
    }

    /** Quantités déjà retournées par ligne vendue : [orderItemId => quantité] */
    public function returnedQuantities(Order $order): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT ri.returned_from_item_id AS item_id, SUM(ri.quantity) AS quantity FROM order_items ri JOIN "order" r ON r.id = ri.order_associated_id
             WHERE r.returned_from_id = ? AND r.status_id <> 7 AND ri.returned_from_item_id IS NOT NULL GROUP BY ri.returned_from_item_id', [$order->getId()]);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['item_id']] = (int) $row['quantity'];
        }

        return $out;
    }

    /** Montants déjà remboursés sur une vente : [cash, card, total] */
    public function refundedAmounts(Order $order): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative('SELECT COALESCE(SUM(cash_refund), 0) AS cash, COALESCE(SUM(card_refund), 0) AS card, COALESCE(SUM(amount), 0) AS total FROM pos_return WHERE order_id = ?', [$order->getId()]);

        return ['cash' => (int) $row['cash'], 'card' => (int) $row['card'], 'total' => (int) $row['total']];
    }

    public function findByTerminalRefund(string $refundId): ?PosReturn
    {
        return $this->findOneBy(['terminalRefundId' => $refundId]);
    }

    /** @return array{count: int, total: int, restocked: int} retours de la session */
    public function totalsForSession(int $caisseId): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative('SELECT COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total, COALESCE(SUM(CASE WHEN restock THEN 1 ELSE 0 END), 0) AS restocked FROM pos_return WHERE caisse_id = ?', [$caisseId]);

        return ['count' => (int) $row['count'], 'total' => (int) $row['total'], 'restocked' => (int) $row['restocked']];
    }
}
