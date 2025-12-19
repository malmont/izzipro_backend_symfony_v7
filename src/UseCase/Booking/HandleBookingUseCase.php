<?php

namespace App\UseCase\Booking;

use App\Entity\Order;
use App\Entity\Booking;
use App\Entity\OrderItems;
use App\Entity\ProductVariant;
use App\Services\Booking\BookingAvailabilityService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class HandleBookingUseCase
{
    public function __construct(
        private BookingAvailabilityService $bookingAvailabilityService,
        private TenantEntityManagerProvider $emProvider
    ) {}

    /**
     * @param Order $order
     * @param array $itemsArray Le tableau brut venant du JSON
     */
    public function execute(Order $order, array $itemsArray): void
    {
        $em = $this->emProvider->getEntityManager();
        
        if ($order->getId()) {
            $em->refresh($order);
        }
        
        $variantRepo = $em->getRepository(ProductVariant::class);
        $orderItemsMap = [];
        foreach ($order->getOrderItems() as $item) {
            if ($variant = $item->getProductVariant()) {
                $orderItemsMap[$variant->getId()][] = $item;
            }
        }

        foreach ($itemsArray as $itemData) {
            
            if (!isset($itemData['productVariantId'])) {
                continue; 
            }

            $variantId = (int) $itemData['productVariantId']; 

            if (isset($orderItemsMap[$variantId]) && count($orderItemsMap[$variantId]) > 0) {
                $variant = $orderItemsMap[$variantId][0]->getProductVariant();
            } else {
                $variant = $variantRepo->find($variantId);
            }

            if (!$variant) continue;

            $product = $variant->getProduct();

            if ($product && method_exists($product, 'isBookable') && $product->isBookable()) {
                
                if (empty($itemData['booking']['start']) || empty($itemData['booking']['end'])) {
                    throw new BadRequestHttpException(sprintf(
                        "Dates manquantes pour le produit '%s' (Variante #%d).", 
                        $product->getName(), 
                        $variantId
                    ));
                }

                try {
                    $start = new \DateTimeImmutable($itemData['booking']['start']);
                    $end = new \DateTimeImmutable($itemData['booking']['end']);
                } catch (\Exception $e) {
                    throw new BadRequestHttpException("Format de date invalide pour la réservation.");
                }

                $quantity = (int) ($itemData['quantity'] ?? 1);

                if (!$this->bookingAvailabilityService->isAvailable($product, $start, $end, $quantity)) {
                    throw new ConflictHttpException(sprintf(
                        "Désolé, le créneau pour '%s' n'est plus disponible.", 
                        $product->getName()
                    ));
                }

                $booking = new Booking();
                $booking->setProduct($product);
                $booking->setStartAt($start);
                $booking->setEndAt($end);
                $booking->setQuantity($quantity);
                $booking->setStatus('PENDING_PAYMENT'); 

                if (isset($orderItemsMap[$variantId]) && count($orderItemsMap[$variantId]) > 0) {
                    /** @var OrderItems $relatedOrderItem */

                    $relatedOrderItem = array_shift($orderItemsMap[$variantId]);
                    
                    $booking->setOrderItem($relatedOrderItem);
                    $relatedOrderItem->setBooking($booking);
                }

                $em->persist($booking);
            }
        }
        
    }
}