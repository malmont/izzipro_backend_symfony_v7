<?php
namespace App\Controller\CaisseController;

use App\Entity\Caisse;
use App\Services\CaisseService\CaisseService;;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\UseCase\CaisseUseCase\GestCaisseUseCase;


class CaisseController extends AbstractController
{
    private $handleCaisseTransactionUseCase;
    private $caisseService;
    private $gestCaisseUseCase ;

    public function __construct(HandleCaisseTransactionUseCase $handleCaisseTransactionUseCase, CaisseService $caisseService,GestCaisseUseCase $gestCaisseUseCase )
    {
        $this->handleCaisseTransactionUseCase = $handleCaisseTransactionUseCase;
        $this->caisseService = $caisseService;
        $this->gestCaisseUseCase =$gestCaisseUseCase;
    }

     /**
     * @Route("api/caisse/open", name="caisse_open", methods={"POST"})
     */
    public function openCaisse(): JsonResponse
        {
            $existingCaisse = $this->caisseService->getOpenCaisse();
            if ($existingCaisse) {
                return new JsonResponse(['error' => 'A caisse is already open'], 400);
            }

            // Récupérer la dernière caisse fermée
            $lastClosedCaisse = $this->caisseService->getLastClosedCaisse();
            $initialAmount = $lastClosedCaisse ? $lastClosedCaisse->getAmountTotal() : 0.0;

            $caisse = new Caisse();
            $caisse->setAmountTotal($initialAmount);
            $caisse->setCreatedAt(new \DateTime());
            $caisse->setOpen(true);

            $entityManager = $this->handleCaisseTransactionUseCase->getEm();
            $entityManager->persist($caisse);
            $entityManager->flush();  // Sauvegarder la nouvelle caisse dans la base de données

            // Exécuter la transaction pour l'ouverture de la caisse
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

        $this->handleCaisseTransactionUseCase->getEm()->persist($caisse);
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

        // Créer une transaction de dépôt (ID de transaction type: 3 pour Dépôt)
        $this->handleCaisseTransactionUseCase->execute(null, $this->getUser(), $amount, 3);

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

        // Créer une transaction de retrait (ID de transaction type: 6 pour Retrait)
        $this->handleCaisseTransactionUseCase->execute(null, $this->getUser(), $amount, 6);

        return new JsonResponse(['message' => 'Withdrawal successful', 'new_total' => $caisse->getAmountTotal()], 201);
    }

    #[Route('api/caisse', name: 'get_caisse', methods: ['GET'])]
    public function getCaisse(Request $request): JsonResponse
    {
        $days = $request->query->get('days');
        $days = $days !== null ? (int)$days : null; // Conversion sécurisée en entier si non null
        
        $caisseDTOs = $this->gestCaisseUseCase->execute($days);
        
        if (empty($caisseDTOs)) {
            return $this->json(['message' => 'Aucune caisse trouvée pour la période donnée'], JsonResponse::HTTP_NOT_FOUND);
        }
        
        $caisseDatas = array_map(fn($dto) => $dto->toArray(), $caisseDTOs);
        
        return $this->json($caisseDatas);
    }
    
        

}

