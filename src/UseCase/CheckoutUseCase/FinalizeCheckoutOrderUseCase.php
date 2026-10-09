<?php

namespace App\UseCase\CheckoutUseCase;

use App\Dto\CreateOrderDTO;
use App\Entity\CheckoutSession;
use App\Entity\Order;
use App\Entity\User;
use App\Services\CheckoutService\CheckoutCustomerService;
use App\Services\CheckoutService\CheckoutException;
use App\Services\CheckoutService\CheckoutPaymentGatewayInterface;
use App\Services\CheckoutService\CheckoutSessionService;
use App\Services\MediaUrlResolver;
use App\Services\OrderService\OrderMailerService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\OrderUseCase\CreateOrderUseCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Commande d'un panier payé par Stripe (09/10/2026), une seule fois par paiement, quel que soit l'appelant :
 * - navigateur : POST /api/order/create (client connecté) ou /api/order/create-guest (invité) ;
 * - webhook Stripe (paiement autorisé) si le navigateur ne l'a jamais fait, avec le corps gardé à create-intent.
 * Idempotent : un second appel pour le même paiement renvoie la commande existante (200) ; deux appels simultanés se
 * départagent par la réservation atomique du paiement. Commande refusée (stock, montant) : autorisation annulée, le
 * client n'est pas débité.
 */
final class FinalizeCheckoutOrderUseCase
{
    public const MODE_CUSTOMER = 'customer';
    public const MODE_GUEST = 'guest';

    private const ORDER_SOURCE_ECOMMERCE = 1;
    private const ORDER_SOURCE_MOBILE_APP = 3;
    private const PAYMENT_METHOD_ONLINE = 2;
    private const TYPE_ORDER_SALE = 1;
    private const PAID = ['requires_capture', 'succeeded'];

