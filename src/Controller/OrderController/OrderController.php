<?php

namespace App\Controller\OrderController;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\UseCase\OrderUseCase\CreateOrderUseCase;
use App\UseCase\OrderUseCase\CancelOrderUseCase;
use App\UseCase\OrderUseCase\GetOrdersBySourceUseCase;
use App\Dto\CreateOrderDTO;
use App\Dto\PaymentMethodDTO;
use App\Dto\CreateOrderMultiPaymentDTO;
use Symfony\Component\Security\Core\Security;
use App\UseCase\OrderUseCase\GetOrdersByUserUseCase;
use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\Order;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;
use App\Services\GemsuiteImporterService\GemsuiteSaleManager;

class OrderController extends AbstractController
{
    private GetOrdersByUserUseCase $getOrdersByUserUseCase;
    private CreateOrderUseCase $createOrderUseCase;
    private CancelOrderUseCase $cancelOrderUseCase;
    private GetOrdersBySourceUseCase $getOrdersBySourceUseCase;
    private TenantEntityManagerProvider $emProvider;
    private TenantCacheService $cache;
    private GemsuiteSaleManager $gemsuiteSaleManager;

    public function __construct(
        CreateOrderUseCase $createOrderUseCase,
        CancelOrderUseCase $cancelOrderUseCase,
        GetOrdersBySourceUseCase $getOrdersBySourceUseCase,
        GetOrdersByUserUseCase $getOrdersByUserUseCase,
        TenantEntityManagerProvider $emProvider,
        TenantCacheService $cache,
        GemsuiteSaleManager $gemsuiteSaleManager
    ) {
        $this->createOrderUseCase = $createOrderUseCase;
        $this->cancelOrderUseCase = $cancelOrderUseCase;
        $this->getOrdersBySourceUseCase = $getOrdersBySourceUseCase;
        $this->getOrdersByUserUseCase = $getOrdersByUserUseCase;
        $this->emProvider = $emProvider;
        $this->cache = $cache;
        $this->gemsuiteSaleManager = $gemsuiteSaleManager;
    }

    /**
     * @Route("api/order/create", name="order_create", methods={"POST"})
     */
    public function createOrder(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!isset($data['orderSource'], $data['paymentMethod'], $data['addressId'], $data['carrierId'], $data['items'])) {
            return $this->json(['error' => 'Missing required fields'], JsonResponse::HTTP_BAD_REQUEST);
        }

        // Vérification que l'adresse appartient bien à l'utilisateur
        $em = $this->emProvider->getEntityManager();
        $address = $em->getRepository(Adress::class)->find($data['addressId']);
        if (!$address || $address->getUserAdress() !== $user) {
            return $this->json(['error' => 'Unauthorized: Invalid address'], JsonResponse::HTTP_FORBIDDEN);
        }

