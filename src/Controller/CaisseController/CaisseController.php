<?php

namespace App\Controller\CaisseController;

use App\Entity\Caisse;
use App\Services\CaisseService\CaisseService;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\UseCase\CaisseUseCase\GestCaisseUseCase;
use Doctrine\ORM\EntityManagerInterface;
use App\UseCase\CaisseUseCase\GetTransactionsForOpenCaisseUseCase;

class CaisseController extends AbstractController
{
    private $handleCaisseTransactionUseCase;
    private $caisseService;
    private $gestCaisseUseCase;
    private $getTransactionsForOpenCaisseUseCase;
    private $entityManager;
    public function __construct(
        HandleCaisseTransactionUseCase $handleCaisseTransactionUseCase,
        CaisseService $caisseService,
        GestCaisseUseCase $gestCaisseUseCase,
        EntityManagerInterface $entityManager,
        GetTransactionsForOpenCaisseUseCase $getTransactionsForOpenCaisseUseCase,
    ) {
        $this->handleCaisseTransactionUseCase = $handleCaisseTransactionUseCase;
        $this->caisseService = $caisseService;
        $this->gestCaisseUseCase = $gestCaisseUseCase;
        $this->entityManager = $entityManager;
        $this->getTransactionsForOpenCaisseUseCase = $getTransactionsForOpenCaisseUseCase;
    }

    /**
     * @Route("api/caisse/open", name="caisse_open", methods={"POST"})
     */
    public function openCaisse(): JsonResponse
    {
        // Vérifier si une caisse est déjà ouverte
        $existingCaisse = $this->caisseService->getOpenCaisse();
        if ($existingCaisse) {
            return new JsonResponse(['error' => 'A caisse is already open'], 400);
        }

        // Récupérer la dernière caisse fermée et calculer le montant initial
        $lastClosedCaisse = $this->caisseService->getLastClosedCaisse();
        $initialAmount = $lastClosedCaisse ? $lastClosedCaisse->getAmountTotal() : 0.0;

        // Créer une nouvelle caisse
        $caisse = new Caisse();
        $caisse->setAmountTotal($initialAmount);
        $caisse->setCreatedAt(new \DateTime());
        $caisse->setOpen(true);

        $this->entityManager->persist($caisse);
        $this->entityManager->flush();

        // Utiliser le UseCase pour gérer l'ouverture de la caisse (transaction)
        $this->handleCaisseTransactionUseCase->execute(null, $this->getUser(), $initialAmount, 4); // 4 pour Ouverture

        return new JsonResponse(['message' => 'Caisse opened successfully', 'caisse_id' => $caisse->getId()], 201);
    }

    /**
     * @Route("api/caisse/close", name="caisse_close", methods={"POST"})
     */
    public function closeCaisse(): JsonResponse
    {
        $caisse = $this->caisseService->getOpenCaisse();
        if (!$caisse) {
            return new JsonResponse(['error' => 'No open caisse found'], 400);
        }

        $caisse->setOpen(false);

        // Utiliser le UseCase pour gérer la fermeture de la caisse
        $this->handleCaisseTransactionUseCase->execute(null, $this->getUser(), $caisse->getAmountTotal(), 5); // 5 pour Fermeture

        return new JsonResponse(['message' => 'Caisse closed successfully', 'caisse_id' => $caisse->getId()], 200);
    }

    /**
     * @Route("api/caisse/deposit", name="caisse_deposit", methods={"POST"})
     */
    public function deposit(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $amount = $data['amount'] ?? null;
        if ($amount <= 0) {
            return new JsonResponse(['error' => 'Invalid amount'], 400);
        }

        $caisse = $this->caisseService->getOpenCaisse();
        if (!$caisse) {
            return new JsonResponse(['error' => 'No open caisse found'], 400);
        }

        // Utiliser le UseCase pour gérer le dépôt
        $this->handleCaisseTransactionUseCase->execute(null, $this->getUser(), $amount, 3); // 3 pour Dépôt

        return new JsonResponse(['message' => 'Deposit successful', 'new_total' => $caisse->getAmountTotal()], 201);
    }

    /**
     * @Route("api/caisse/withdraw", name="caisse_withdraw", methods={"POST"})
     */
    public function withdraw(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $amount = $data['amount'] ?? null;
        if ($amount <= 0) {
            return new JsonResponse(['error' => 'Invalid amount'], 400);
        }

        $caisse = $this->caisseService->getOpenCaisse();
        if (!$caisse) {
            return new JsonResponse(['error' => 'No open caisse found'], 400);
        }

        if ($caisse->getAmountTotal() < $amount) {
            return new JsonResponse(['error' => 'Insufficient funds in the caisse'], 400);
        }

        // Utiliser le UseCase pour gérer le retrait
        $this->handleCaisseTransactionUseCase->execute(null, $this->getUser(), $amount, 6); // 6 pour Retrait

        return new JsonResponse(['message' => 'Withdrawal successful', 'new_total' => $caisse->getAmountTotal()], 201);
    }

    #[Route('api/caisse', name: 'get_caisse', methods: ['GET'])]
    public function getCaisse(Request $request): JsonResponse
    {
        $days = $request->query->get('days');
        $days = $days !== null ? (int)$days : null; // Conversion sécurisée en entier si non null
        
        $caisseDTOs = $this->gestCaisseUseCase->execute($days);
        
        // Si aucune caisse n'est trouvée, renvoyer une liste vide avec un code 200
        if (empty($caisseDTOs)) {
            return $this->json([], JsonResponse::HTTP_OK);
        }
        
        $caisseDatas = array_map(fn($dto) => $dto->toArray(), $caisseDTOs);
        
        return $this->json($caisseDatas);
    }
     /**
     * @Route("api/caisse/transactions", name="get_open_caisse_transactions", methods={"GET"})
     */
    public function getOpenCaisseTransactions(): JsonResponse
    {
        $transactions = $this->getTransactionsForOpenCaisseUseCase->execute();

        if (empty($transactions)) {
            return new JsonResponse(['message' => 'Aucune transaction trouvée pour la caisse ouverte'], 404);
        }

        $transactionData = array_map(fn($transaction) => [
            'id' => $transaction->getId(),
            'amount' => $transaction->getAmount(),
            'transactionDate' => $transaction->getTransactionDate()->format('Y-m-d H:i:s'),
            'transactionType' => $transaction->getTransactionType()->getName(),
        ], $transactions);

        return new JsonResponse($transactionData);
    }
}

