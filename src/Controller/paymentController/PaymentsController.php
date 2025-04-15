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
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;


class PaymentsController extends AbstractController
{
    private GetPaymentsByOrderSourceUseCase $getPaymentsByOrderSourceUseCase;
    private CreatePaymentUseCase $createPaymentUseCase;
    private EntityManagerInterface $entityManager;
    private Security $security;
    private CacheInterface $cache;

    public function __construct(
        GetPaymentsByOrderSourceUseCase $getPaymentsByOrderSourceUseCase,
        CreatePaymentUseCase $createPaymentUseCase,
        EntityManagerInterface $entityManager,
        Security $security,
        CacheInterface $cache
    ) {
        $this->getPaymentsByOrderSourceUseCase = $getPaymentsByOrderSourceUseCase;
        $this->createPaymentUseCase = $createPaymentUseCase;
        $this->entityManager = $entityManager;
        $this->security = $security;
        $this->cache = $cache;
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

        // Vérifier que l'utilisateur a bien accès aux paiements liés à ses commandes
        $userOrders = $this->entityManager->getRepository(Order::class)->findBy(['userId' => $user]);
        if (!$userOrders) {
            return $this->json(['error' => 'Unauthorized access to payments'], JsonResponse::HTTP_FORBIDDEN);
        }

        // Construction d'une clé de cache dynamique basée sur orderSource et jours
        $cacheKey = 'payments_orderSource_' . $orderSourceId . ($days ? '_days_' . (int)$days : '');

        $paymentDTOs = $this->cache->get($cacheKey, function (ItemInterface $item) use ($orderSourceId, $host, $days) {
            $item->expiresAfter(300); // 5 minutes
            $item->tag(['payments']);
            return $this->getPaymentsByOrderSourceUseCase->execute((int)$orderSourceId, $host, $days ? (int)$days : null);
        });

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
        if (!$nonce || !$amount) {
            return $this->json(['success' => false, 'error' => 'Invalid data.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($amount <= 0) {
            return $this->json(['error' => 'Invalid payment amount'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $result = $this->createPaymentUseCase->execute($nonce, $amount * 100);
        if ($result['success']) {
            $payment = $result['payment'];
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

        return $this->json(['success' => false, 'errors' => $result['errors']], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Récupérer applicationId et locationId pour le front
     */
    #[Route('/api/square-config', name: 'get_square_config', methods: ['GET'])]
    public function getSquareConfig(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $squareConfigData = $this->cache->get('square_config', function (ItemInterface $item) {
            $item->expiresAfter(3600); // 1 heure
            // Récupération de la configuration active depuis la base
            $squareConfig = $this->entityManager
                ->getRepository(SquareConfig::class)
                ->findOneBy(['isActive' => true]);
            if (!$squareConfig) {
                return null;
            }
            return [
                'applicationId' => $squareConfig->getApplicationId(),
                'locationId'    => $squareConfig->getLocationId(),
            ];
        });

        if (!$squareConfigData) {
            return $this->json(['success' => false, 'error' => 'Configuration Square introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true, 'data' => $squareConfigData]);
    }
}
