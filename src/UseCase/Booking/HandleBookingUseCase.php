<?php

namespace App\UseCase\Booking;

use App\Entity\Order;
use App\Entity\Booking;
use App\Entity\Product;
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
        $variantRepo = $em->getRepository(ProductVariant::class);

        foreach ($itemsArray as $itemData) {
            
            // 1. On récupère la variante (car ton front envoie productVariantId)
            if (!isset($itemData['productVariantId'])) {
                continue; 
            }

            $variantId = $itemData['productVariantId'];
            $variant = $variantRepo->find($variantId);

            if (!$variant) continue;

            // 2. On remonte au Produit Parent (car c'est lui qui porte le mode 'booking')
            $product = $variant->getProduct();

            // 3. ON NE TRAITE QUE LES PRODUITS "BOOKING"
            if ($product && $product->isBookable()) {
                
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

                // 4. LE GARDIEN (Vérification Ultime du Stock)
                if (!$this->bookingAvailabilityService->isAvailable($product, $start, $end, $quantity)) {
                    throw new ConflictHttpException(sprintf(
                        "Désolé, le créneau pour '%s' n'est plus disponible.", 
                        $product->getName()
                    ));
                }

                // 5. CRÉATION DU BOOKING
                $booking = new Booking();
                $booking->setProduct($product);
                $booking->setStartAt($start);
                $booking->setEndAt($end);
                $booking->setQuantity($quantity);
                $booking->setStatus('PENDING_PAYMENT'); 
                $em->persist($booking);
            }
        }
    }
}