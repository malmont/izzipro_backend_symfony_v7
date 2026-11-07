<?php

namespace App\Services\OrderService;

use App\Dto\AdressOutputDTO;
use App\Dto\OrderDTO;
use App\Dto\OrderItemDTO;
use App\Entity\Order;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;

/**
 * Commande telle que le client la lit (GET /api/ordersuser, GET /api/orders/{id}) : montants en cents entiers, devise du
 * site, statut dans la langue demandée, lignes avec leur réservation. Même forme pour la liste et le détail.
 */
final class OrderPresenter
{
    public function __construct(private readonly TenantCurrencyProvider $currency, private readonly ?\App\Services\TenantEntityManagerProvider $emProvider = null, private readonly ?\App\Services\Booking\BookingClock $clock = null)
    {
    }

    public function toDto(Order $order, string $host, string $locale): OrderDTO
    {
        $items = [];
        $timezone = $this->clock?->timezone(); // réservations rendues dans le fuseau de la boutique (04/11/2026)
        foreach ($order->getOrderItems() as $orderItem) {
            $items[] = new OrderItemDTO($orderItem, $host, $timezone);
        }
        $status = $order->getStatus();
        $statusName = $status?->getTranslation($locale)?->getName() ?? $status?->getName();

        $dto = new OrderDTO(
            (int) $order->getId(),
            (string) $order->getReference(),
            (float) $order->getTotalAmount(),
            $order->getSubTotal(),
            $order->getTotalTax(),
            $order->getShippingCost(),
            $order->getOrderDate()->format('Y-m-d H:i:s'),
            $order->getUserId()?->getId(),
            $order->getShippingAdress() ? new AdressOutputDTO($order->getShippingAdress()) : null,
            $order->getOrderSource()?->getName(),
            $statusName,
            $items
        );
        $dto->currency = $this->currency->code();
        $dto->withTaxDetails($order); // taxes par taux et drapeau TTC (F18 partie 2b)
        $dto->statusId = $status?->getId();
        $dto->carrier = $order->getCarrier() ? ['id' => $order->getCarrier()->getId(), 'name' => $order->getCarrier()->getTranslation($locale)?->getName() ?? $order->getCarrier()->getName()] : null;
        if ($order->getOrderSource()?->getId() === 2 && $this->emProvider) { // vente au comptoir : numéros (C2)
            $dto->pos = OrderDTO::posFields($this->emProvider->getEntityManager()->getRepository(\App\Entity\PosReceipt::class)->forOrders([(int) $order->getId()])[$order->getId()] ?? []);
        }
        $dto->paymentStatus = $this->paymentStatus($order);
        $dto->shipment = $this->shipment($order, $locale);
        if ($this->emProvider && $order->getId() !== null && $order->getOrderSource()?->getId() !== 2) { // retours web (lot 7) : le client voit ce qui lui a été repris
            $dto->returns = array_map(fn (\App\Entity\OrderReturn $r) => ['id' => (int) $r->getId(), 'createdAt' => $r->getCreatedAt()->format('Y-m-d H:i'), 'amountCents' => $r->getAmount(), 'lines' => array_map(fn ($l) => ['orderItemId' => $l['orderItemId'], 'name' => $l['name'], 'quantity' => $l['quantity'], 'amountCents' => $l['amountCents']], $r->getLines())], $this->emProvider->getEntityManager()->getRepository(\App\Entity\OrderReturn::class)->findForOrder($order));
        }

        return $dto;
    }

    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_AUTHORIZED = 'authorized';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_FAILED = 'failed';
    public const PAYMENT_REFUNDED = 'refunded';
    public const PAYMENT_CANCELED = 'canceled';

    private const STATUS_CANCELED_ID = 7;
    private const ORDER_TYPE_RETURN_ID = 2;

