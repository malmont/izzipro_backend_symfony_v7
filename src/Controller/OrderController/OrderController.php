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
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\Order;

class OrderController extends AbstractController
{
    private GetOrdersByUserUseCase $getOrdersByUserUseCase;
    private CreateOrderUseCase $createOrderUseCase;
    private CancelOrderUseCase $cancelOrderUseCase;
    private GetOrdersBySourceUseCase $getOrdersBySourceUseCase;
    private EntityManagerInterface $entityManager;

    public function __construct(
        CreateOrderUseCase $createOrderUseCase,
        CancelOrderUseCase $cancelOrderUseCase,
        GetOrdersBySourceUseCase $getOrdersBySourceUseCase,
        GetOrdersByUserUseCase $getOrdersByUserUseCase,
        EntityManagerInterface $entityManager
    ) {
        $this->createOrderUseCase = $createOrderUseCase;
        $this->cancelOrderUseCase = $cancelOrderUseCase;
        $this->getOrdersBySourceUseCase = $getOrdersBySourceUseCase;
        $this->getOrdersByUserUseCase = $getOrdersByUserUseCase;
        $this->entityManager = $entityManager;
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
        $address = $this->entityManager->getRepository(Adress::class)->find($data['addressId']);
        if (!$address || $address->getUserAdress() !== $user) {
            return $this->json(['error' => 'Unauthorized: Invalid address'], JsonResponse::HTTP_FORBIDDEN);
        }

        // Vérification que le transporteur existe
        $carrier = $this->entityManager->getRepository(Carrier::class)->find($data['carrierId']);
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
            $data['typeOrder'],
            $data['items'],
            $paymentData['squarePaymentId'] ?? null,
            $paymentData['squareOrderId'] ?? null,
            $paymentData['squareReceiptUrl'] ?? null,
            $paymentData['squareStatus'] ?? null,
            $paymentData['squareCardBrand'] ?? null,
            $paymentData['squareLast4'] ?? null,
            $paymentData['squareRiskLevel'] ?? null
        );

        return $this->createOrderUseCase->execute($dto);
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

        $order = $this->entityManager->getRepository(Order::class)->find($id);

        if (!$order) {
            return $this->json(['error' => 'Order not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        // Vérification que l'utilisateur peut bien annuler cette commande
        if ($order->getUser() !== $user) {
            return $this->json(['error' => 'Unauthorized: You can only cancel your own orders'], JsonResponse::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        return $this->cancelOrderUseCase->execute($id, $data['paymentMethod']);
    }

    #[Route('api/orders', name: 'get_orders', methods: ['GET'])]
    public function getOrders(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN'); // Seuls les admins peuvent voir toutes les commandes
    
        $orderSourceId = $request->query->get('orderSource');
        if (!$orderSourceId) {
            return $this->json(['error' => 'orderSource parameter is required'], JsonResponse::HTTP_BAD_REQUEST);
        }
    
        $days = $request->query->get('days');
        $host = $request->getSchemeAndHttpHost();
        $orderDTOs = $this->getOrdersBySourceUseCase->execute((int)$orderSourceId, $host, $days ? (int)$days : null);
    
        if (empty($orderDTOs)) {
            return $this->json([], JsonResponse::HTTP_OK);
        }
    
        $orderData = array_map(fn($dto) => $dto->toArray(), $orderDTOs);
        return $this->json($orderData);
    }
    
    #[Route('api/ordersuser', name: 'get_user_orders', methods: ['GET'])]
    public function getUserOrders(Request $request, Security $security): JsonResponse
    {
        $user = $security->getUser();

        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $host = $request->getSchemeAndHttpHost();
        $orderDTOs = $this->getOrdersByUserUseCase->execute($user->getId(), $host);

        if (empty($orderDTOs)) {
            return $this->json(['message' => 'No orders found for the current user'], JsonResponse::HTTP_NOT_FOUND);
        }

        $orderData = array_map(fn($dto) => $dto->toArray(), $orderDTOs);

        return $this->json($orderData);
    }
}
