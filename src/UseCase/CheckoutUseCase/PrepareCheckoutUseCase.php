<?php

namespace App\UseCase\CheckoutUseCase;

use App\Dto\CartQuoteInputDto;
use App\Entity\Adress;
use App\Entity\User;
use App\Services\CheckoutService\CheckoutCartGuard;
use App\Services\CheckoutService\CheckoutException;
use App\Services\CheckoutService\CheckoutSessionService;
use App\Services\OrderService\CartQuoteCalculator;
use App\Services\OrderService\CartQuoteException;

/**
 * Intention de paiement d'un panier (POST /api/stripe/create-intent) : montant = total du devis du serveur. Un même
 * panier garde son intent (paymentIntentId renvoyé par le navigateur, remis au nouveau montant tant qu'il n'est pas
 * payé). Le corps de la commande à venir (order) est gardé pour que le webhook crée la commande si le navigateur ne le
 * fait pas (onglet fermé juste après le paiement).
 */
final class PrepareCheckoutUseCase
{
    public function __construct(
        private readonly CartQuoteCalculator $quotes,
        private readonly CheckoutSessionService $sessions,
        private readonly \App\Services\TenantEntityManagerProvider $emProvider,
        private readonly ?\App\Services\Booking\BookingClock $clock = null, // lot CHECKOUT-PANIER : dates sans fuseau = heure du magasin
    ) {
    }

    /** @return array{success: bool, clientSecret: string, paymentIntentId: string, calculatedAmount: int, quote: array, reused: bool} */
    public function execute(array $payload, ?User $user, string $locale, ?string $host): array
    {
        if (!isset($payload['priceShipping']) && !isset($payload['shippingPrice']) && isset($payload['shipping']) && is_numeric($payload['shipping'])) {
            $payload['priceShipping'] = $payload['shipping'];
        }
        $order = is_array($payload['order'] ?? null) ? $payload['order'] : null;
        // lot CHECKOUT-PANIER : un corps de commande ENVOYÉ doit donner le panier coté (articles, quantités, réservation,
        // transporteur, livraison, adresse) ; refusé ici, AVANT tout paiement, sinon le webhook créerait une autre commande que
        // celle payée. Gardé ensuite sans ses clés de panier : la commande les prendra toujours du panier coté.
        if ($order !== null) {
            $addressOfOrder = CheckoutCartGuard::orderAddress($order, $user, $this->emProvider->getEntityManager()->getRepository(Adress::class));
            if ((array_key_exists('items', $order) && !CheckoutCartGuard::sameCart($order, $payload, $this->clock?->timezone()))
                || (!empty($payload['shippingAddress']) && $addressOfOrder !== null && !CheckoutCartGuard::sameAddress($payload['shippingAddress'], $addressOfOrder, is_numeric($order['addressId'] ?? null)))) {
                throw new CheckoutException(409, 'La commande ne correspond pas au panier à payer.', [], 'CART_MISMATCH');
            }
            $order = CheckoutCartGuard::withoutCart($order);
        }
        $reuse = is_string($payload['paymentIntentId'] ?? null) ? $payload['paymentIntentId'] : null;
        // Panier modifié sans renvoyer « order » : le corps gardé au premier appel reste valable, SAUF si l'adresse a changé (lot
        // CHECKOUT-PANIER, vérification des pertes) : il est alors abandonné, pour que le webhook ne crée jamais la commande à l'ancienne
        // adresse ; le navigateur la crée avec son corps (comparé au panier), sinon l'autorisation expire sans débit
        $dropKept = false;
        if ($order === null && $reuse !== null) {
            $kept = $this->sessions->find($reuse)?->getOrderData();
            $keptAddress = $kept !== null ? CheckoutCartGuard::orderAddress($kept, $user, $this->emProvider->getEntityManager()->getRepository(Adress::class)) : null;
            if ($kept !== null && !empty($payload['shippingAddress']) && $keptAddress !== null && !CheckoutCartGuard::sameAddress($payload['shippingAddress'], $keptAddress, is_numeric($kept['addressId'] ?? null))) {
                $dropKept = true;
            } else {
                $order = $kept;
            }
        }
        // Adresse des taxes : sans shippingAddress, celle de la commande à venir (sinon l'intent serait chiffré sans
        // taxes et la commande, qui les calcule, refuserait le montant payé)
        if (empty($payload['shippingAddress']) && $order !== null) {
            $payload['shippingAddress'] = $this->taxAddress($order, $user);
        }
        try {
            $quote = $this->quotes->quote(CartQuoteInputDto::fromArray($payload), web: true); // intention de paiement : circuit web
        } catch (CartQuoteException $e) {
            throw new CheckoutException($e->getStatusCode(), $e->getMessage(), $e->errors, $e->code()); // code et errors[] codées du devis (02/11/2026)
        }
        $amount = (int) $quote['total'];
        if ($amount <= 0) {
            throw new CheckoutException(400, 'Montant invalide calculé', [], 'AMOUNT_INVALID');
        }
        $cart = array_intersect_key($payload, array_flip(CheckoutCartGuard::CART_KEYS)); // `rental` compris : le devis l'applique
        $opened = $this->sessions->open($cart, $amount, (string) $quote['currency'], $user, $order, $locale, $host, $reuse, $dropKept);

        return [
            'success' => true,
            'clientSecret' => (string) $opened['intent']['clientSecret'],
            'paymentIntentId' => $opened['intent']['id'],
            'calculatedAmount' => $amount,
            'quote' => $quote,
            'reused' => $opened['reused'],
        ];
    }

    /** Adresse de livraison de la commande à venir : adresse enregistrée du client, ou adresse saisie par l'invité */
    private function taxAddress(array $order, ?User $user): ?array
    {
        return CheckoutCartGuard::orderAddress($order, $user, $this->emProvider->getEntityManager()->getRepository(Adress::class));
    }
}
