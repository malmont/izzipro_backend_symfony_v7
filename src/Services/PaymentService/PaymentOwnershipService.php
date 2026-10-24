<?php

namespace App\Services\PaymentService;

use App\Entity\User;
use App\Services\CheckoutService\CheckoutSessionService;

/**
 * Propriété d'un PaymentIntent pour POST /api/payment (S7, 31/10/2026). Avant, tout utilisateur connecté pouvait lire l'état,
 * la marque et les quatre derniers chiffres de la carte de n'importe quel paiement du site à partir de son identifiant « pi_… ».
 * Appartient à l'utilisateur : la session de paiement du panier (create-intent) est à son nom, ou la commande créée pour ce
 * paiement est la sienne, ou c'est une commande d'invité dont il présente le jeton (guestToken). L'administrateur du site voit tout.
 */
final class PaymentOwnershipService
{
    public function __construct(private readonly CheckoutSessionService $sessions)
    {
    }

    public function owns(string $paymentIntentId, User $user, ?string $guestToken, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }
        $session = $this->sessions->find($paymentIntentId);
        if ($session !== null && $session->getUser() !== null && $session->getUser()->getId() === $user->getId()) {
            return true;
        }
        $order = $this->sessions->existingOrder($paymentIntentId);
        if ($order === null) {
            return false;
        }
        if ($order->getUserId()?->getId() === $user->getId()) {
            return true;
        }
        $token = $order->getGuestToken();

        return $token !== null && $token !== '' && is_string($guestToken) && hash_equals($token, $guestToken);
    }
}
