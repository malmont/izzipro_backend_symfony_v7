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
        array $cashDetails = []
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
        
        foreach ($cashDetails as $detail) {
            $typeCash = $typeCashRepository->find($detail['typeCash']);
            if (!$typeCash) {
                throw new \Exception('Invalid TypeCash ID');
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