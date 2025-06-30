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
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Security\Core\Security;
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;

class PaymentsController extends AbstractController
{
    private GetPaymentsByOrderSourceUseCase $getPaymentsByOrderSourceUseCase;
    private CreatePaymentUseCase $createPaymentUseCase;
    private TenantEntityManagerProvider $emProvider;
    private Security $security;
    private TenantCacheService $cache;

    public function __construct(
        GetPaymentsByOrderSourceUseCase $getPaymentsByOrderSourceUseCase,
        CreatePaymentUseCase $createPaymentUseCase,
        TenantEntityManagerProvider $emProvider,
        Security $security,
        TenantCacheService $cache
    ) {
        $this->getPaymentsByOrderSourceUseCase = $getPaymentsByOrderSourceUseCase;
        $this->createPaymentUseCase = $createPaymentUseCase;
        $this->emProvider = $emProvider;
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

        $em = $this->emProvider->getEntityManager();
        $userOrders = $em->getRepository(Order::class)->findBy(['user' => $user]);
        if (!$userOrders) {
            return $this->json(['error' => 'Unauthorized access to payments'], JsonResponse::HTTP_FORBIDDEN);
        }

        $cacheKey = 'payments_orderSource_' . $orderSourceId . ($days ? '_days_' . (int)$days : '');

        $paymentDTOs = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($orderSourceId, $host, $days) {
                $item->expiresAfter(300);
                $item->tag(['payments']);
                return $this->getPaymentsByOrderSourceUseCase->execute((int)$orderSourceId, $host, $days ? (int)$days : null);
            },
        );

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

        $cacheKey = 'square_config';
        $host = $request->getSchemeAndHttpHost();

        $squareConfigData = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($host) {
                $item->expiresAfter(3600); 
                $item->tag(['square_config']);
                $em = $this->emProvider->getEntityManager();
                $squareConfig = $em
                    ->getRepository(SquareConfig::class)
                    ->findOneBy(['isActive' => true]);
                if (!$squareConfig) {
                    return null;
                }
                return [
                    'applicationId' => $squareConfig->getApplicationId(),
                    'locationId'    => $squareConfig->getLocationId(),
                ];
            },
            /* ttl */ 3600,
            /* extraTags */ ['square_config']
        );

        if (!$squareConfigData) {
            return $this->json(['success' => false, 'error' => 'Configuration Square introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true, 'data' => $squareConfigData]);
    }
}
