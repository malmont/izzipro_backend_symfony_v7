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

    public function execute(Order $order = null, $user, float $transactionAmount, int $transactionTypeId, array $cashDetails = [],float $caisseAmount=0): void
    
    {
        $caisse = $this->caisseService->getOpenCaisse();
        if (!$caisse && $transactionTypeId != 4) {
            throw new \Exception('No open caisse found');
        }

        $transactionType = $this->transactionTypeRepository->find($transactionTypeId);
        if (!$transactionTypeId) {
            throw new \Exception('Transaction type not found');
        }
     
        // Mise à jour du montant total de la caisse en fonction du type de transaction
        switch ($transactionTypeId) {
            case 1: // Vendu
                $caisse->setAmountTotal($caisse->getAmountTotal() + $caisseAmount);
                break;
            case 2: //  Remboursement 
                if ($caisse->getAmountTotal() < $caisseAmount) {
                    throw new \Exception('Insufficient funds in the caisse');
                }
                $caisse->setAmountTotal($caisse->getAmountTotal() - abs($caisseAmount));
                $transactionAmount = -abs($transactionAmount); // Retrait
                break;
            case 3: // Dépôt
                $caisse->setAmountTotal($caisse->getAmountTotal() + $transactionAmount);
                break;
            case 5: // Fermeture de la caisse
                // Aucune logique spécifique pour le moment
                break;
        
            case 4: // Ouverture de la caisse
                // Aucune logique spécifique pour le moment
                break;
            case 6: // Retrait
                if ($caisse->getAmountTotal() < $transactionAmount) {
                    throw new \Exception('Insufficient funds in the caisse');
                }
                $caisse->setAmountTotal($caisse->getAmountTotal() - $transactionAmount);
                $transactionAmount = -abs($transactionAmount); // Retrait
                break;
            case 7: // Retrait fond de caisse
                if ($caisse->getAmountTotal() < $transactionAmount) {
                    throw new \Exception('Insufficient funds in the caisse');
                }
                $caisse->setAmountTotal($caisse->getAmountTotal() - $transactionAmount);
                $caisse->setFonDeCaisse($caisse->getFonDeCaisse() - $transactionAmount);
                $transactionAmount = -abs($transactionAmount); // 
                break;
            case 8: //ajout fond de caisse
                $caisse->setAmountTotal($caisse->getAmountTotal() + $transactionAmount);
                $caisse->setFonDeCaisse($caisse->getFonDeCaisse() + $transactionAmount);
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
            $transactionAmount,
            $transactionType,
            $cashDetails 
        );

        // Persister la transaction caisse
        $this->em->persist($transactionCaisse);
        $this->em->flush();
    }
}