<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\ProductVariant;
use App\Services\EntityRetrieverService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use App\Services\OrderService\OrderItemService;

class ProcessOrderItemsUseCase
{
    private $em;
    private $entityRetrieverService;
    private $orderItemService;

    public function __construct(
        EntityManagerInterface $em,
        EntityRetrieverService $entityRetrieverService,
        OrderItemService $orderItemService
    ) {
        $this->em = $em;
        $this->entityRetrieverService = $entityRetrieverService;
        $this->orderItemService = $orderItemService;
    }

    public function execute(Order $order, array $items, UpdateStockAndInventoryUseCase $updateStockAndInventory, int $typeOrderId)
    {
        $isCancel = $typeOrderId !== 1;
        $subtotal = 0;
        $carrierPrice = $order->getCarrier()->getPrice(); 
        $subtotal += $carrierPrice;

        foreach ($items as $itemData) {
            $productVariant = $this->entityRetrieverService->findOrFail(ProductVariant::class, $itemData['productVariantId'], 'Product variant not found');

            if ($productVariant->getStockQuantity() < $itemData['quantity'] && !$isCancel) {
                throw new BadRequestHttpException('Insufficient stock for product variant');
            }

            // Mise à jour du stock et création d'un mouvement d'inventaire
            $updateStockAndInventory->execute($productVariant, $itemData['quantity'], $isCancel);

            // Création de l'article de commande via le service
            $orderItem = $this->orderItemService->createOrderItem($order, $productVariant, $itemData['quantity']);

            // Persistance de l'article de commande
            $this->em->persist($orderItem);
            $subtotal += $orderItem->getTotalPrice();
        }

        return $subtotal;
    }
}
