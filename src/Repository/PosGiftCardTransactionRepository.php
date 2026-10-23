<?php

namespace App\Repository;

use App\Entity\PosGiftCardTransaction;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<PosGiftCardTransaction> */
class PosGiftCardTransactionRepository extends EntityRepository
{
    /**
     * Totaux d'une session de caisse (rapports X et Z) : {sold, redeemed, creditsIssued, refunded (annulations + remboursements en espèces), adjusted} en {count, amount}
     *
     * @return array<string, array{count: int, amount: int}>
     */
    public function totalsForSession(int $caisseId): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT t.kind, c.kind AS card_kind, COUNT(*) AS n, COALESCE(SUM(ABS(t.amount)), 0) AS total FROM pos_gift_card_transaction t JOIN pos_gift_card c ON c.id = t.gift_card_id WHERE t.caisse_id = ? GROUP BY t.kind, c.kind', [$caisseId]);
        $out = ['sold' => ['count' => 0, 'amount' => 0], 'redeemed' => ['count' => 0, 'amount' => 0], 'creditsIssued' => ['count' => 0, 'amount' => 0], 'refunded' => ['count' => 0, 'amount' => 0], 'adjusted' => ['count' => 0, 'amount' => 0]];
        foreach ($rows as $r) {
            $key = match ($r['kind']) {
                PosGiftCardTransaction::ISSUE => $r['card_kind'] === 'store_credit' ? 'creditsIssued' : 'sold',
                PosGiftCardTransaction::REDEEM => 'redeemed',
                PosGiftCardTransaction::CANCEL, PosGiftCardTransaction::CASH_OUT => 'refunded',
                PosGiftCardTransaction::ADJUST => 'adjusted',
                default => null,
            };
            if ($key !== null) {
                $out[$key]['count'] += (int) $r['n'];
                $out[$key]['amount'] += (int) $r['total'];
            }
        }

        return $out;
    }

    /**
     * Parts payées par carte-cadeau ou avoir d'une vente, moins ce qui a déjà été recrédité : [giftCardId => montant disponible]
     *
     * @return array<int, int>
     */
    public function redeemedAvailable(int $orderId): array
    {
        $out = [];
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT gift_card_id, SUM(CASE WHEN kind = ? THEN -amount ELSE 0 END) - SUM(CASE WHEN kind = ? THEN amount ELSE 0 END) AS available FROM pos_gift_card_transaction WHERE order_id = ? GROUP BY gift_card_id ORDER BY MIN(id)',
            [PosGiftCardTransaction::REDEEM, PosGiftCardTransaction::REFUND, $orderId]) as $r) {
            if ((int) $r['available'] > 0) {
                $out[(int) $r['gift_card_id']] = (int) $r['available'];
            }
        }

        return $out;
    }
}
