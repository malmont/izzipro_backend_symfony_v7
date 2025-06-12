<?php
namespace App\UseCase\CaisseUseCase;

use App\Entity\Order;
use App\Entity\TransactionType;
use App\Services\TenantEntityManagerProvider;
use App\Services\CaisseService\CaisseService;
use App\Services\OrderService\CaisseTransactionService;

class HandleCaisseTransactionUseCase
{
    private TenantEntityManagerProvider $emProvider;
    private CaisseService $caisseService;
    private CaisseTransactionService $caisseTransactionService;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        CaisseService $caisseService,
        CaisseTransactionService $caisseTransactionService
    ) {
        $this->emProvider = $emProvider;
        $this->caisseService = $caisseService;
        $this->caisseTransactionService = $caisseTransactionService;
    }

    public function execute(Order $order = null, $user, float $transactionAmount, int $transactionTypeId, array $cashDetails = [], float $caisseAmount = 0): void
    {
        $em = $this->emProvider->getEntityManager();

        $caisse = $this->caisseService->getOpenCaisse();
        if (!$caisse && $transactionTypeId != 4) { // On autorise la transaction de type 4 (Ouverture) même si pas de caisse ouverte
            throw new \Exception('No open caisse found');
        }

        $transactionTypeRepository = $em->getRepository(TransactionType::class);
        $transactionType = $transactionTypeRepository->find($transactionTypeId);
        if (!$transactionType) {
            throw new \Exception('Transaction type not found');
        }
     
        // VOTRE LOGIQUE MÉTIER, CORRECTEMENT CONSERVÉE
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

        // On persiste la caisse si elle a été modifiée (elle peut être null pour le type 4)
        if ($caisse) {
            $em->persist($caisse);
        }

        // Création de la transaction caisse via le service
        $transactionCaisse = $this->caisseTransactionService->createTransaction(
            $caisse,
            $user,
            $order,
            $transactionAmount,
            $transactionType,
            $cashDetails 
        );

        // Pas besoin de persister transactionCaisse ici, car createTransaction le fait déjà.
        // Mais le faire n'est pas une erreur.
        $em->persist($transactionCaisse);

        // On sauvegarde toutes les modifications dans la base de données du tenant
        $em->flush();
    }
}