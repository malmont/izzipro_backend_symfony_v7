<?php
namespace App\Services\OrderService;

use App\Entity\Caisse;
use App\Entity\Order;
use App\Entity\TransactionCaisse;
use App\Entity\TransactionType;
use App\Entity\CashDetails;
use App\Entity\TypeCash;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider; 

class CaisseTransactionService
{

    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function createTransaction(
        Caisse $caisse,
        User $user,
        ?Order $order,
        float $amount,
        TransactionType $transactionType,
        array $cashDetails = [],
        ?int $cashAmount = null,
        ?int $cashRounding = null
    ): TransactionCaisse {

        $em = $this->emProvider->getEntityManager();
        $typeCashRepository = $em->getRepository(TypeCash::class);

        $transactionCaisse = new TransactionCaisse();
        $transactionCaisse->setCaisse($caisse);
        $transactionCaisse->setUserCaisse($user);
        $transactionCaisse->setOrderCaisse($order);
        $transactionCaisse->setTransactionDate(new \DateTime());
        $transactionCaisse->setTransactionType($transactionType);
        $transactionCaisse->setAmount($amount);
        $transactionCaisse->setCashAmount($cashAmount);
        $transactionCaisse->setCashRounding($cashRounding);
        
        foreach ($cashDetails as $detail) {
            // Coupure inconnue ou nombre invalide : 400 (500 avant le 19/10/2026, la table des coupures était vide partout)
            $typeCash = is_array($detail) && is_numeric($detail['typeCash'] ?? null) ? $typeCashRepository->find((int) $detail['typeCash']) : null;
            if (!$typeCash || !is_numeric($detail['nombreItems'] ?? null) || (int) $detail['nombreItems'] < 0) {
                throw new \App\Services\CaisseService\CashRegisterException('INVALID_CASH_DETAILS', 'cashDetails : coupure inconnue ou nombre invalide (GET /api/caisse/denominations)', 400);
            }
            $cashDetail = new CashDetails();
            $cashDetail->setTransactionCaisse($transactionCaisse);
            $cashDetail->setTypeCash($typeCash);
            $cashDetail->setNombreItems($detail['nombreItems']);
            $transactionCaisse->addCashDetail($cashDetail);
            $em->persist($cashDetail);
        }
        $em->persist($transactionCaisse);

        $em->flush();

        return $transactionCaisse;
    }
}