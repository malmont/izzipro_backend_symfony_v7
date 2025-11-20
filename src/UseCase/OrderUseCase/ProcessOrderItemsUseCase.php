<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\ProductVariant;
use App\Services\EntityRetrieverService;
use App\Services\TenantEntityManagerProvider; 
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use App\Services\OrderService\OrderItemService;
use Psr\Log\LoggerInterface;

class ProcessOrderItemsUseCase
{
    private TenantEntityManagerProvider $emProvider;
    private EntityRetrieverService $entityRetrieverService;
    private OrderItemService $orderItemService;
    private LoggerInterface $logger;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        EntityRetrieverService $entityRetrieverService,
        OrderItemService $orderItemService,
        LoggerInterface $logger
    ) {
        $this->emProvider = $emProvider;
        $this->entityRetrieverService = $entityRetrieverService;
        $this->orderItemService = $orderItemService;
        $this->logger = $logger;
    }

    public function execute(Order $order, array $items, UpdateStockAndInventoryUseCase $updateStockAndInventory, int $typeOrderId, float $priceShipping)
    {
        $em = $this->emProvider->getEntityManager();
        $isCancel = $typeOrderId !== 1;
        $subtotal = 0;
        $order->setShippingCost($priceShipping);
        $carrierPrice = $priceShipping; 
        $subtotal += $carrierPrice;
        foreach ($items as $itemData) {
            $productVariant = $this->entityRetrieverService->findOrFail(ProductVariant::class, $itemData['productVariantId'], 'Product variant not found');

            if ($productVariant->getStockQuantity() < $itemData['quantity'] && !$isCancel) {
                throw new BadRequestHttpException('Insufficient stock for product variant');
            }

            $updateStockAndInventory->execute($productVariant, $itemData['quantity'], $isCancel);
            $orderItem = $this->orderItemService->createOrderItem($order, $productVariant, $itemData['quantity']);

            $em->persist($order);

            $em->persist($orderItem);
            $subtotal += $orderItem->getTotalPrice();
        }
        return $subtotal;
    }
}