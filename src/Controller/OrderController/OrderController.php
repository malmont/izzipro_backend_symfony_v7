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
use Symfony\Component\Security\Core\Security;
use App\UseCase\OrderUseCase\GetOrdersByUserUseCase;

class OrderController extends AbstractController
{
    private GetOrdersByUserUseCase $getOrdersByUserUseCase;
    private $createOrderUseCase;
    private $cancelOrderUseCase;
    private $getOrdersBySourceUseCase;
    public function __construct(
        CreateOrderUseCase $createOrderUseCase,
        CancelOrderUseCase $cancelOrderUseCase,
        GetOrdersBySourceUseCase $getOrdersBySourceUseCase,
        GetOrdersByUserUseCase $getOrdersByUserUseCase
    ) {
        $this->createOrderUseCase = $createOrderUseCase;
        $this->cancelOrderUseCase = $cancelOrderUseCase;
        $this->getOrdersBySourceUseCase = $getOrdersBySourceUseCase;
        $this->getOrdersByUserUseCase = $getOrdersByUserUseCase;
    }

    /**
     * @Route("api/order/create", name="order_create", methods={"POST"})
     */
    public function createOrder(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $user = $this->getUser();
        $dto = new CreateOrderDTO(
            $user->getId(),
            $data['orderSource'],
            $data['paymentMethod'],
            $data['addressId'],
            $data['carrierId'],
            $data['typeOrder'],
            $data['items']
        );

        return $this->createOrderUseCase->execute($dto);
    }
    /**
     * @Route("api/order/cancel/{id}", name="order_cancel", methods={"POST"})
     */
    public function cancelOrder(int $id): JsonResponse
    {
        return $this->cancelOrderUseCase->execute($id);
    }


    #[Route('api/orders', name: 'get_orders', methods: ['GET'])]
    public function getOrders(Request $request): JsonResponse
    {
        $orderSourceId = $request->query->get('orderSource');
        if (!$orderSourceId) {
            return $this->json(['error' => 'orderSource parameter is required'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $days = $request->query->get('days');
        
        $host = $request->getSchemeAndHttpHost() . '/jeesign';
    
        $orderDTOs = $this->getOrdersBySourceUseCase->execute((int)$orderSourceId, $host, $days ? (int)$days : null);
    
        if (empty($orderDTOs)) {
            return $this->json(['message' => 'No orders found for the given order source'], JsonResponse::HTTP_NOT_FOUND);
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

        $host = $request->getSchemeAndHttpHost() . '/jeesign';
        $orderDTOs = $this->getOrdersByUserUseCase->execute($user->getId(), $host);

        if (empty($orderDTOs)) {
            return $this->json(['message' => 'No orders found for the current user'], JsonResponse::HTTP_NOT_FOUND);
        }

        $orderData = array_map(fn($dto) => $dto->toArray(), $orderDTOs);

        return $this->json($orderData);
    }
}

    

