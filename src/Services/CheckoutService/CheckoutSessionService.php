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
    /** @param ?CheckoutSession $reuse session déjà reconnue reprenable (reusable(), preuve faite) : relue une seule fois chez Stripe */
    public function open(array $cart, int $amount, string $currency, ?User $user, ?array $orderData, string $locale, ?string $host, ?CheckoutSession $reuse, bool $dropKeptOrderData = false): array
    {
        $em = $this->emProvider->getEntityManager();
        $session = $reuse;
        if ($session !== null) {
            $intent = $this->payments->updateAmount($session->getPaymentIntentId(), $amount);
            if ($intent !== null) {
                // $dropKeptOrderData : le corps gardé ne correspond plus au panier (adresse changée), il est abandonné (CHECKOUT-PANIER)
                $session->setCart($cart)->setAmount($amount)->setOrderData($orderData ?? ($dropKeptOrderData ? null : $session->getOrderData()))->setLocale($locale)->setHost($host);
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

    /**
     * Lot CHECKOUT-PROPRIETE : une intention ne se REPREND qu'avec sa PREUVE (son clientSecret, que seul le navigateur qui l'a ouverte
     * détient), et une session de client par ce client seulement. Sinon, rien : une nouvelle intention, sans le clientSecret ni le
     * corps gardé d'un autre. Toujours une session encore ouverte ; Stripe muet : pas de reprise.
     */
    public function reusable(string $paymentIntentId, ?User $user, ?string $proof): ?CheckoutSession
    {
        $session = $this->find($paymentIntentId);
        if ($session === null || $session->getStatus() !== CheckoutSession::STATUS_OPEN || !is_string($proof) || $proof === '') {
            return null;
        }
        if ($session->getUser() !== null && $session->getUser()->getId() !== $user?->getId()) {
            return null;
        }
        $intent = $this->payments->retrieve($paymentIntentId);

        return is_string($intent['clientSecret'] ?? null) && hash_equals($intent['clientSecret'], $proof) ? $session : null;
    }

    /** Le courriel de l'invité gardé dans un corps de commande (normalisé), ou null */
    public static function guestEmail(?array $orderData): ?string
    {
        return self::normalizeEmail($orderData['guestInfo']['email'] ?? null);
    }

    public static function normalizeEmail(mixed $email): ?string
    {
        $email = is_string($email) ? mb_strtolower(trim($email)) : '';

        return $email !== '' ? $email : null;
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
