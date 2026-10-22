<?php

namespace App\UseCase\CaisseUseCase;

use App\Dto\CloseCaisseInputDto;
use App\Entity\Caisse;
use App\Entity\CashRegisterReport;
use App\Entity\User;
use App\Services\CaisseService\CashSessionReportBuilder;
use App\Services\CaisseService\DenominationService;
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
        private readonly TenantCacheService $cache,
        private readonly CashSessionReportBuilder $reports,
        private readonly DenominationService $denominations,
        private readonly \App\Services\PosAuditService\PosAuditService $audit,
        private readonly \App\Services\PosAuditService\PosApprovalService $approvals,
        private readonly \App\Services\PosAuditService\PosIdempotencyService $idempotency,
        private readonly \App\Services\PosAuditService\PosWriteResponseBuilder $responses
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
        // Fermeture avec comptage (rapport Z) : seul le fond laissé dans le tiroir (keptFund) est reporté, le reste est
        // parti en banque (19/10/2026)
        $lastReport = $lastClosed ? $this->emProvider->getEntityManager()->getRepository(CashRegisterReport::class)->findOneBySession($lastClosed) : null;
        if ($lastReport && $lastReport->getKeptFund() !== null) {
            $initialAmount = $initialFund = (float) $lastReport->getKeptFund();
        }

        $caisse = (new Caisse())->setCashRegister($register)->setAmountTotal($initialAmount)->setFonDeCaisse($initialFund)->setCreatedAt(new \DateTime())->setOpen(true);
        $em = $this->emProvider->getEntityManager();
        $connection = $em->getConnection();
        $connection->beginTransaction(); // ouverture, transactions et journal ensemble (C2)
        try {
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
            $this->audit->record(\App\Services\PosAuditService\PosAuditService::SESSION_OPENED, [
                'openingAmount' => (int) round($initialAmount), 'openingFund' => (int) round($initialFund), 'cashFund' => $cashFund !== null ? (int) $cashFund : 0,
                'amountTotal' => (int) round((float) $caisse->getAmountTotal()),
            ], $caisse, null, 'session', $caisse->getId(), $user);
            $this->idempotency->attach('session_open', $caisse->getId(), $this->responses->open($caisse), 201); // C5
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }

        return $caisse;
    }

    /**
     * Fermeture d'une session (19/10/2026 : comptage et rapport Z). Sous verrou de la ligne du poste, dans une seule
     * transaction SQL : comptage contrôlé (COUNT_MISMATCH, DISCREPANCY_REASON_REQUIRED, INVALID_AMOUNT), transaction
     * « Fermeture » (avec le détail des coupures), session fermée, rapport Z numéroté (suite sans trou par poste) et figé.
     * Un second envoi trouve la session fermée : 400 CASH_REGISTER_CLOSED avec le dernier Z du poste.
     */
    public function close(?int $cashRegisterId, User $user, ?CloseCaisseInputDto $input = null, string $locale = 'fr'): CashRegisterReport
    {
        $input ??= new CloseCaisseInputDto($cashRegisterId);
        $register = $this->registers->resolve($cashRegisterId);
        $em = $this->emProvider->getEntityManager();
        $reportRepository = $em->getRepository(CashRegisterReport::class);
        $connection = $em->getConnection();
        $connection->beginTransaction();
        try {
            $connection->executeQuery('SELECT id FROM cash_register WHERE id = ? FOR UPDATE', [$register->getId()]);
            $caisse = $this->registers->openSession($register);
            if ($caisse) {
                $em->refresh($caisse);
            }
            if (!$caisse || !$caisse->isOpen()) {
                $last = $reportRepository->findLastForRegister($register);
                throw new CashRegisterException('CASH_REGISTER_CLOSED', sprintf('Aucune session ouverte sur le poste « %s »', $register->getName()), 400, [],
                    $last ? ['lastReport' => ['id' => $last->getId(), 'number' => $last->getNumber()]] : []);
            }

            $snapshot = $this->reports->build($caisse, $locale);
            $expected = (int) $snapshot['expectedCash'];
            $counted = $input->countedCash;
            $countLines = null;
            if ($input->cashDetails !== null) {
                $count = $this->denominations->count($input->cashDetails);
                if ($counted !== null && $counted !== $count['total']) {
                    throw new CashRegisterException('COUNT_MISMATCH', sprintf('Le détail des coupures fait %d cents, countedCash en annonce %d', $count['total'], $counted), 400, [], ['countedFromDetails' => $count['total']]);
                }
                $counted = $count['total'];
                $countLines = $count['lines'];
            }
            $discrepancy = $keptFund = $bankDeposit = null;
            if ($counted !== null) {
                $discrepancy = $counted - $expected;
                if (abs($discrepancy) > (int) $snapshot['discrepancyThreshold'] && $input->discrepancyReason === null) {
                    throw new CashRegisterException('DISCREPANCY_REASON_REQUIRED', sprintf('Écart de %d cents entre espèces comptées et attendues : motif requis', $discrepancy), 400, [],
                        ['expectedCash' => $expected, 'countedCash' => $counted, 'discrepancy' => $discrepancy]);
                }
                $keptFund = $input->keptFund ?? $counted;
                if ($keptFund > $counted) {
                    throw new CashRegisterException('INVALID_AMOUNT', 'keptFund : entre 0 et countedCash', 400);
                }
                $bankDeposit = $counted - $keptFund;
            } elseif ($input->keptFund !== null) {
                throw new CashRegisterException('INVALID_AMOUNT', 'keptFund exige un comptage (countedCash ou cashDetails)', 400);
            }
            if ($counted === null) { // fermeture sans comptage : validation d'un responsable selon les réglages (C3)
                $this->approvals->requireFor(\App\Services\PosAuditService\PosApprovalService::CLOSE_WITHOUT_COUNT, $user, ['cashRegisterId' => (int) $register->getId()]);
            }

            $closedAt = new \DateTimeImmutable();
            $closingDetails = array_map(fn (array $line) => ['typeCash' => $line['typeCash'], 'nombreItems' => $line['count']], $countLines ?? []);
            $this->transactions->execute($caisse, null, $user, (float) ($counted ?? $caisse->getAmountTotal()), HandleCaisseTransactionUseCase::CLOSING, $closingDetails);
            $caisse->setOpen(false)->setClosedAt(\DateTime::createFromImmutable($closedAt));

            $report = new CashRegisterReport($register, $caisse, $reportRepository->nextNumber($register), $closedAt, $user,
                $counted, $expected, $discrepancy, $counted !== null ? $input->discrepancyReason : null, $keptFund, $bankDeposit,
                $snapshot + [
                    'closedAt' => $closedAt->format(\DateTimeInterface::ATOM),
                    'closedBy' => CashSessionReportBuilder::user($user),
                    'countedCash' => $counted,
                    'cashDetails' => $countLines,
                    'discrepancy' => $discrepancy,
                    'discrepancyReason' => $counted !== null ? $input->discrepancyReason : null,
                    'keptFund' => $keptFund,
                    'bankDeposit' => $bankDeposit,
                ]);
            $em->persist($report);
            $em->flush();
            // Journal (C2) : fermeture et rapport Z, dans la même transaction
            $Audit = \App\Services\PosAuditService\PosAuditService::class;
            $this->audit->record($Audit::SESSION_CLOSED, [
                'expectedCash' => $expected, 'countedCash' => $counted, 'discrepancy' => $discrepancy,
                'discrepancyReason' => $counted !== null ? $input->discrepancyReason : null, 'keptFund' => $keptFund, 'bankDeposit' => $bankDeposit,
                'reportId' => $report->getId(), 'reportNumber' => $report->getNumber(),
            ], $caisse, $register, 'session', $caisse->getId(), $user);
            $this->audit->record($Audit::Z_REPORT_CREATED, [
                'reportId' => $report->getId(), 'number' => $report->getNumber(), 'salesCount' => $snapshot['sales']['count'], 'salesTotal' => $snapshot['sales']['total'],
                'refundsTotal' => $snapshot['refunds']['total'], 'expectedCash' => $expected, 'receipts' => $snapshot['receipts'] ?? null, 'creditNotes' => $snapshot['creditNotes'] ?? null,
            ], $caisse, $register, 'z_report', $report->getId(), $user);
            $this->idempotency->attach('session_close', $report->getId(), $this->responses->close($report), 200); // C5
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
        $this->cache->invalidateTags(['caisses_tag']);

        return $report;
    }

    /** Dépôt (3), retrait (6), ajout (8) ou retrait (7) du fond de caisse, sur la session ouverte du poste */
    public function move(?int $cashRegisterId, User $user, mixed $amount, int $transactionTypeId, array $cashDetails): Caisse
    {
        if (!is_numeric($amount) || (float) $amount <= 0) {
            throw new CashRegisterException('INVALID_AMOUNT', 'Montant invalide', 400);
        }
        $caisse = $this->registers->requireOpenSession($cashRegisterId);
        $connection = $this->emProvider->getEntityManager()->getConnection();
        $connection->beginTransaction(); // mouvement et journal ensemble (C2)
        try {
            // Retraits : validation d'un responsable selon les réglages, consommée dans cette transaction (C3)
            if (in_array($transactionTypeId, [HandleCaisseTransactionUseCase::WITHDRAWAL, HandleCaisseTransactionUseCase::CASH_FUND_WITHDRAWAL], true)) {
                $this->approvals->requireFor(
                    $transactionTypeId === HandleCaisseTransactionUseCase::WITHDRAWAL ? \App\Services\PosAuditService\PosApprovalService::WITHDRAWAL : \App\Services\PosAuditService\PosApprovalService::FUND_WITHDRAWAL,
                    $user, ['amount' => (int) round((float) $amount), 'cashRegisterId' => (int) $caisse->getCashRegister()->getId()], (int) round((float) $amount));
            }
            $this->transactions->execute($caisse, null, $user, (float) $amount, $transactionTypeId, is_array($cashDetails) ? $cashDetails : []);
            $Audit = \App\Services\PosAuditService\PosAuditService::class;
            $this->audit->record(match ($transactionTypeId) {
                HandleCaisseTransactionUseCase::DEPOSIT => $Audit::CASH_DEPOSIT,
                HandleCaisseTransactionUseCase::WITHDRAWAL => $Audit::CASH_WITHDRAWAL,
                HandleCaisseTransactionUseCase::CASH_FUND_DEPOSIT => $Audit::FUND_DEPOSIT,
                default => $Audit::FUND_WITHDRAWAL,
            }, ['amount' => (int) round((float) $amount), 'amountTotal' => (int) round((float) $caisse->getAmountTotal()),
                'fonDeCaisse' => (int) round((float) ($caisse->getFonDeCaisse() ?? 0)), 'cashDetails' => is_array($cashDetails) ? $cashDetails : []],
                $caisse, null, 'session', $caisse->getId(), $user);
            $this->idempotency->attach('session_move', $caisse->getId(), $this->responses->move($caisse, self::moveMessage($transactionTypeId)), 201); // C5
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }

        return $caisse;
    }

    /** Message de la réponse d'un mouvement (inchangé depuis l'origine) */
    public static function moveMessage(int $transactionTypeId): string
    {
        return in_array($transactionTypeId, [HandleCaisseTransactionUseCase::WITHDRAWAL, HandleCaisseTransactionUseCase::CASH_FUND_WITHDRAWAL], true) ? 'Withdrawal successful' : 'Deposit successful';
    }

    /** Ouverture du tiroir-caisse hors encaissement (C2) : session ouverte et motif obligatoires ; seulement journalisée */
    public function openDrawer(?int $cashRegisterId, User $user, mixed $reason): array
    {
        $reason = is_scalar($reason) ? trim((string) $reason) : '';
        if ($reason === '') {
            throw new CashRegisterException('REASON_REQUIRED', 'reason : motif de l\'ouverture du tiroir requis', 400);
        }
        $caisse = $this->registers->requireOpenSession($cashRegisterId);
        $connection = $this->emProvider->getEntityManager()->getConnection();
        $connection->beginTransaction(); // validation (C3) et journal ensemble
        try {
            $this->approvals->requireFor(\App\Services\PosAuditService\PosApprovalService::DRAWER_OPEN, $user, ['cashRegisterId' => (int) $caisse->getCashRegister()->getId()]);
            $entry = $this->audit->record(\App\Services\PosAuditService\PosAuditService::DRAWER_OPENED, ['reason' => mb_substr($reason, 0, 255)], $caisse, null, 'session', $caisse->getId(), $user);
            $this->idempotency->attach('drawer', $entry['id'], ['auditId' => $entry['id'], 'sequence' => $entry['sequence'], 'occurredAt' => $entry['occurredAt'], 'cashRegisterId' => $caisse->getCashRegister()->getId()], 201); // C5
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }

        return ['auditId' => $entry['id'], 'sequence' => $entry['sequence'], 'occurredAt' => $entry['occurredAt'], 'cashRegisterId' => $caisse->getCashRegister()->getId()];
    }
}
