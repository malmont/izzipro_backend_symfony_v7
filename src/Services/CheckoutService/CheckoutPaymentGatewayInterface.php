<?php

namespace App\Services\CheckoutService;

/**
 * Paiements d'un panier (intents Stripe en capture manuelle sur le compte du site), derrière une interface pour les
 * tests (FakeCheckoutPaymentGateway). Un intent est rendu sous forme normalisée :
 * { id, status, amount (cents), currency, clientSecret, metadata, receiptUrl, cardBrand, last4, riskLevel }.
 */
interface CheckoutPaymentGatewayInterface
{
    /** @param array<string, string> $metadata */
    public function create(int $amount, string $currency, array $metadata): ?array;

    public function retrieve(string $id): ?array;

    /** Nouveau montant d'un intent pas encore confirmé ; null si l'intent ne se modifie plus */
    public function updateAmount(string $id, int $amount): ?array;

    public function capture(string $id): ?array;

    /** Libère une autorisation non capturée (le client n'est pas débité) */
    public function cancel(string $id): void;
}
