<?php
namespace App\UseCase\CaisseUseCase;


use App\Entity\Order;
use App\Entity\TransactionCaisse;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\TransactionTypeRepository;

class HandleCaisseTransactionUseCase
{
    private $em;
    private $transactionTypeRepository;

    public function __construct(EntityManagerInterface $em, TransactionTypeRepository $transactionTypeRepository)
    {
        $this->em = $em;
        $this->transactionTypeRepository = $transactionTypeRepository;
    }

    public function execute(Order $order, $user, float $amount, string $transactionTypeName): void
    {
        $caisse = $this->getOpenCaisse();
        if (!$caisse) {
            throw new \Exception('No open caisse found');
        }

        $transactionType = $this->transactionTypeRepository->findOneBy(['name' => $transactionTypeName]);
        if (!$transactionType) {
            throw new \Exception('Transaction type not found');
        }

        $transactionCaisse = new TransactionCaisse();
        $transactionCaisse->setCaisse($caisse);
        $transactionCaisse->setUser($user);
        $transactionCaisse->setOrder($order);
        $transactionCaisse->setTransactionDate(new \DateTime());
        $transactionCaisse->setTransactionType($transactionType);
        $transactionCaisse->setAmount($amount);

        $this->em->persist($transactionCaisse);
        $this->em->flush();
    }

    private function getOpenCaisse()
    {
        return $this->em->getRepository(Caisse::class)->findOneBy(['isOpen' => true]);
    }
}