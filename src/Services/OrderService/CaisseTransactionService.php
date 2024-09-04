<?php
namespace App\Services\OrderService;

use App\Entity\Caisse;
use App\Entity\Order;
use App\Entity\TransactionCaisse;
use App\Entity\TransactionType;
use App\Entity\User;

class CaisseTransactionService
{
    public function createTransaction(
        Caisse $caisse,
        User $user,
        ?Order $order,
        float $amount,
        TransactionType $transactionType
    ): TransactionCaisse {
        $transactionCaisse = new TransactionCaisse();
        $transactionCaisse->setCaisse($caisse);
        $transactionCaisse->setUserCaisse($user);
        $transactionCaisse->setOrderCaisse($order);
        $transactionCaisse->setTransactionDate(new \DateTime());
        $transactionCaisse->setTransactionType($transactionType);
        $transactionCaisse->setAmount($amount);

        return $transactionCaisse;
    }
}
