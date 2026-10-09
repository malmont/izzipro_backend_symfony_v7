<?php

namespace App\UseCase\CheckoutUseCase;

use App\Dto\CartQuoteInputDto;
use App\Entity\Adress;
use App\Entity\User;
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
        private readonly \App\Services\TenantEntityManagerProvider $emProvider
    ) {
    }

    /** @return array{success: bool, clientSecret: string, paymentIntentId: string, calculatedAmount: int, quote: array, reused: bool} */
    public function execute(array $payload, ?User $user, string $locale, ?string $host): array
    {
        if (!isset($payload['priceShipping']) && !isset($payload['shippingPrice']) && isset($payload['shipping']) && is_numeric($payload['shipping'])) {
            $payload['priceShipping'] = $payload['shipping'];
        }
        $order = is_array($payload['order'] ?? null) ? $payload['order'] : null;
        $reuse = is_string($payload['paymentIntentId'] ?? null) ? $payload['paymentIntentId'] : null;
        // Panier modifié sans renvoyer « order » : le corps gardé au premier appel reste valable
        $order ??= $reuse !== null ? $this->sessions->find($reuse)?->getOrderData() : null;
        // Adresse des taxes : sans shippingAddress, celle de la commande à venir (sinon l'intent serait chiffré sans
        // taxes et la commande, qui les calcule, refuserait le montant payé)
        if (empty($payload['shippingAddress']) && $order !== null) {
            $payload['shippingAddress'] = $this->taxAddress($order, $user);
        }
        try {
            $quote = $this->quotes->quote(CartQuoteInputDto::fromArray($payload));
        } catch (CartQuoteException $e) {
            throw new CheckoutException($e->getStatusCode(), $e->getMessage(), $e->errors);
        }
        $amount = (int) $quote['total'];
        if ($amount <= 0) {
            throw new CheckoutException(400, 'Montant invalide calculé');
        }
        $cart = array_intersect_key($payload, array_flip(['items', 'carrierId', 'priceShipping', 'shippingPrice', 'service', 'shippingAddress', 'booking']));
        $opened = $this->sessions->open($cart, $amount, (string) $quote['currency'], $user, $order, $locale, $host, $reuse);

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
        if ($user !== null && is_numeric($order['addressId'] ?? null)) {
            $address = $this->emProvider->getEntityManager()->getRepository(Adress::class)->find((int) $order['addressId']);
            if ($address !== null && $address->getUserAdress()?->getId() === $user->getId()) {
                return ['country' => $address->getCountry(), 'province' => $address->getProvince(), 'city' => $address->getCity(), 'postalCode' => $address->getCodepostal()];
            }
        }
        $shipping = is_array($order['shippingAddress'] ?? null) ? $order['shippingAddress'] : null;

        return $shipping === null ? null : [
            'country' => $shipping['country'] ?? null, 'province' => $shipping['province'] ?? null, 'city' => $shipping['city'] ?? null,
            'postalCode' => $shipping['postalCode'] ?? $shipping['zipCode'] ?? $shipping['zip'] ?? $shipping['codepostal'] ?? null,
        ];
    }
}
