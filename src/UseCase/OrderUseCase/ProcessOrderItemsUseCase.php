<?php

namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\ProductVariant;
use App\Services\EntityRetrieverService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use App\Services\OrderService\OrderItemService;
use App\Services\OrderService\RentalPriceCalculator;
use Psr\Log\LoggerInterface;

class ProcessOrderItemsUseCase
{
    private TenantEntityManagerProvider $emProvider;
    private EntityRetrieverService $entityRetrieverService;
    private OrderItemService $orderItemService;
    private RentalPriceCalculator $rentalPriceCalculator;
    private LoggerInterface $logger;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        EntityRetrieverService $entityRetrieverService,
        OrderItemService $orderItemService,
        RentalPriceCalculator $rentalPriceCalculator,
        LoggerInterface $logger
    ) {
        $this->emProvider = $emProvider;
        $this->entityRetrieverService = $entityRetrieverService;
        $this->orderItemService = $orderItemService;
        $this->rentalPriceCalculator = $rentalPriceCalculator;
        $this->logger = $logger;
    }

    public function execute(Order $order, array $items, UpdateStockAndInventoryUseCase $updateStockAndInventory, int $typeOrderId, ?float $priceShipping)
    {
        $em = $this->emProvider->getEntityManager();
        $isCancel = $typeOrderId !== 1;
        $subtotal = 0;
        $order->setShippingCost($priceShipping);
        $carrierPrice = $priceShipping ?? 0.0;
        $subtotal += $carrierPrice;

        foreach ($items as $itemData) {
            $productVariant = $this->entityRetrieverService->findOrFail(ProductVariant::class, $itemData['productVariantId'], 'Product variant not found');

            $product = $productVariant->getProduct();
            $isBookable = $product && method_exists($product, 'isBookable') && $product->isBookable();

            if (!$isBookable) {
                if ($productVariant->getStockQuantity() < $itemData['quantity'] && !$isCancel) {
                    throw new BadRequestHttpException(sprintf(
                        'Stock insuffisant pour le produit "%s" (Stock: %d, Demandé: %d)',
                        $product->getName(),
                        $productVariant->getStockQuantity(),
                        $itemData['quantity']
                    ));
                }

                $updateStockAndInventory->execute($productVariant, $itemData['quantity'], $isCancel);
            }

            $unitPrice = null;

            if ($isBookable) {
                // Determine duration and type for rental price calculation
                // Support both 'booking' (standard) and 'rental' (legacy) keys
                $rentalData = $itemData['booking'] ?? $itemData['rental'] ?? $itemData;
                $unitPrice = $this->rentalPriceCalculator->calculate($product, $rentalData);
            }

            $orderItem = $this->orderItemService->createOrderItem($order, $productVariant, $itemData['quantity'], $unitPrice);

            if (isset($itemData['licenseNumber'])) {
                $orderItem->setLicenseNumber($itemData['licenseNumber']);
            }
            if (isset($itemData['licenseExpirationDate'])) {
                $orderItem->setLicenseExpirationDate(new \DateTime($itemData['licenseExpirationDate']));
            }

            $em->persist($order);
            $em->persist($orderItem);

            $subtotal += $orderItem->getTotalPrice();
        }

        return $subtotal;
    }
}