    public function __construct(
        private readonly CheckoutSessionService $sessions,
        private readonly CheckoutPaymentGatewayInterface $payments,
        private readonly CheckoutCustomerService $customers,
        private readonly CreateOrderUseCase $createOrder,
        private readonly OrderMailerService $mailer,
        private readonly MediaUrlResolver $media,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{created: bool, order: Order, body: array<string, mixed>}
     * @throws CheckoutException
     */
    public function customer(User $user, array $data, string $locale, string $host): array
    {
        return $this->finalize(self::MODE_CUSTOMER, $this->paymentIntentId($data), $data, $user, $locale, $host);
    }

    /** @throws CheckoutException */
    public function guest(array $data, string $locale, string $host): array
    {
        return $this->finalize(self::MODE_GUEST, $this->paymentIntentId($data), $data, null, $locale, $host);
    }

    /**
     * Webhook : paiement autorisé (payment_intent.amount_capturable_updated ou succeeded). Crée la commande si le
     * navigateur ne l'a pas fait et que le corps de la commande a été gardé à create-intent.
     *
     * @return array{status: string, detail: string}
     */
    public function fromWebhook(string $paymentIntentId): array
    {
        $session = $this->sessions->find($paymentIntentId);
        if ($session === null) {
            return ['status' => 'ignored', 'detail' => 'paiement sans panier enregistré'];
        }
        if ($this->sessions->existingOrder($paymentIntentId) !== null) {
            return ['status' => 'ignored', 'detail' => 'commande déjà créée'];
        }
        $data = $session->getOrderData();
        if ($data === null) {
            $this->logger->warning('[Checkout] Paiement autorisé sans corps de commande : rien n\'est créé ; l\'autorisation expirera (7 jours)', ['paymentIntent' => $paymentIntentId]);

            return ['status' => 'ignored', 'detail' => 'corps de commande absent (create-intent sans « order »)'];
        }
        $mode = $session->getUser() !== null && isset($data['addressId']) ? self::MODE_CUSTOMER : self::MODE_GUEST;
        try {
            $result = $this->finalize($mode, $paymentIntentId, $data + $session->getCart(), $session->getUser(), $session->getLocale(), (string) $session->getHost());
        } catch (CheckoutException $e) {
            return ['status' => 'failed', 'detail' => $e->getMessage()];
        }

        return ['status' => 'processed', 'detail' => ($result['created'] ? 'commande ' : 'commande existante ') . $result['order']->getId()];
    }

    /** @return array{created: bool, order: Order, body: array<string, mixed>} */
    private function finalize(string $mode, string $paymentIntentId, array $data, ?User $user, string $locale, string $host): array
    {
        $existing = $this->sessions->existingOrder($paymentIntentId);
        if ($existing !== null) {
            return $this->existing($existing, $mode, $data, $user);
        }
        $intent = $this->payments->retrieve($paymentIntentId);
        if ($intent === null || !in_array($intent['status'], self::PAID, true)) {
            throw new CheckoutException(402, 'Payment verification failed or payment not completed');
        }

        $session = $this->sessions->find($paymentIntentId);
        if ($session !== null && !$this->sessions->claim($session)) {
            // Un autre appel (navigateur ou webhook) crée la commande : on l'attend, puis on la renvoie
            $order = $this->sessions->waitForOrder($session);
            if ($order !== null) {
                return $this->existing($order, $mode, $data, $user);
            }
            if ($session->getStatus() === CheckoutSession::STATUS_PROCESSING) {
                throw new CheckoutException(409, 'La commande de ce paiement est en cours de création : réessayez dans un instant.');
            }
            throw new CheckoutException(400, (string) ($session->getLastError() ?? 'Commande refusée.'));
        }

        try {
            if ($mode === self::MODE_GUEST) {
                $guest = $this->customers->guest($data);
                $user = $guest['user'];
                $addressId = (int) $guest['address']->getId();
                $license = [$data['guestInfo']['licenseNumber'] ?? null, $data['guestInfo']['licenseExpirationDate'] ?? null];
                $source = self::ORDER_SOURCE_ECOMMERCE;
            } else {
                $addressId = (int) $this->customers->customer($user, $data)['address']->getId();
                $license = [$data['licenseNumber'] ?? null, $data['licenseExpirationDate'] ?? null];
                $source = in_array((int) ($data['orderSource'] ?? 0), [self::ORDER_SOURCE_ECOMMERCE, self::ORDER_SOURCE_MOBILE_APP], true) ? (int) $data['orderSource'] : self::ORDER_SOURCE_ECOMMERCE;
            }
            $dto = new CreateOrderDTO(
                $user->getId(), $source, self::PAYMENT_METHOD_ONLINE, $addressId, is_numeric($data['carrierId'] ?? null) ? (int) $data['carrierId'] : null, self::TYPE_ORDER_SALE,
                is_array($data['items']) ? $data['items'] : [], is_numeric($data['priceShipping'] ?? $data['shippingPrice'] ?? null) ? (float) ($data['priceShipping'] ?? $data['shippingPrice']) : null,
                null, null, null, null, null, null, null,
                $intent['id'], $intent['receiptUrl'], $intent['status'], $intent['cardBrand'], $intent['last4'], $intent['riskLevel'],
                $license[0], $license[1]
            );
            // Le montant autorisé doit être le total calculé par le serveur (1 centime de tolérance)
            $dto->setVerifiedPaymentAmount((int) $intent['amount']);
            $result = $this->createOrder->execute($dto, $user);
            if (!$result instanceof Order) {
                // Commande refusée après le paiement (stock, montant…) : l'autorisation est libérée, le client n'est pas débité
                $this->payments->cancel($paymentIntentId);
                $message = $result instanceof JsonResponse ? (json_decode((string) $result->getContent(), true)['error'] ?? 'Commande refusée.') : 'Commande refusée.';
                throw new CheckoutException(400, $message);
            }
        } catch (CheckoutException $e) {
            // Données invalides (adresse, transporteur…) : l'autorisation reste, le navigateur peut corriger et renvoyer
            if ($session !== null) {
                $this->sessions->markFailed($session, $e->getMessage());
            }

            throw $e;
        }

        $em = $this->emProvider->getEntityManager();
        $em->refresh($result);
        if ($mode === self::MODE_GUEST) {
            // Jeton d'accès de l'invité à sa commande (GET /api/orders/{id}?token=)
            $result->setGuestToken(bin2hex(random_bytes(24)));
            $em->flush();
            if (!empty($guest['isNewUser'])) {
                $this->customers->completeLicense($user, $license[0], $license[1]);
            }
        } else {
            $this->customers->completeLicense($user, $license[0], $license[1]);
        }
        $this->capture($result, $paymentIntentId);
        if ($session !== null) {
            $this->sessions->markOrdered($session, $result);
        }
        $this->notify($result, $locale, $host);

        return ['created' => true, 'order' => $result, 'body' => $this->body($result, $mode, true)];
    }

    /** Commande déjà créée pour ce paiement : renvoyée à son client (jeton compris pour l'invité à la même adresse) */
    private function existing(Order $order, string $mode, array $data, ?User $user): array
    {
        $owner = $order->getUserId();
        $allowed = $mode === self::MODE_CUSTOMER
            ? $user !== null && $owner?->getId() === $user->getId()
            : $owner !== null && mb_strtolower(trim((string) ($data['guestInfo']['email'] ?? ''))) === mb_strtolower((string) $owner->getEmail());
        if (!$allowed) {
            throw new CheckoutException(409, 'Ce paiement est déjà associé à une commande.');
        }

        return ['created' => false, 'order' => $order, 'body' => $this->body($order, $mode, false)];
    }

    private function body(Order $order, string $mode, bool $created): array
    {
        return array_filter([
            'success' => true,
            'orderId' => $order->getId(),
            'guestToken' => $mode === self::MODE_GUEST ? $order->getGuestToken() : null,
            'alreadyCreated' => !$created,
            'message' => $created ? 'Commande créée.' : 'Commande déjà créée pour ce paiement.',
        ], fn ($v) => $v !== null);
    }

    private function capture(Order $order, string $paymentIntentId): void
    {
        $captured = $this->payments->capture($paymentIntentId);
        if ($captured === null || $captured['status'] !== 'succeeded') {
            $this->logger->error(sprintf('[Checkout] Capture du paiement %s impossible (commande %d)', $paymentIntentId, $order->getId()));

            return;
        }
        // La collection de la commande n'est pas rechargée après sa création : le paiement est relu par son identifiant
        $payment = $this->emProvider->getEntityManager()->getRepository(\App\Entity\Payments::class)->findOneBy(['stripePaymentId' => $paymentIntentId], ['id' => 'DESC']);
        if ($payment) {
            $payment->setStripeStatus('succeeded')->setStripeReceiptUrl($captured['receiptUrl'] ?? $payment->getStripeReceiptUrl())
                ->setStripeCardBrand($captured['cardBrand'] ?? $payment->getStripeCardBrand())->setStripeLast4($captured['last4'] ?? $payment->getStripeLast4())
                ->setStripeRiskLevel($captured['riskLevel'] ?? $payment->getStripeRiskLevel());
            $this->emProvider->getEntityManager()->flush();
        }
    }

    private function notify(Order $order, string $locale, string $host): void
    {
        try {
            $domain = $this->media->getEmailLogosBaseUrl($host !== '' ? $host : null) . '/';
            $this->mailer->sendOrderConfirmation($order, $locale, $domain);
            $this->mailer->sendShippingNotification($order, $locale, $domain);
        } catch (\Throwable $e) {
            $this->logger->error('[Checkout] Courriels de la commande non envoyés : ' . $e->getMessage(), ['orderId' => $order->getId()]);
        }
    }

    private function paymentIntentId(array $data): string
    {
        $id = $data['paymentIntentId'] ?? $data['payment']['stripePaymentId'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new CheckoutException(402, 'Un paiement en ligne est requis pour cette commande.');
        }

        return $id;
    }
}
