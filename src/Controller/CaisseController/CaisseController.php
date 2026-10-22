<?php

namespace App\Controller\CaisseController;

use App\Dto\CashRegisterReportOutputDto;
use App\Dto\CloseCaisseInputDto;
use App\Entity\Caisse;
use App\Entity\User;
use App\Services\CaisseService\DenominationService;
use App\UseCase\CaisseUseCase\CashReportsUseCase;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\CashRegisterResolver;
use App\Services\TenantCacheService;
use App\UseCase\CaisseUseCase\CaisseOperationsUseCase;
use App\UseCase\CaisseUseCase\GestCaisseUseCase;
use App\UseCase\CaisseUseCase\GetTransactionsForOpenCaisseUseCase;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Sessions de caisse (POS, application de gestion). Plusieurs postes par site depuis le 15/10/2026 : chaque route
 * accepte un cashRegisterId FACULTATIF (corps JSON des POST, paramètre de requête des GET) ; absent, le poste unique
 * du site est pris (CashRegisterResolver), donc rien ne change pour un site à un seul poste. Les réponses portent
 * cashRegisterId en plus de leurs champs d'avant. Codes HTTP de succès inchangés (close : 200, le reste : 201).
 * Refus : {error, message} (docs/caisse.md).
 */
class CaisseController extends AbstractController
{
    public function __construct(
        private readonly CaisseOperationsUseCase $operations,
        private readonly CashRegisterResolver $registers,
        private readonly GestCaisseUseCase $gestCaisseUseCase,
        private readonly GetTransactionsForOpenCaisseUseCase $getTransactionsForOpenCaisseUseCase,
        private readonly TenantCacheService $cache,
        private readonly CashReportsUseCase $reports,
        private readonly DenominationService $denominations,
        private readonly \App\Services\PosAuditService\PosWriteResponseBuilder $responses,
        private readonly \App\Services\PosTerminalService\PosTerminalPaymentService $terminalPayments,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
    }

    /** Réconciliation des paiements par terminal (C4) à l'ouverture et à la fermeture : jamais d'effet sur la réponse */
    private function reconcileTerminal(): void
    {
        try {
            $this->terminalPayments->reconcile();
        } catch (\Throwable $e) {
            $this->logger->warning('[PosTerminal] réconciliation à l\'ouverture/fermeture : ' . $e->getMessage());
        }
    }