    /**
     * État du paiement d'une commande (01/11/2026), valeurs stables :
     *  canceled  : commande annulée (statut 7) ;
     *  refunded  : commande de retour, ou commande ayant un retour ou un paiement de type remboursement ;
     *  paid      : un paiement client complété (statut « Complété » ou Stripe succeeded) ;
     *  authorized: paiement Stripe autorisé, pas encore capturé (requires_capture) ;
     *  failed    : seulement des paiements en échec ;
     *  pending   : aucun paiement ou paiement en cours.
     */
    public function paymentStatus(Order $order): string
    {
        if ($order->getStatus()?->getId() === self::STATUS_CANCELED_ID) {
            return self::PAYMENT_CANCELED;
        }
        if ($order->getOrderType()?->getId() === self::ORDER_TYPE_RETURN_ID) {
            return self::PAYMENT_REFUNDED;
        }
        $paid = $authorized = $failed = $refunded = false;
        foreach ($order->getPayments() as $payment) {
            $type = (string) $payment->getPaymentType()?->getName();
            $statut = (string) $payment->getStatutPayment()?->getName();
            $stripe = (string) $payment->getStripeStatus();
            if ($type === 'RemboursementClient') {
                $refunded = true;
                continue;
            }
            if ($stripe === 'succeeded' || $statut === 'Complété') {
                $paid = true;
            } elseif ($stripe === 'requires_capture') {
                $authorized = true;
            } elseif ($stripe === 'canceled' || $statut === 'Echoué' || $statut === 'Échoué') {
                $failed = true;
            }
        }
        if (!$refunded && $this->emProvider !== null && $order->getId() !== null) {
            $refunded = $this->emProvider->getEntityManager()->getRepository(Order::class)->count(['returnedFrom' => $order]) > 0;
        }

        return match (true) {
            $refunded => self::PAYMENT_REFUNDED,
            $paid => self::PAYMENT_PAID,
            $authorized => self::PAYMENT_AUTHORIZED,
            $failed => self::PAYMENT_FAILED,
            default => self::PAYMENT_PENDING,
        };
    }

    /** @return array{carrier: ?string, trackingNumber: ?string, trackingUrl: ?string}|null null tant qu'aucune étiquette ni expédition manuelle n'existe */
    public function shipment(Order $order, string $locale): ?array
    {
        $shippingOrder = $order->getShippingOrder();
        if ($shippingOrder === null) {
            // Expédition manuelle par le marchand (livraison, lot 1, 07/11/2026) : transporteur et suivi saisis dans le back-office
            if ($order->getShippedAt() !== null || $order->getTrackingNumber() !== null) {
                return ['carrier' => $order->getTrackingCarrier(), 'trackingNumber' => $order->getTrackingNumber(), 'trackingUrl' => $order->getTrackingUrl()];
            }

            return null;
        }
        foreach ($shippingOrder->getParcels() as $parcel) {
            $label = $parcel->getShippingLabel();
            if ($label === null) {
                continue;
            }
            $carrier = $order->getCarrier() ? ($order->getCarrier()->getTranslation($locale)?->getName() ?? $order->getCarrier()->getName()) : null;
            $tracking = $label->getTrackingCode();

            return ['carrier' => $carrier, 'trackingNumber' => $tracking, 'trackingUrl' => $tracking !== null && $tracking !== '' ? self::trackingUrl((string) $order->getCarrier()?->getName(), $tracking) : null];
        }

        return null;
    }

    /** Page de suivi du transporteur, pour les transporteurs connus ; null sinon */
    public static function trackingUrl(string $carrierName, string $trackingNumber): ?string
    {
        $name = strtolower($carrierName);
        $code = rawurlencode($trackingNumber);

        return match (true) {
            str_contains($name, 'canada post'), str_contains($name, 'postes canada'), str_contains($name, 'canadapost') => 'https://www.canadapost-postescanada.ca/track-reperage/fr#/search?searchFor=' . $code,
            str_contains($name, 'purolator') => 'https://www.purolator.com/fr/expedition/suivi?pin=' . $code,
            str_contains($name, 'ups') => 'https://www.ups.com/track?tracknum=' . $code,
            str_contains($name, 'fedex') => 'https://www.fedex.com/fedextrack/?trknbr=' . $code,
            str_contains($name, 'usps') => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . $code,
            str_contains($name, 'dhl') => 'https://www.dhl.com/global-en/home/tracking.html?tracking-id=' . $code,
            default => null,
        };
    }
}
