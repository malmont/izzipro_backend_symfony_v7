<?php

namespace App\UseCase\CaisseUseCase;

use App\Entity\Caisse;
use App\Entity\User;
use App\Services\CaisseService\CaisseService;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\CashRegisterResolver;
use App\Services\TenantCacheService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Opérations d'une session de caisse (routes /api/caisse/*), par poste depuis le 15/10/2026 : ouverture (montant
 * reporté de la dernière session fermée du MÊME poste), fermeture (closedAt), dépôts et retraits. Le poste est choisi
 * par CashRegisterResolver (cashRegisterId facultatif : poste unique sinon). Refus : CashRegisterException.
 */
class CaisseOperationsUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly CashRegisterResolver $registers,
        private readonly CaisseService $caisseService,
        private readonly HandleCaisseTransactionUseCase $transactions,
        private readonly TenantCacheService $cache
    ) {
    }

    /**
     * Ouverture d'une session : solde ET fond de caisse reportés de la dernière session fermée du même poste (l'argent
     * reste dans le tiroir). $cashFund (cents, facultatif, 19/10/2026) : fond de caisse ajouté à l'ouverture, enregistré
     * comme un « Ajout fond de caisse » (type 8), comme POST /api/caisse/cashfunddeposit.
     */
    public function open(?int $cashRegisterId, User $user, mixed $cashFund = null): Caisse
    {
        if ($cashFund !== null && (!is_numeric($cashFund) || (float) $cashFund < 0)) {
            throw new CashRegisterException('INVALID_AMOUNT', 'cashFund : montant en cents, positif ou nul, attendu', 400);
        }
        $register = $this->registers->resolve($cashRegisterId);
        if ($this->caisseService->getOpenCaisse($register)) {
            throw new CashRegisterException('CAISSE_ALREADY_OPEN', sprintf('Une session est déjà ouverte sur le poste « %s »', $register->getName()), 400);
        }
        $lastClosed = $this->caisseService->getLastClosedCaisse($register);
        $initialAmount = $lastClosed ? (float) $lastClosed->getAmountTotal() : 0.0;
        $initialFund = $lastClosed ? (float) ($lastClosed->getFonDeCaisse() ?? 0) : 0.0;

        $caisse = (new Caisse())->setCashRegister($register)->setAmountTotal($initialAmount)->setFonDeCaisse($initialFund)->setCreatedAt(new \DateTime())->setOpen(true);
        $em = $this->emProvider->getEntityManager();
        try {
            $em->persist($caisse);
            $em->flush();
        } catch (UniqueConstraintViolationException $e) { // deux ouvertures simultanées : l'index unique partiel tranche
            throw new CashRegisterException('CAISSE_ALREADY_OPEN', sprintf('Une session est déjà ouverte sur le poste « %s »', $register->getName()), 400);
        }
        $this->transactions->execute($caisse, null, $user, $initialAmount, HandleCaisseTransactionUseCase::OPENING);
        if ($cashFund !== null && (float) $cashFund > 0) {
            $this->transactions->execute($caisse, null, $user, (float) $cashFund, HandleCaisseTransactionUseCase::CASH_FUND_DEPOSIT);
        }

        return $caisse;
    }

    public function close(?int $cashRegisterId, User $user): Caisse
    {
        $caisse = $this->registers->requireOpenSession($cashRegisterId);
        // Transaction de fermeture enregistrée avant de fermer la session (sinon elle n'avait plus de session ouverte : 500 avant le 15/10/2026)
        $this->transactions->execute($caisse, null, $user, (float) $caisse->getAmountTotal(), HandleCaisseTransactionUseCase::CLOSING);
        $caisse->setOpen(false)->setClosedAt(new \DateTime());
        $this->emProvider->getEntityManager()->flush();
        $this->cache->invalidateTags(['caisses_tag']);

        return $caisse;
    }

    /** Dépôt (3), retrait (6), ajout (8) ou retrait (7) du fond de caisse, sur la session ouverte du poste */
    public function move(?int $cashRegisterId, User $user, mixed $amount, int $transactionTypeId, array $cashDetails): Caisse
    {
        if (!is_numeric($amount) || (float) $amount <= 0) {
            throw new CashRegisterException('INVALID_AMOUNT', 'Montant invalide', 400);
        }
        $caisse = $this->registers->requireOpenSession($cashRegisterId);
        $this->transactions->execute($caisse, null, $user, (float) $amount, $transactionTypeId, is_array($cashDetails) ? $cashDetails : []);

        return $caisse;
    }
}
