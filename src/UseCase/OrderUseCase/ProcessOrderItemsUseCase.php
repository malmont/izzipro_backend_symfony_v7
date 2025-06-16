<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\ProductVariant;
use App\Services\EntityRetrieverService;
use App\Services\TenantEntityManagerProvider; // <-- On importe notre provider
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use App\Services\OrderService\OrderItemService;

class ProcessOrderItemsUseCase
{
    // MODIFICATION 1 : La propriété $em est remplacée par $emProvider
    private TenantEntityManagerProvider $emProvider;
    private EntityRetrieverService $entityRetrieverService;
    private OrderItemService $orderItemService;

    public function __construct(
        TenantEntityManagerProvider $emProvider, // <-- On injecte le provider
        EntityRetrieverService $entityRetrieverService, // <-- On conserve ce service
        OrderItemService $orderItemService
    ) {
        $this->emProvider = $emProvider;
        $this->entityRetrieverService = $entityRetrieverService;
        $this->orderItemService = $orderItemService;
    }

    public function execute(Order $order, array $items, UpdateStockAndInventoryUseCase $updateStockAndInventory, int $typeOrderId, float $priceShipping)
    {
        // MODIFICATION 2 : On récupère l'EM du tenant ici
        $em = $this->emProvider->getEntityManager();

        $isCancel = $typeOrderId !== 1;
        $subtotal = 0;
        $carrierPrice = $priceShipping; 
        $subtotal += $carrierPrice;

        foreach ($items as $itemData) {
            // Cet appel reste INCHANGÉ car votre service est déjà tenant-aware
            $productVariant = $this->entityRetrieverService->findOrFail(ProductVariant::class, $itemData['productVariantId'], 'Product variant not found');

            if ($productVariant->getStockQuantity() < $itemData['quantity'] && !$isCancel) {
                throw new BadRequestHttpException('Insufficient stock for product variant');
            }

            $updateStockAndInventory->execute($productVariant, $itemData['quantity'], $isCancel);
            $orderItem = $this->orderItemService->createOrderItem($order, $productVariant, $itemData['quantity']);

            // GARDE-FOU : On s'assure que l'Order est bien gérée par l'EM
            $em->persist($order);

            // On utilise l'EM du tenant pour la persistance
            $em->persist($orderItem);
            $subtotal += $orderItem->getTotalPrice();
        }

        return $subtotal;
    }
}