<?php

namespace App\Controller\CaisseController;

use App\Entity\Caisse;
use App\Services\CaisseService\CaisseService;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use App\UseCase\CaisseUseCase\GestCaisseUseCase;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\CaisseUseCase\GetTransactionsForOpenCaisseUseCase;
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;

class CaisseController extends AbstractController
{
    private HandleCaisseTransactionUseCase $handleCaisseTransactionUseCase;
    private CaisseService $caisseService;
    private GestCaisseUseCase $gestCaisseUseCase;
    private TenantEntityManagerProvider $emProvider;
    private GetTransactionsForOpenCaisseUseCase $getTransactionsForOpenCaisseUseCase;
    private TenantCacheService $cache;

    public function __construct(
        HandleCaisseTransactionUseCase $handleCaisseTransactionUseCase,
        CaisseService $caisseService,
        GestCaisseUseCase $gestCaisseUseCase,
        TenantEntityManagerProvider $emProvider,
        GetTransactionsForOpenCaisseUseCase $getTransactionsForOpenCaisseUseCase,
        TenantCacheService $cache
    ) {
        $this->handleCaisseTransactionUseCase = $handleCaisseTransactionUseCase;
        $this->caisseService = $caisseService;
        $this->gestCaisseUseCase = $gestCaisseUseCase;
        $this->emProvider = $emProvider;
        $this->getTransactionsForOpenCaisseUseCase = $getTransactionsForOpenCaisseUseCase;
        $this->cache = $cache;
    }

    /**
     * Ouvre une caisse
     */
    #[Route('/api/caisse/open', name: 'caisse_open', methods: ['POST'])]
    public function openCaisse(): JsonResponse
    {
        // Vérifier si une caisse est déjà ouverte
        $existingCaisse = $this->caisseService->getOpenCaisse();
        if ($existingCaisse) {
            return new JsonResponse(['error' => 'A caisse is already open'], 400);
        }

        // Récupérer la dernière caisse fermée pour déterminer le montant initial
        $lastClosedCaisse = $this->caisseService->getLastClosedCaisse();
        $initialAmount = $lastClosedCaisse ? $lastClosedCaisse->getAmountTotal() : 0.0;

        // Créer une nouvelle caisse
        $caisse = new Caisse();
        $caisse->setAmountTotal($initialAmount);
        $caisse->setCreatedAt(new \DateTime());
        $caisse->setOpen(true);

        $em = $this->emProvider->getEntityManager();
        $em->persist($caisse);
        $em->flush();

        // Gérer l'ouverture de la caisse via le UseCase (par exemple, en enregistrant une transaction d'ouverture)
        $this->handleCaisseTransactionUseCase->execute(null, $this->getUser(), $initialAmount, 4, []);
        return new JsonResponse(['message' => 'Caisse opened successfully', 'caisse_id' => $caisse->getId()], 201);
    }

    /**
     * Ferme la caisse ouverte
     */
    #[Route('/api/caisse/close', name: 'caisse_close', methods: ['POST'])]
    public function closeCaisse(): JsonResponse
    {
        $caisse = $this->caisseService->getOpenCaisse();
        if (!$caisse) {
            return new JsonResponse(['error' => 'No open caisse found'], 400);
        }

        $caisse->setOpen(false);

        // Persist change: use EntityManager from provider
        $em = $this->emProvider->getEntityManager();
        $em->flush();

        $this->handleCaisseTransactionUseCase->execute(null, $this->getUser(), $caisse->getAmountTotal(), 5, []);

        return new JsonResponse(['message' => 'Caisse closed successfully', 'caisse_id' => $caisse->getId()], 200);
    }

    /**
     * Effectuer un dépôt sur la caisse
     */
    #[Route('/api/caisse/deposit', name: 'caisse_deposit', methods: ['POST'])]
    public function deposit(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $amount = $data['amount'] ?? null;
        $cashDetails = $data['cashDetails'] ?? [];
        if ($amount === null || $amount <= 0) {
            return new JsonResponse(['error' => 'Invalid amount'], 400);
        }

        $caisse = $this->caisseService->getOpenCaisse();
        if (!$caisse) {
            return new JsonResponse(['error' => 'No open caisse found'], 400);
        }

        $this->handleCaisseTransactionUseCase->execute(null, $this->getUser(), $amount, 3, $cashDetails);

        // Le service CaisseService devrait mettre à jour le montant total en interne ou via event subscriber
        // Ici on suppose que la mise à jour de l'entité est faite via le UseCase ou listener

        return new JsonResponse(['message' => 'Deposit successful', 'new_total' => $caisse->getAmountTotal()], 201);
    }

