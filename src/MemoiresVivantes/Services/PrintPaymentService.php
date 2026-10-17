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
        private readonly \App\Services\TenantConnectionProvider $tenants,
        private readonly \App\MemoiresVivantes\UseCase\Print\CreateBookPrintOrderUseCase $createOrder
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
        $bgColor = isset($shipping['bg_color']) && is_string($shipping['bg_color']) && trim($shipping['bg_color']) !== '' ? trim($shipping['bg_color']) : $book->getBgColor();
        $order = (new BookPrintOrder())
            ->setBook($book)->setUser($user)->setQuantity($billing['quantity'])
            ->setCoverStyle(isset($shipping['cover_style']) && is_string($shipping['cover_style']) && trim($shipping['cover_style']) !== '' ? trim($shipping['cover_style']) : ($book->getCoverStyle() ?: 'biographic_split'))
            ->setBgColor($bgColor !== null && !str_starts_with($bgColor, '#') && ctype_xdigit($bgColor) ? '#' . $bgColor : $bgColor)
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

    /**
     * Paiement confirmé par le webhook Stripe alors que l'application n'a pas (encore) appelé print/order : la commande
     * est complétée côté serveur avec les coordonnées et les choix de couverture enregistrés à l'intention (PDF, Lulu,
     * capture). Si l'application l'a déjà fait : rien.
     *
     * @return array{status: string, detail: string}
     */
    public function completeFromWebhook(string $paymentIntentId, string $publicBaseUrl): array
    {
        $order = $this->emProvider->getEntityManager()->getRepository(BookPrintOrder::class)->findOneBy(['stripePaymentIntentId' => $paymentIntentId]);
        if ($order === null) {
            return ['status' => 'ignored', 'detail' => 'intention inconnue'];
        }
        if ($order->getStatus() !== self::STATUS_PENDING_PAYMENT) {
            return ['status' => 'already_ordered', 'detail' => 'commande ' . $order->getId()->toRfc4122()];
        }
        $book = $order->getBook();
        $user = $order->getUser();
        if ($book === null || $user === null) {
            return ['status' => 'ignored', 'detail' => 'commande sans livre ou sans utilisateur'];
        }
        try {
            $this->confirm($book, $user, $paymentIntentId);
        } catch (ApiErrorException $e) {
            return ['status' => 'not_paid', 'detail' => $e->getMessage()];
        }
        $shipping = [
            'name' => $order->getRecipientName(), 'street1' => $order->getStreet1(), 'street2' => $order->getStreet2(), 'city' => $order->getCity(),
            'state_code' => $order->getState(), 'postal_code' => $order->getPostalCode(), 'country_code' => $order->getCountryCode(),
            'phone_number' => $order->getPhoneNumber(), 'email' => $order->getEmail(), 'quantity' => $order->getQuantity(),
            'cover_style' => $order->getCoverStyle(), 'bg_color' => $order->getBgColor(), 'author_name' => $book->getAuthorName(),
        ];
        $completed = $this->createOrder->execute($book, $user, $shipping, $publicBaseUrl, $order);
        $this->markPaid($completed);

        return ['status' => 'completed', 'detail' => 'commande ' . $completed->getId()->toRfc4122()];
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
