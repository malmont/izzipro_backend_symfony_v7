<?php

namespace App\Services\SubscriptionService;

use App\Entity\Order;
use App\Entity\OrderSource;
use App\Entity\OrderTax;
use App\Entity\OrderType;
use App\Entity\Payments;
use App\Entity\StatusCommande;
use App\Entity\Subscription;
use App\Services\OrderService\OrderCreationService;
use App\Services\OrderService\OrderItemService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\OrderUseCase\PaymentHandlerUseCase;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use Psr\Log\LoggerInterface;

/**
 * Commande créée à chaque facture Stripe payée d'un abonnement (webhook invoice.paid) : une ligne pour le produit de la
 * formule (première variante, quantité de l'abonnement, prix de la formule), montants de la facture (sous-total, taxes,
 * total en cents), paiement par carte enregistré, stock décrémenté quand il suffit. Rejouable : une facture déjà
 * transformée (order.stripe_invoice_id) ne crée rien. Sans adresse (produit sans livraison et client sans adresse
 * principale), aucune commande n'est créée : l'échéance reste visible dans Stripe.
 */
final class SubscriptionOrderFactory
{
    private const SOURCE_ECOMMERCE = 1;
    private const STATUS_IN_PROGRESS = 2;
    private const TYPE_SALE = 1;
    private const PAYMENT_METHOD_CARD = 1;
    private const PAYMENT_TYPE_CUSTOMER = 2;
    private const PAYMENT_STATUS_DONE = 2;

    /** Raison du dernier refus de créer une commande (journal et réponse du webhook) */
    public ?string $lastSkipReason = null;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly OrderCreationService $orders,
        private readonly OrderItemService $items,
        private readonly PaymentHandlerUseCase $payments,
        private readonly UpdateStockAndInventoryUseCase $stock,
        private readonly LoggerInterface $logger
    ) {
    }

    /** @param array<string, mixed> $invoice objet invoice du webhook */
    public function createFromInvoice(Subscription $subscription, array $invoice): ?Order
    {
        $em = $this->emProvider->getEntityManager();
        $this->lastSkipReason = null;
        $invoiceId = (string) ($invoice['id'] ?? '');
        if ($invoiceId !== '' && $em->getRepository(Order::class)->findOneBy(['stripeInvoiceId' => $invoiceId]) !== null) {
            $this->lastSkipReason = 'facture déjà traitée';

            return null;
        }
        $user = $subscription->getUser();
        $address = $subscription->getAddress() ?? $user?->getPrimaryAddress();
        $plan = $subscription->getPlan();
        $variant = $plan?->getProduct()?->getVariants()->first() ?: null;
        if ($user === null || $address === null || $plan === null || !$variant) {
            $this->lastSkipReason = sprintf('commande impossible : %s', implode(', ', array_keys(array_filter(['client' => $user === null, 'adresse' => $address === null, 'formule' => $plan === null, 'variante' => !$variant]))));
            $this->logger->warning('[Subscription] Facture payée sans commande possible', ['reason' => $this->lastSkipReason, 'subscription' => $subscription->getId(), 'invoice' => $invoiceId]);

            return null;
        }
        $source = $em->getRepository(OrderSource::class)->find(self::SOURCE_ECOMMERCE);
        $status = $em->getRepository(StatusCommande::class)->find(self::STATUS_IN_PROGRESS);
        $type = $em->getRepository(OrderType::class)->find(self::TYPE_SALE);
        if (!$source || !$status || !$type) {
            $this->lastSkipReason = 'données de référence des commandes absentes (order_source, status_commande, order_type)';
            $this->logger->error('[Subscription] ' . $this->lastSkipReason);

            return null;
        }

        $quantity = max(1, $subscription->getQuantity());
        $subtotal = (int) ($invoice['subtotal'] ?? $plan->getPrice() * $quantity);
        $tax = (int) ($invoice['tax'] ?? 0);
        $total = (int) ($invoice['total'] ?? $subtotal + $tax);

        $order = $this->orders->createOrder($user, $source, $address, $subscription->getCarrier(), $status, $type);
        $order->setSubscription($subscription)->setStripeInvoiceId($invoiceId !== '' ? $invoiceId : null)
            ->setSubTotal((float) $subtotal)->setTotalTax((float) $tax)->setTotalAmount((float) $total)->setShippingCost(0.0);
        $em->persist($order);
        $item = $this->items->createOrderItem($order, $variant, $quantity, (float) ($subtotal / $quantity));
        $em->persist($item);
        if ($tax > 0) {
            $orderTax = (new OrderTax())->setOrderTax($order)->setAmount((float) $tax);
            $order->addOrderTax($orderTax);
            $em->persist($orderTax);
        }
        if ($variant->getStockQuantity() >= $quantity) {
            $this->stock->execute($variant, $quantity, false);
        } else {
            $this->logger->warning('[Subscription] Stock insuffisant pour l\'échéance : commande créée sans décrément', ['subscription' => $subscription->getId(), 'variant' => $variant->getId()]);
        }
        $em->flush();

        $this->payments->handlePayment($order, (float) $total, self::PAYMENT_METHOD_CARD, self::PAYMENT_TYPE_CUSTOMER, self::PAYMENT_STATUS_DONE, new \DateTime(), null);
        // La collection de la commande n'est pas rafraîchie par PaymentService : relire le paiement créé
        $payment = $em->getRepository(Payments::class)->findOneBy(['orderPayment' => $order], ['id' => 'DESC']);
        if ($payment) {
            $payment->setStripePaymentId(is_string($invoice['payment_intent'] ?? null) ? $invoice['payment_intent'] : ($invoiceId ?: null));
            $payment->setStripeStatus('succeeded');
            $em->flush();
        }

        return $order;
    }
}
