<?php
namespace App\UseCase\CaisseUseCase;

use App\Entity\Order;
use App\Entity\Caisse;
use App\Entity\TransactionCaisse;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\TransactionTypeRepository;
use App\Services\CaisseService\CaisseService;
use App\Services\OrderService\CaisseTransactionService;

class HandleCaisseTransactionUseCase
{
    private $em;
    private $transactionTypeRepository;
    private $caisseService;
    private $caisseTransactionService;

    public function __construct(
        EntityManagerInterface $em,
        TransactionTypeRepository $transactionTypeRepository,
        CaisseService $caisseService,
        CaisseTransactionService $caisseTransactionService
    ) {
        $this->em = $em;
        $this->transactionTypeRepository = $transactionTypeRepository;
        $this->caisseService = $caisseService;
        $this->caisseTransactionService = $caisseTransactionService;
    }

    public function execute(Order $order = null, $user, float $amount, int $transactionTypeId, array $cashDetails = []): void
    
    {
        $caisse = $this->caisseService->getOpenCaisse();
        if (!$caisse && $transactionTypeId != 4) {
            throw new \Exception('No open caisse found');
        }

        $transactionType = $this->transactionTypeRepository->find($transactionTypeId);
        if (!$transactionType) {
            throw new \Exception('Transaction type not found');
        }

        // Mise à jour du montant total de la caisse en fonction du type de transaction
        switch ($transactionTypeId) {
            case 1: // Vendu
            case 2: // Dépôt
            case 3: // Ajout
                $caisse->setAmountTotal($caisse->getAmountTotal() + $amount);
                break;
            case 6: // Retrait
                if ($caisse->getAmountTotal() < $amount) {
                    throw new \Exception('Insufficient funds in the caisse');
                }
                $caisse->setAmountTotal($caisse->getAmountTotal() - $amount);
                $amount = -abs($amount); // Retrait
                break;
            case 4: // Ouverture
            case 5: // Fermeture
            case 7: // Retrait fond de caisse
                if ($caisse->getAmountTotal() < $amount) {
                    throw new \Exception('Insufficient funds in the caisse');
                }
                $caisse->setAmountTotal($caisse->getAmountTotal() - $amount);
                $caisse->setFonDeCaisse($caisse->getFonDeCaisse()() - $amount);
                $amount = -abs($amount); // 
                break;
            case 8: //ajout fond de caisse
                $caisse->setAmountTotal($caisse->getAmountTotal() + $amount);
                $caisse->setFonDeCaisse($caisse->getFonDeCaisse() + $amount);
                break;
            default:
                throw new \InvalidArgumentException('Unknown transaction type');
        }

        // Persister la caisse mise à jour
        $this->em->persist($caisse);

        // Création de la transaction caisse via le service
        $transactionCaisse = $this->caisseTransactionService->createTransaction(
            $caisse,
            $user,
            $order,
            $amount,
            $transactionType,
            $cashDetails 
        );

        // Persister la transaction caisse
        $this->em->persist($transactionCaisse);
        $this->em->flush();
    }
}