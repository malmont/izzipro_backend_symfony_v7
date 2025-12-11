<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\User;
use App\DTO\ICreateOrderDTO;
use App\Services\EntityRetrieverService;
use App\Services\OrderService\OrderCreationService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use App\Entity\OrderSource;
use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\StatusCommande;
use App\Entity\OrderType;


class CreateOrderCommandUseCase
{
    private TenantEntityManagerProvider $emProvider;
    private EntityRetrieverService $entityRetrieverService;
    private OrderCreationService $orderCreationService;

    public function __construct(
        TenantEntityManagerProvider $emProvider, 
        EntityRetrieverService $entityRetrieverService,
        OrderCreationService $orderCreationService
    ) {
        $this->emProvider = $emProvider;
        $this->entityRetrieverService = $entityRetrieverService;
        $this->orderCreationService = $orderCreationService;
    }

    public function execute(ICreateOrderDTO $orderDTO, User $user)
    {
        try {
            $orderSource = $this->entityRetrieverService->findOrFail(OrderSource::class, $orderDTO->getOrderSource(), 'Invalid order source ID');
            $address = $this->entityRetrieverService->findOrFail(Adress::class, $orderDTO->getAddressId(), 'Invalid address ID');
            $carrierId = $orderDTO->getCarrierId();
            $carrier = null;
            
            if ($carrierId) {
                $carrier = $this->entityRetrieverService->findOrFail(Carrier::class, $carrierId, 'Invalid carrier ID');
            }
            $statusCommande = $this->entityRetrieverService->findOrFail(StatusCommande::class, 3, 'Invalid status ID');
            $orderType = $this->entityRetrieverService->findOrFail(OrderType::class, $orderDTO->getTypeOrder(), 'Invalid order type ID');
        } catch (NotFoundHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        }
        $order = $this->orderCreationService->createOrder(
            $user,
            $orderSource,
            $address,
            $carrier,
            $statusCommande,
            $orderType
        );
        $em = $this->emProvider->getEntityManager();
        $em->persist($order);
        return $order;
    }
}