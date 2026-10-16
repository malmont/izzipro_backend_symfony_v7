<?php

namespace App\MemoiresVivantes\Services;

use App\Entity\User;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\BookPrintOrder;
use App\MemoiresVivantes\Exception\ApiErrorException;
use App\Services\CheckoutService\CheckoutPaymentGatewayInterface;
use App\Services\TenantEntityManagerProvider;

/**
 * Paiement Stripe d'une impression commandée depuis l'application (16/10/2026) : à la demande d'intention, une
 * commande « pending_payment » est créée avec le montant de la grille (PricingService) et l'identifiant du
 * PaymentIntent (passerelle du panier, capture manuelle) ; à la commande, l'intention doit être autorisée ou
 * réglée, la commande est alors complétée (PDF, Lulu) et le paiement capturé. Jamais de commande Lulu avant paiement.
 */
class PrintPaymentService
{
    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PricingService $pricing,
        private readonly CheckoutPaymentGatewayInterface $gateway,
        private readonly \App\Services\TenantConnectionProvider $tenants
    ) {}

    /**
     * @param array<string, mixed> $shipping coordonnées (facultatives à ce stade, complétées à la commande)
     * @return array{order: BookPrintOrder, clientSecret: string, amount: float, currency: string, billing: array}
     * @throws ApiErrorException CURRENCY_NOT_SUPPORTED (422), PAYMENT_UNAVAILABLE (503)
     */
    public function createPendingOrder(Book $book, User $user, int $quantity, ?string $currency, array $shipping): array
    {
        $billing = $this->pricing->estimate($book, $quantity, $currency);
        $intent = $this->gateway->create((int) round($billing['total'] * 100), $billing['currency'], [
            'memoires_print' => '1', 'book_id' => (string) $book->getId(), 'user_id' => (string) $user->getId(), 'quantity' => (string) $billing['quantity'],
            'tenant_code' => (string) ($this->tenants->getTenantCode() ?? ''),
        ]);
        if ($intent === null || empty($intent['clientSecret']) || empty($intent['id'])) {
            throw new ApiErrorException('PAYMENT_UNAVAILABLE', 'Le paiement est momentanément indisponible. Réessayez dans un instant.', 503);
        }
        $address = LuluPrintService::normalizeAddress($shipping);
        $order = (new BookPrintOrder())
            ->setBook($book)->setUser($user)->setQuantity($billing['quantity'])
            ->setRecipientName($address['name'] !== 'Destinataire' ? $address['name'] : trim($user->getFirstname() . ' ' . $user->getLastname()))
            ->setStreet1($address['street1'] ?: null)->setStreet2($address['street2'] ?: null)->setCity($address['city'] ?: null)
            ->setState($address['state_code'] ?: null)->setPostalCode($address['postal_code'] ?: null)->setCountryCode($address['country_code'])
            ->setPhoneNumber($address['phone_number'] ?: null)->setEmail($address['contact_email'] ?: $user->getEmail())
            ->setStatus(self::STATUS_PENDING_PAYMENT)->setPaymentStatus('pending')
            ->setChargedAmount($billing['total'])->setChargedCurrency($billing['currency'])->setStripePaymentIntentId((string) $intent['id']);
        $em = $this->emProvider->getEntityManager();
        $em->persist($order);
        $em->flush();

        return ['order' => $order, 'clientSecret' => (string) $intent['clientSecret'], 'amount' => $billing['total'], 'currency' => $billing['currency'], 'billing' => $billing];
    }

    /**
     * La commande en attente dont l'intention est autorisée ou réglée
     *
     * @throws ApiErrorException PRINT_NOT_PAID (402), PRINT_ALREADY_ORDERED (409)
     */
    public function confirm(Book $book, User $user, string $paymentIntentId): BookPrintOrder
    {
        $paymentIntentId = trim($paymentIntentId);
        $order = $paymentIntentId === '' ? null : $this->emProvider->getEntityManager()->getRepository(BookPrintOrder::class)->findOneBy(['stripePaymentIntentId' => $paymentIntentId, 'book' => $book]);
        if ($order === null || $order->getUser()?->getId() !== $user->getId()) {
            throw new ApiErrorException('PRINT_NOT_PAID', 'Le paiement de cette impression n\'a pas été trouvé : réglez-la d\'abord (print/payment-intent).', 402);
        }
        if ($order->getStatus() !== self::STATUS_PENDING_PAYMENT) {
            throw new ApiErrorException('PRINT_ALREADY_ORDERED', 'Cette impression a déjà été commandée.', 409, ['orderId' => $order->getId()?->toRfc4122()]);
        }
        $intent = $this->gateway->retrieve($paymentIntentId);
        $status = (string) ($intent['status'] ?? '');
        $expectedAmount = (int) round(($order->getChargedAmount() ?? 0) * 100);
        if ($intent === null || !in_array($status, ['requires_capture', 'succeeded'], true) || (int) ($intent['amount'] ?? -1) !== $expectedAmount || strtolower((string) ($intent['currency'] ?? '')) !== $order->getChargedCurrency()) {
            throw new ApiErrorException('PRINT_NOT_PAID', 'Le paiement de cette impression n\'est pas confirmé.', 402, ['paymentStatus' => $status ?: null]);
        }

        return $order;
    }

    /** Remboursement fait dans Stripe (webhook charge.refunded) : la commande passe en « refunded » ; null si l'intention est inconnue */
    public function refundFromStripe(string $paymentIntentId, float $amountRefunded): ?BookPrintOrder
    {
        $order = $this->emProvider->getEntityManager()->getRepository(BookPrintOrder::class)->findOneBy(['stripePaymentIntentId' => $paymentIntentId]);
        if ($order === null) {
            return null;
        }
        if ($order->getPaymentStatus() !== 'refunded') {
            $order->markRefunded($amountRefunded);
            $this->emProvider->getEntityManager()->flush();
        }

        return $order;
    }

    /** Après la création de la commande (PDF, Lulu) : capture du paiement autorisé */
    public function markPaid(BookPrintOrder $order): void
    {
        $intentId = (string) $order->getStripePaymentIntentId();
        $intent = $this->gateway->retrieve($intentId);
        if (($intent['status'] ?? null) === 'requires_capture') {
            $this->gateway->capture($intentId);
        }
        $order->setPaymentStatus('paid');
        $this->emProvider->getEntityManager()->flush();
    }
}