        // Vérification que le transporteur existe
        $carrier = $em->getRepository(Carrier::class)->find($data['carrierId']);
        if (!$carrier) {
            return $this->json(['error' => 'Carrier not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        // 🟢 Récupération des données de paiement Square
        $paymentData = $data['payment'] ?? [];

        $dto = new CreateOrderDTO(
            $user->getId(),
            $data['orderSource'],
            $data['paymentMethod'],
            $data['addressId'],
            $data['carrierId'],
            $data['typeOrder'] ?? null,
            $data['items'],
            $data['priceShipping'] ?? null,
            $paymentData['squarePaymentId'] ?? null,
            $paymentData['squareOrderId'] ?? null,
            $paymentData['squareReceiptUrl'] ?? null,
            $paymentData['squareStatus'] ?? null,
            $paymentData['squareCardBrand'] ?? null,
            $paymentData['squareLast4'] ?? null,
            $paymentData['squareRiskLevel'] ?? null
        );

         $result = $this->createOrderUseCase->execute($dto);

    if ($result instanceof Order) {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->refresh($result);
        $this->gemsuiteSaleManager->createSale($result);
        return $this->json([
            'success' => true,
            'orderId' => $result->getId(),
            'message' => 'Commande créée et synchronisée avec succès.'
        ], JsonResponse::HTTP_CREATED);
    }
        return $result;
    }

    /**
     * @Route("api/order/create-multi-payment", name="order_create_multi_payment", methods={"POST"})
     */
    public function createOrderWithMultiplePayments(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        $paymentData = $data['payment'] ?? [];
        $paymentMethods = array_map(function ($method) {
            return new PaymentMethodDTO($method['type'], $method['amount']);
        }, $data['paymentMethods'] ?? []);

        $dto = new CreateOrderMultiPaymentDTO(
            $user->getId(),
            $data['orderSource'],
            $paymentMethods,
            $data['addressId'] ?? null,
            $data['carrierId'] ?? null,
            $data['typeOrder'] ?? null,
            $data['items'] ?? [],
            $data['priceShipping'] ?? null,
            $paymentData['squarePaymentId'] ?? null,
            $paymentData['squareOrderId'] ?? null,
            $paymentData['squareReceiptUrl'] ?? null,
            $paymentData['squareStatus'] ?? null,
            $paymentData['squareCardBrand'] ?? null,
            $paymentData['squareLast4'] ?? null,
            $paymentData['squareRiskLevel'] ?? null
        );
         $result = $this->createOrderUseCase->execute($dto);
         if ($result instanceof Order) {
            $this->gemsuiteSaleManager->createSale($result);
            return $this->json([
                'success' => true,
                'orderId' => $result->getId(),
                'message' => 'Commande créée et synchronisée avec succès.'
            ], JsonResponse::HTTP_CREATED);
        }
        return $result;
    }

    /**
     * @Route("api/order/cancel/{id}", name="order_cancel", methods={"POST"})
     */
    public function cancelOrder(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $em = $this->emProvider->getEntityManager();
        $order = $em->getRepository(Order::class)->find($id);
        if (!$order) {
            return $this->json(['error' => 'Order not found'], JsonResponse::HTTP_NOT_FOUND);
        }
        if ($order->getUser() !== $user) {
            return $this->json(['error' => 'Unauthorized: You can only cancel your own orders'], JsonResponse::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        return $this->cancelOrderUseCase->execute($id, $data['paymentMethod'] ?? null);
    }

    #[Route("api/orders", name:"get_orders", methods:["GET"])]
    public function getOrders(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN'); 

        $orderSourceId = $request->query->get('orderSource');
        if (!$orderSourceId) {
            return $this->json(['error' => 'orderSource parameter is required'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $days = $request->query->get('days');
        $host = $request->getSchemeAndHttpHost();
        $cacheKey = 'orders_source_' . $orderSourceId . ($days ? '_days_' . (int)$days : '');

        $orderDTOs = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($orderSourceId, $host, $days) {
                $item->expiresAfter(300); 
                $item->tag(['orders_source']);
                return $this->getOrdersBySourceUseCase->execute((int)$orderSourceId, $host, $days ? (int)$days : null);
            },
        );

        $orderData = array_map(fn($dto) => $dto->toArray(), $orderDTOs);
        return $this->json($orderData);
    }

    #[Route("api/ordersuser", name:"get_user_orders", methods:["GET"])]
    public function getUserOrders(Request $request, Security $security): JsonResponse
    {
        $user = $security->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $host = $request->getSchemeAndHttpHost();
        $cacheKey = 'orders_user_' . $user->getId();

        $orderDTOs = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($user, $host) {
                $item->expiresAfter(300); 
                $item->tag(['orders_user']);
                return $this->getOrdersByUserUseCase->execute($user->getId(), $host);
            },
            /* ttl */ 300,
            /* extraTags */ ['orders_user']
        );

        if (empty($orderDTOs)) {
            return $this->json(['message' => 'No orders found for the current user'], JsonResponse::HTTP_NOT_FOUND);
        }

        $orderData = array_map(fn($dto) => $dto->toArray(), $orderDTOs);
        return $this->json($orderData);
    }
}
