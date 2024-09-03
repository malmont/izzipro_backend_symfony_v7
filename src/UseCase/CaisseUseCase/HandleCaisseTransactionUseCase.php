<?php
namespace App\UseCase\CaisseUseCase;

use App\Entity\Order;
use App\Entity\Caisse;
use App\Entity\TransactionCaisse;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\TransactionTypeRepository;
use App\Services\CaisseService;

class HandleCaisseTransactionUseCase
{
    private $em;
    private $transactionTypeRepository;
    private $caisseService;

    public function __construct(EntityManagerInterface $em, TransactionTypeRepository $transactionTypeRepository, CaisseService $caisseService)
    {
        $this->em = $em;
        $this->transactionTypeRepository = $transactionTypeRepository;
        $this->caisseService = $caisseService;
    }

    public function execute(Order $order = null, $user, float $amount, int $transactionTypeId): void
    {
        $caisse = $this->caisseService->getOpenCaisse();
        if (!$caisse && $transactionTypeId!=4) {
            throw new \Exception('No open caisse found');
        }

        $transactionType = $this->transactionTypeRepository->find($transactionTypeId);
        if (!$transactionType) {
            throw new \Exception('Transaction type not found');
        }

        // Mettre à jour le montant total de la caisse en fonction du type de transaction
        switch ($transactionTypeId) {
            case 1: 
                $caisse->setAmountTotal($caisse->getAmountTotal() + $amount);
            break;
            case 3:
                $caisse->setAmountTotal($caisse->getAmountTotal() + $amount);
                break;

            case 2:  $caisse->setAmountTotal($caisse->getAmountTotal() + $amount);
            break;
            case 6: // Retrait (soustraire du montant)
                if ($caisse->getAmountTotal() < $amount) {
                    throw new \Exception('Insufficient funds in the caisse');
                }
                $caisse->setAmountTotal($caisse->getAmountTotal() - $amount);
                $amount=-abs($amount);
            break;
                case 4:  break;
            
            case 5: break;
            default:
                throw new \InvalidArgumentException('Unknown transaction type');
        }
     
        $this->em->persist($caisse);

        $transactionCaisse = new TransactionCaisse();
        $transactionCaisse->setCaisse($caisse);
        $transactionCaisse->setUserCaisse($user);
        $transactionCaisse->setOrderCaisse($order);
        $transactionCaisse->setTransactionDate(new \DateTime());
        $transactionCaisse->setTransactionType($transactionType);
        $transactionCaisse->setAmount($amount);

        $this->em->persist($transactionCaisse);
        $this->em->flush();
    }

    public function getEm(): EntityManagerInterface
    {
        return $this->em;
    }
}
