<?php
namespace App\UseCase\OrderUseCase;


use App\Entity\Order;
use App\Entity\OrderItems;
use App\Repository\ProductVariantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class ProcessOrderItemsUseCase
{
    private $em;
    private $productVariantRepository;

    public function __construct(EntityManagerInterface $em, ProductVariantRepository $productVariantRepository)
    {
        $this->em = $em;
        $this->productVariantRepository = $productVariantRepository;
    }

    public function execute(Order $order, array $items, UpdateStockAndInventoryUseCase $updateStockAndInventory)
    {
        $subtotal = 0;
        $carrierPrice = $order->getCarrier()->getPrice(); // Prix hors taxes du transporteur
        $subtotal += $carrierPrice;
        foreach ($items as $itemData) {
            $productVariant = $this->productVariantRepository->find($itemData['productVariantId']);
            if (!$productVariant || $productVariant->getStockQuantity() < $itemData['quantity']) {
                return new JsonResponse(['error' => 'Insufficient stock for product variant'], 400);
            }

            // Mise à jour du stock et création d'un mouvement d'inventaire
            $updateStockAndInventory->execute($productVariant, $itemData['quantity']);

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