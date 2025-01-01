<?php

namespace App\Services\OrderService;

use App\Entity\Caisse;
use App\Entity\Order;
use App\Entity\TransactionCaisse;
use App\Entity\TransactionType;
use App\Entity\CashDetails;
use App\Entity\TypeCash;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\TypeCashRepository;

class CaisseTransactionService
{
    private EntityManagerInterface $em;
    private TypeCashRepository $typeCashRepository;

    public function __construct(
        EntityManagerInterface $em,
        TypeCashRepository $typeCashRepository
    ) {
        $this->em = $em;
        $this->typeCashRepository = $typeCashRepository;
    }

    public function createTransaction(
        Caisse $caisse,
        User $user,
        ?Order $order,
        float $amount,
        TransactionType $transactionType,
        array $cashDetails = []
    ): TransactionCaisse {
        $transactionCaisse = new TransactionCaisse();
        $transactionCaisse->setCaisse($caisse);
        $transactionCaisse->setUserCaisse($user);
        $transactionCaisse->setOrderCaisse($order);
        $transactionCaisse->setTransactionDate(new \DateTime());
        $transactionCaisse->setTransactionType($transactionType);
        $transactionCaisse->setAmount($amount);
        
        // Gérer les détails en cash s'ils sont fournis
        foreach ($cashDetails as $detail) {
            $typeCash = $this->typeCashRepository->find($detail['typeCash']);
            if (!$typeCash) {
                throw new \Exception('Invalid TypeCash ID');
            }
            $cashDetail = new CashDetails();
            $cashDetail->setTransactionCaisse($transactionCaisse);
            $cashDetail->setTypeCash($typeCash);
            $cashDetail->setNombreItems($detail['nombreItems']);

            // Ajouter CashDetail à la transaction
            $transactionCaisse->addCashDetail($cashDetail);
            $this->em->persist($cashDetail);
        }

        $this->em->persist($transactionCaisse);
        $this->em->flush();

        return $transactionCaisse;
    }
}
