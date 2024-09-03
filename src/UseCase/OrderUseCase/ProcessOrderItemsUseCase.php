<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\ProductVariant;
use App\Services\EntityRetrieverService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ProcessOrderItemsUseCase
{
    private $em;
    private $entityRetrieverService;

    public function __construct(EntityManagerInterface $em, EntityRetrieverService $entityRetrieverService)
    {
        $this->em = $em;
        $this->entityRetrieverService = $entityRetrieverService;
    }

    public function execute(Order $order, array $items, UpdateStockAndInventoryUseCase $updateStockAndInventory, int $typeOrderId)
    {
        $isCancel = $typeOrderId === 1 ? false : true;
        $subtotal = 0;
        $carrierPrice = $order->getCarrier()->getPrice(); // Prix hors taxes du transporteur
        $subtotal += $carrierPrice;

        foreach ($items as $itemData) {
            $productVariant = $this->entityRetrieverService->findOrFail(ProductVariant::class, $itemData['productVariantId'], 'Product variant not found');

            if ($productVariant->getStockQuantity() < $itemData['quantity'] && !$isCancel) {
                throw new BadRequestHttpException('Insufficient stock for product variant');
            }

            // Mise à jour du stock et création d'un mouvement d'inventaire
            $updateStockAndInventory->execute($productVariant, $itemData['quantity'], $isCancel);

            // Création des OrderItems et calcul du sous-total
            $orderItem = new OrderItems();
            $orderItem->setOrderAssociated($order);
            $orderItem->setProductVariant($productVariant);
            $orderItem->setQuantity($itemData['quantity']);
            $orderItem->setUnitPrice($productVariant->getProduct()->getPrice());
            $orderItem->setTotalPrice($orderItem->getUnitPrice() * $itemData['quantity']);

            $this->em->persist($orderItem);
            $subtotal += $orderItem->getTotalPrice();
        }

        return $subtotal;
    }
}