    #[Route('/api/caisse/open', name: 'caisse_open', methods: ['POST'])]
    public function openCaisse(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $data = json_decode($request->getContent(), true);
            $caisse = $this->operations->open($this->registerIdFromBody($request), $this->user(), is_array($data) ? ($data['cashFund'] ?? null) : null);
            $this->reconcileTerminal();

            // amountTotal, fonDeCaisse et createdAt ajoutés le 19/10/2026 (champs d'avant inchangés) ; corps partagé avec le rejeu d'idempotence (C5)
            return new JsonResponse($this->responses->open($caisse), 201);
        });
    }

    /**
     * Fermeture (19/10/2026 : comptage facultatif {countedCash?, cashDetails?, discrepancyReason?, keptFund?}, rapport Z
     * créé dans tous les cas) ; champs d'avant inchangés, rapport Z complet sous « report ». Code de succès : 200.
     */
    #[Route('/api/caisse/close', name: 'caisse_close', methods: ['POST'])]
    public function closeCaisse(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $data = json_decode($request->getContent(), true);
            $input = CloseCaisseInputDto::fromArray(is_array($data) ? $data : []);
            $report = $this->operations->close($input->cashRegisterId, $this->user(), $input, $this->locale($request));
            $this->reconcileTerminal();

            return new JsonResponse($this->responses->close($report), 200);
        });
    }

    /** Coupures (billets, pièces) de la devise du site, à utiliser dans cashDetails : [{id, label, valueCents, kind, active}] */
    #[Route('/api/caisse/denominations', name: 'caisse_denominations', methods: ['GET'])]
    public function denominations(): JsonResponse
    {
        return new JsonResponse($this->denominations->list());
    }

    /** Rapport X de la session ouverte du poste (lecture seule) ; 409 CASH_REGISTER_CLOSED sans session ouverte */
    #[Route('/api/caisse/report/x', name: 'caisse_report_x', methods: ['GET'])]
    public function reportX(Request $request): JsonResponse
    {
        return $this->guard(fn () => new JsonResponse($this->reports->x($this->registerIdFromQuery($request), $this->locale($request), $request->query->get('purpose'))));
    }

    /** Ouverture du tiroir hors encaissement {cashRegisterId?, reason} → 201 ; journalisée (C2) */
    #[Route('/api/caisse/drawer-open', name: 'caisse_drawer_open', methods: ['POST'])]
    public function drawerOpen(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $data = json_decode($request->getContent(), true);

            return new JsonResponse($this->operations->openDrawer($this->registerIdFromBody($request), $this->user(), is_array($data) ? ($data['reason'] ?? null) : null), 201);
        });
    }

    /** Rapports Z d'un poste, les plus récents d'abord (?cashRegisterId=, ?from=, ?to= : AAAA-MM-JJ) */
    #[Route('/api/caisse/report/z', name: 'caisse_report_z_list', methods: ['GET'])]
    public function reportZList(Request $request): JsonResponse
    {
        return $this->guard(fn () => new JsonResponse($this->reports->listZ($this->registerIdFromQuery($request), $request->query->get('from'), $request->query->get('to'))));
    }

    /** Détail d'un rapport Z (identifiant unique du rapport ; le numéro n'est unique que par poste) */
    #[Route('/api/caisse/report/z/{id}', name: 'caisse_report_z', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function reportZ(int $id): JsonResponse
    {
        return $this->guard(fn () => new JsonResponse($this->reports->z($id)));
    }

    private function locale(Request $request): string
    {
        $locale = (string) $request->query->get('locale', 'fr');

        return preg_match('/^[a-z]{2}$/', $locale) ? $locale : 'fr';
    }

    #[Route('/api/caisse/deposit', name: 'caisse_deposit', methods: ['POST'])]
    public function deposit(Request $request): JsonResponse
    {
        return $this->move($request, HandleCaisseTransactionUseCase::DEPOSIT, 'Deposit successful');
    }

    #[Route('/api/caisse/cashfunddeposit', name: 'caisse_cash_funddeposit', methods: ['POST'])]
    public function cashFundDeposit(Request $request): JsonResponse
    {
        return $this->move($request, HandleCaisseTransactionUseCase::CASH_FUND_DEPOSIT, 'Deposit successful');
    }

    #[Route('/api/caisse/withdraw', name: 'caisse_withdraw', methods: ['POST'])]
    public function withdraw(Request $request): JsonResponse
    {
        return $this->move($request, HandleCaisseTransactionUseCase::WITHDRAWAL, 'Withdrawal successful');
    }

    #[Route('/api/caisse/cashfundwithdraw', name: 'caisse_cash_fund_withdraw', methods: ['POST'])]
    public function cashFundWithdraw(Request $request): JsonResponse
    {
        return $this->move($request, HandleCaisseTransactionUseCase::CASH_FUND_WITHDRAWAL, 'Withdrawal successful');
    }

    /** Sessions de caisse : ?days= (créées depuis N jours) ; ?cashRegisterId= filtre sur un poste, sinon tous les postes */
    #[Route('/api/caisse', name: 'get_caisse', methods: ['GET'])]
    public function getCaisse(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $days = $request->query->get('days');
            $days = $days !== null ? (int) $days : null;
            $registerId = $this->registerIdFromQuery($request);
            $register = $registerId !== null ? $this->registers->resolve($registerId) : null;
            $cacheKey = 'caisse_data_' . ($days ?? 'all') . '_' . ($registerId ?? 'all');

            $caisseDTOs = $this->cache->get($cacheKey, function (ItemInterface $item) use ($days, $register) {
                $item->expiresAfter(3600);
                $item->tag(['caisses_tag']);

                return array_map(fn ($dto) => $dto->toArray(), $this->gestCaisseUseCase->execute($days, $register));
            }, 3600, ['caisses_tag']);

            return new JsonResponse($caisseDTOs, JsonResponse::HTTP_OK);
        });
    }

    /** Transactions de la session ouverte du poste (?cashRegisterId=, sinon poste unique) ; 404 si aucune, comme avant */
    #[Route('/api/caisse/transactions', name: 'get_open_caisse_transactions', methods: ['GET'])]
    public function getOpenCaisseTransactions(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $register = $this->registers->resolve($this->registerIdFromQuery($request));
            $transactions = $this->cache->get('open_caisse_transactions_' . $register->getId(), function (ItemInterface $item) use ($register) {
                $item->expiresAfter(3600);
                $item->tag(['caisses_tag']);

                return array_map(fn ($transaction) => [
                    'id' => $transaction->getId(),
                    'amount' => $transaction->getAmount(),
                    'transactionDate' => $transaction->getTransactionDate()->format('Y-m-d H:i:s'),
                    'transactionType' => $transaction->getTransactionType()->getName(),
                    'cashRegisterId' => $register->getId(),
                ], $this->getTransactionsForOpenCaisseUseCase->execute($register));
            }, 3600, ['caisses_tag']);

            if (empty($transactions)) {
                return new JsonResponse(['message' => 'Aucune transaction trouvée pour la caisse ouverte', 'cashRegisterId' => $register->getId()], 404);
            }

            return new JsonResponse($transactions);
        });
    }

    private function move(Request $request, int $transactionTypeId, string $message): JsonResponse
    {
        return $this->guard(function () use ($request, $transactionTypeId, $message) {
            $data = json_decode($request->getContent(), true);
            $data = is_array($data) ? $data : [];
            $caisse = $this->operations->move($this->registerIdFromBody($request), $this->user(), $data['amount'] ?? null, $transactionTypeId, $data['cashDetails'] ?? []);

            return new JsonResponse($this->responses->move($caisse, $message), 201);
        });
    }

    private function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (CashRegisterException $e) {
            return new JsonResponse($e->toBody(), $e->status);
        }
    }

    private function registerIdFromBody(Request $request): ?int
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) && is_numeric($data['cashRegisterId'] ?? null) ? (int) $data['cashRegisterId'] : null;
    }

    private function registerIdFromQuery(Request $request): ?int
    {
        $value = $request->query->get('cashRegisterId');

        return is_numeric($value) ? (int) $value : null;
    }

    private function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new CashRegisterException('UNAUTHENTICATED', 'User not authenticated', 401);
        }

        return $user;
    }
}
