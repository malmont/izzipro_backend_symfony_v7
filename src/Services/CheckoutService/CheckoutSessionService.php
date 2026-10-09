<?php

namespace App\Services\CheckoutService;

use App\Entity\CheckoutSession;
use App\Entity\Order;
use App\Entity\Payments;
use App\Entity\User;
use App\Repository\CheckoutSessionRepository;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;

/**
 * Paiements de panier en cours : un intent par panier jusqu'à la commande (réutilisé et remis au bon montant quand le
 * panier change), données de commande gardées pour le webhook, réservation atomique de la création de commande.
 */
final class CheckoutSessionService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenantProvider,
        private readonly CheckoutPaymentGatewayInterface $payments
    ) {
    }

    /**
     * Intent du panier : celui donné (paymentIntentId) s'il est à ce client, pas encore payé ni commandé, remis au
     * montant du devis ; sinon un nouvel intent.
     *
     * @return array{intent: array, session: CheckoutSession, reused: bool}
     * @throws CheckoutException 502 Stripe
     */
    public function open(array $cart, int $amount, string $currency, ?User $user, ?array $orderData, string $locale, ?string $host, ?string $reuseIntentId): array
    {
        $em = $this->emProvider->getEntityManager();
        $session = $reuseIntentId ? $this->repository()->findOneByPaymentIntent($reuseIntentId) : null;
        if ($session !== null && $session->getStatus() === CheckoutSession::STATUS_OPEN && $session->getUser()?->getId() === $user?->getId()) {
            $intent = $this->payments->updateAmount($session->getPaymentIntentId(), $amount);
            if ($intent !== null) {
                $session->setCart($cart)->setAmount($amount)->setOrderData($orderData ?? $session->getOrderData())->setLocale($locale)->setHost($host);
                $em->flush();

                return ['intent' => $intent, 'session' => $session, 'reused' => true];
            }
        }
        $code = (string) $this->tenantProvider->getTenantCode();
        $intent = $this->payments->create($amount, $currency, ['store_code' => $code, 'tenant_code' => $code]);
        if ($intent === null) {
            throw new CheckoutException(502, 'Impossible de créer l\'intention de paiement.');
        }
        $session = (new CheckoutSession($intent['id']))->setUser($user)->setCart($cart)->setOrderData($orderData)->setAmount($amount)
            ->setCurrency($currency)->setLocale($locale)->setHost($host);
        $em->persist($session);
        $em->flush();

        return ['intent' => $intent, 'session' => $session, 'reused' => false];
    }

    public function find(string $paymentIntentId): ?CheckoutSession
    {
        return $this->repository()->findOneByPaymentIntent($paymentIntentId);
    }

    /** Commande déjà créée pour ce paiement (par la trace du paiement, sinon par le paiement Stripe enregistré) */
    public function existingOrder(string $paymentIntentId): ?Order
    {
        $em = $this->emProvider->getEntityManager();
        $session = $this->find($paymentIntentId);
        if ($session?->getOrder() !== null) {
            return $session->getOrder();
        }

        return $em->getRepository(Payments::class)->findOneBy(['stripePaymentId' => $paymentIntentId])?->getOrderPayment();
    }

    /** true : cet appelant crée la commande ; false : un autre s'en charge (ou l'a fait) */
    public function claim(CheckoutSession $session): bool
    {
        $claimed = $this->repository()->claim($session->getPaymentIntentId());
        $this->emProvider->getEntityManager()->refresh($session);

        return $claimed;
    }

    /** Attend (8 s au plus) la commande qu'un autre appelant est en train de créer */
    public function waitForOrder(CheckoutSession $session, int $seconds = 8): ?Order
    {
        $em = $this->emProvider->getEntityManager();
        for ($i = 0; $i < $seconds * 4; $i++) {
            $em->refresh($session);
            if ($session->getOrder() !== null || $session->getStatus() !== CheckoutSession::STATUS_PROCESSING) {
                return $session->getOrder();
            }
            usleep(250000);
        }

        return null;
    }

    public function markOrdered(CheckoutSession $session, Order $order): void
    {
        $session->setOrder($order)->setStatus(CheckoutSession::STATUS_ORDERED)->setLastError(null);
        $this->emProvider->getEntityManager()->flush();
    }

    public function markFailed(CheckoutSession $session, string $error): void
    {
        $em = $this->emProvider->getEntityManager();
        if (!$em->isOpen()) {
            return;
        }
        $session->setStatus(CheckoutSession::STATUS_FAILED)->setLastError($error);
        $em->flush();
    }

    private function repository(): CheckoutSessionRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(CheckoutSession::class);
    }
}