    /**
     * Effectuer un dépôt de fond de caisse
     */
    #[Route('/api/caisse/cashfunddeposit', name: 'caisse_cash_funddeposit', methods: ['POST'])]
    public function cashFundDeposit(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $amount = $data['amount'] ?? null;
        $cashDetails = $data['cashDetails'] ?? [];
        if ($amount === null || $amount <= 0) {
            return new JsonResponse(['error' => 'Invalid amount'], 400);
        }

        $caisse = $this->caisseService->getOpenCaisse();
        if (!$caisse) {
            return new JsonResponse(['error' => 'No open caisse found'], 400);
        }

        $this->handleCaisseTransactionUseCase->execute(null, $this->getUser(), $amount, 8, $cashDetails);

        return new JsonResponse(['message' => 'Deposit successful', 'new_total' => $caisse->getAmountTotal()], 201);
    }

    /**
     * Effectuer un retrait sur la caisse
     */
    #[Route('/api/caisse/withdraw', name: 'caisse_withdraw', methods: ['POST'])]
    public function withdraw(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $amount = $data['amount'] ?? null;
        $cashDetails = $data['cashDetails'] ?? [];
        if ($amount === null || $amount <= 0) {
            return new JsonResponse(['error' => 'Invalid amount'], 400);
        }

        $caisse = $this->caisseService->getOpenCaisse();
        if (!$caisse) {
            return new JsonResponse(['error' => 'No open caisse found'], 400);
        }

        if ($caisse->getAmountTotal() < $amount) {
            return new JsonResponse(['error' => 'Insufficient funds in the caisse'], 400);
        }

        $this->handleCaisseTransactionUseCase->execute(null, $this->getUser(), $amount, 6, $cashDetails);

        return new JsonResponse(['message' => 'Withdrawal successful', 'new_total' => $caisse->getAmountTotal()], 201);
    }

    /**
     * Effectuer un retrait de fond de caisse
     */
    #[Route('/api/caisse/cashfundwithdraw', name: 'caisse_cash_fund_withdraw', methods: ['POST'])]
    public function cashFundWithdraw(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $amount = $data['amount'] ?? null;
        $cashDetails = $data['cashDetails'] ?? [];
        if ($amount === null || $amount <= 0) {
            return new JsonResponse(['error' => 'Invalid amount'], 400);
        }

        $caisse = $this->caisseService->getOpenCaisse();
        if (!$caisse) {
            return new JsonResponse(['error' => 'No open caisse found'], 400);
        }

        if ($caisse->getAmountTotal() < $amount) {
            return new JsonResponse(['error' => 'Insufficient funds in the caisse'], 400);
        }

        $this->handleCaisseTransactionUseCase->execute(null, $this->getUser(), $amount, 7, $cashDetails);

        return new JsonResponse(['message' => 'Withdrawal successful', 'new_total' => $caisse->getAmountTotal()], 201);
    }

    /**
     * Récupérer la liste des caisses avec un éventuel filtre sur le nombre de jours
     */
    #[Route('/api/caisse', name: 'get_caisse', methods: ['GET'])]
    public function getCaisse(Request $request): JsonResponse
    {
        $days = $request->query->get('days');
        $days = $days !== null ? (int) $days : 'all';

        $cacheKey = "caisse_data_{$days}";

        $caisseDTOs = $this->cache->get(
            $cacheKey,
            function(ItemInterface $item) use ($days) {
                $item->expiresAfter(3600);
                $item->tag(['caisses_tag']);
                $dtoCollection = $this->gestCaisseUseCase->execute($days);
                return array_map(fn($dto) => $dto->toArray(), $dtoCollection);
            },
            /* ttl */ 3600,
            /* extraTags */ ['caisses_tag']
        );

        return new JsonResponse($caisseDTOs, JsonResponse::HTTP_OK);
    }

    /**
     * Récupérer les transactions de la caisse ouverte
     */
    #[Route('/api/caisse/transactions', name: 'get_open_caisse_transactions', methods: ['GET'])]
    public function getOpenCaisseTransactions(): JsonResponse
    {
        $cacheKey = "open_caisse_transactions";

        $tags = ['caisses_tag'];

        $transactions = $this->cache->get(
            $cacheKey,
            function(ItemInterface $item) use ($tags) {
                $item->expiresAfter(3600);
                $item->tag($tags);
                $transactionsCollection = $this->getTransactionsForOpenCaisseUseCase->execute();
                return array_map(fn($transaction) => [
                    'id'                => $transaction->getId(),
                    'amount'            => $transaction->getAmount(),
                    'transactionDate'   => $transaction->getTransactionDate()->format('Y-m-d H:i:s'),
                    'transactionType'   => $transaction->getTransactionType()->getName(),
                ], $transactionsCollection);
            }
        );

        if (empty($transactions)) {
            return new JsonResponse(['message' => 'Aucune transaction trouvée pour la caisse ouverte'], 404);
        }

        return new JsonResponse($transactions);
    }

}
