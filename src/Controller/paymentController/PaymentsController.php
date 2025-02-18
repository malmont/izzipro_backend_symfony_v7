<?php
namespace App\Controller\paymentController;

use App\UseCase\PaymentUseCase\CreatePaymentUseCase;
use App\UseCase\PaymentUseCase\GetPaymentsByOrderSourceUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Entity\SquareConfig;
use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;

class PaymentsController extends AbstractController
{
    private GetPaymentsByOrderSourceUseCase $getPaymentsByOrderSourceUseCase;
    private CreatePaymentUseCase $createPaymentUseCase;
    private EntityManagerInterface $entityManager;
    private Security $security;

    public function __construct(
        GetPaymentsByOrderSourceUseCase $getPaymentsByOrderSourceUseCase,
        CreatePaymentUseCase $createPaymentUseCase,
        EntityManagerInterface $entityManager,
        Security $security
    ) {
        $this->getPaymentsByOrderSourceUseCase = $getPaymentsByOrderSourceUseCase;
        $this->createPaymentUseCase = $createPaymentUseCase;
        $this->entityManager = $entityManager;
        $this->security = $security;
    }

    #[Route('api/payments', name: 'get_payments', methods: ['GET'])]
    public function getPayments(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $orderSourceId = $request->query->get('orderSource');
        if (!$orderSourceId) {
            return $this->json(['error' => 'orderSource parameter is required'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $days = $request->query->get('days');
        $host = $request->getSchemeAndHttpHost();

        // Vérification que l'utilisateur a bien accès aux paiements liés à ses commandes
        $userOrders = $this->entityManager->getRepository(Order::class)->findBy(['userId' => $user]);

        if (!$userOrders) {
            return $this->json(['error' => 'Unauthorized access to payments'], JsonResponse::HTTP_FORBIDDEN);
        }

        $paymentDTOs = $this->getPaymentsByOrderSourceUseCase->execute(
            (int)$orderSourceId,
            $host,
            $days ? (int)$days : null
        );

        if (empty($paymentDTOs)) {
            return $this->json([], JsonResponse::HTTP_OK);
        }

        $paymentData = array_map(fn($dto) => $dto->toArray(), $paymentDTOs);
        return $this->json($paymentData);
    }

    /**
     * @Route("/api/payment", name="process_payment", methods={"POST"})
     */
    public function processPayment(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $nonce = $data['nonce'] ?? null;
        $amount = $data['amount'] ?? null;
        if (!$nonce || !$amount ) {
            return $this->json(['success' => false, 'error' => 'Invalid data.'], 400);
        }

        // Vérification du montant
        if ($amount <= 0) {
            return $this->json(['error' => 'Invalid payment amount'], JsonResponse::HTTP_BAD_REQUEST);
        }

        // 🟢 Exécution du paiement
        $result = $this->createPaymentUseCase->execute($nonce, $amount * 100);

        if ($result['success']) {
            $payment = $result['payment'];

            // 🔎 Extraction des informations demandées
            $response = [
                'success' => true,
                'payment' => [
                    'squarePaymentId'   => $payment->getId(),
                    'squareOrderId'     => $payment->getOrderId(),
                    'squareReceiptUrl'  => $payment->getReceiptUrl(),
                    'squareStatus'      => $payment->getStatus(),
                    'squareCardBrand'   => $payment->getCardDetails()->getCard()->getCardBrand(),
                    'squareLast4'       => $payment->getCardDetails()->getCard()->getLast4(),
                    'squareRiskLevel'   => $payment->getRiskEvaluation()->getRiskLevel()
                ]
            ];

            return $this->json($response);
        }

        return $this->json(['success' => false, 'errors' => $result['errors']], 500);
    }

    /**
     * Récupérer applicationId et locationId pour le front
     */
    #[Route('/api/square-config', name: 'get_square_config', methods: ['GET'])]
    public function getSquareConfig(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $squareConfig = $this->entityManager
            ->getRepository(SquareConfig::class)
            ->findOneBy(['isActive' => true]);

        if (!$squareConfig) {
            return $this->json(['success' => false, 'error' => 'Configuration Square introuvable.'], 404);
        }

        return $this->json([
            'success' => true,
            'data' => [
                'applicationId' => $squareConfig->getApplicationId(),
                'locationId' => $squareConfig->getLocationId(),
            ]
        ]);
    }
}
