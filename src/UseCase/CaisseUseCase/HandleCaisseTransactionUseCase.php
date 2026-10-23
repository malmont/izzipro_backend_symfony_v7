<?php
namespace App\UseCase\CaisseUseCase;

use App\Entity\Caisse;
use App\Entity\Order;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\OrderService\CaisseTransactionService;
use App\Services\TenantCacheService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\LockMode;

/**
 * Mouvement sur une session de caisse : met à jour amountTotal (et fonDeCaisse) puis enregistre la TransactionCaisse.
 * 15/10/2026 : reçoit la session visée (plus de recherche de « la » caisse ouverte du site : plusieurs postes) et
 * verrouille sa ligne (SELECT … FOR UPDATE) pendant la mise à jour ; dans la transaction SQL de l'appelant s'il en a
 * une (vente, remboursement), sinon dans la sienne.
 * Types (table transaction_type) : 1 vente, 2 remboursement, 3 dépôt, 4 ouverture, 5 fermeture, 6 retrait,
 * 7 retrait du fond de caisse, 8 ajout au fond de caisse. Montants en cents, comme amountTotal.
 */
class HandleCaisseTransactionUseCase
{
    public const SALE = 1;
    public const REFUND = 2;
    public const DEPOSIT = 3;
    public const OPENING = 4;
    public const CLOSING = 5;
    public const WITHDRAWAL = 6;
    public const CASH_FUND_WITHDRAWAL = 7;
    public const CASH_FUND_DEPOSIT = 8;
    public const GIFT_CARD_SALE = 9; // vente d'une carte-cadeau (F9, 27/10/2026) : espèces encaissées, hors bloc ventes des rapports
    public const GIFT_CARD_REFUND = 10; // annulation d'une carte ou remboursement d'un solde en espèces (F9)

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly CaisseTransactionService $caisseTransactionService,
        private readonly TenantCacheService $cache
    ) {
    }

    /**
     * @param float $transactionAmount montant de la transaction enregistrée (vente : total de la commande)
     * @param float $caisseAmount part en espèces qui entre (vente) ou sort (remboursement) de la caisse ; 0 : $transactionAmount
     * @param ?int $cashRounding arrondi des espèces contenu dans la transaction (cents, effet sur le tiroir)
     */
    public function execute(Caisse $caisse, ?Order $order, User $user, float $transactionAmount, int $transactionTypeId, array $cashDetails = [], float $caisseAmount = 0, ?int $cashRounding = null): void
    {
        $em = $this->emProvider->getEntityManager();
        $connection = $em->getConnection();
        $ownTransaction = !$connection->isTransactionActive();
        if ($ownTransaction) {
            $connection->beginTransaction();
        }
        try {
            $transactionType = $em->getRepository(TransactionType::class)->find($transactionTypeId)
                ?? throw new \RuntimeException('Transaction type not found');

            $em->lock($caisse, LockMode::PESSIMISTIC_WRITE); // ligne verrouillée jusqu'à la fin de la transaction
            $em->refresh($caisse);

            switch ($transactionTypeId) {
                case self::SALE:
                    $caisse->setAmountTotal($caisse->getAmountTotal() + $caisseAmount);
                    break;
                case self::REFUND:
                    if ($caisse->getAmountTotal() < $caisseAmount) {
                        throw new CashRegisterException('INSUFFICIENT_FUNDS', 'Fonds insuffisants dans la caisse', 400);
                    }
                    $caisse->setAmountTotal($caisse->getAmountTotal() - abs($caisseAmount));
                    $transactionAmount = -abs($transactionAmount);
                    break;
                case self::DEPOSIT:
                    $caisse->setAmountTotal($caisse->getAmountTotal() + $transactionAmount);
                    break;
                case self::OPENING:
                case self::CLOSING:
                    break;
                case self::WITHDRAWAL:
                    if ($caisse->getAmountTotal() < $transactionAmount) {
                        throw new CashRegisterException('INSUFFICIENT_FUNDS', 'Fonds insuffisants dans la caisse', 400);
                    }
                    $caisse->setAmountTotal($caisse->getAmountTotal() - $transactionAmount);
                    $transactionAmount = -abs($transactionAmount);
                    break;
                case self::CASH_FUND_WITHDRAWAL:
                    if ($caisse->getAmountTotal() < $transactionAmount) {
                        throw new CashRegisterException('INSUFFICIENT_FUNDS', 'Fonds insuffisants dans la caisse', 400);
                    }
                    $caisse->setAmountTotal($caisse->getAmountTotal() - $transactionAmount);
                    $caisse->setFonDeCaisse(($caisse->getFonDeCaisse() ?? 0) - $transactionAmount);
                    $transactionAmount = -abs($transactionAmount);
                    break;
                case self::CASH_FUND_DEPOSIT:
                    $caisse->setAmountTotal($caisse->getAmountTotal() + $transactionAmount);
                    $caisse->setFonDeCaisse(($caisse->getFonDeCaisse() ?? 0) + $transactionAmount);
                    break;
                case self::GIFT_CARD_SALE: // F9 : seule la part espèces entre dans le tiroir
                    $caisse->setAmountTotal($caisse->getAmountTotal() + $caisseAmount);
                    break;
                case self::GIFT_CARD_REFUND: // F9 : espèces rendues au client
                    if ($caisse->getAmountTotal() < $caisseAmount) {
                        throw new CashRegisterException('INSUFFICIENT_FUNDS', 'Fonds insuffisants dans la caisse', 400);
                    }
                    $caisse->setAmountTotal($caisse->getAmountTotal() - abs($caisseAmount));
                    $transactionAmount = -abs($transactionAmount);
                    break;
                default:
                    throw new \InvalidArgumentException('Unknown transaction type');
            }

            // Effet sur les espèces du tiroir (cents, signé), enregistré sur la transaction pour les rapports X et Z
            $cashAmount = match ($transactionTypeId) {
                self::SALE, self::GIFT_CARD_SALE => (int) round($caisseAmount),
                self::REFUND, self::GIFT_CARD_REFUND => -(int) round(abs($caisseAmount)),
                self::DEPOSIT, self::CASH_FUND_DEPOSIT => (int) round(abs($transactionAmount)),
                self::WITHDRAWAL, self::CASH_FUND_WITHDRAWAL => -(int) round(abs($transactionAmount)),
                default => 0,
            };

            $em->persist($caisse);
            // createTransaction persiste et flush (session mise à jour et transaction écrites ensemble)
            $this->caisseTransactionService->createTransaction($caisse, $user, $order, $transactionAmount, $transactionType, $cashDetails, $cashAmount, $cashRounding);

            if ($ownTransaction) {
                $connection->commit();
            }
            $this->cache->invalidateTags(['caisses_tag']); // listes de sessions et transactions (GET /api/caisse*)
        } catch (\Throwable $e) {
            if ($ownTransaction && $connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
    }
}
