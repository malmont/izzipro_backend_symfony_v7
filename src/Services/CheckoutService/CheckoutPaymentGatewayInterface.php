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
    /**
     * Annule l'autorisation (01/11/2026 : rend vrai si l'intent est annulé à l'issue de l'appel, déjà annulé compris ; faux si
     * l'annulation a échoué ou n'est pas possible dans son état, l'échec étant journalisé en erreur avec l'identifiant)
     */
    public function cancel(string $id): bool;

    /**
     * Rembourse un paiement capturé, en entier (montant nul) ou en partie (cents) ; opérations, lot 3 (07/11/2026). Rend
     * { id, status, amount } du remboursement, null si Stripe refuse (journalisé). Idempotent par clé.
     */
    public function refund(string $id, ?int $amountCents, string $idempotencyKey): ?array;
}
