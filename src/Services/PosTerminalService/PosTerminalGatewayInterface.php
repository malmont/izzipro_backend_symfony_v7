<?php

namespace App\Services\PosTerminalService;

/**
 * Appels Stripe Terminal (intégration pilotée par le serveur) sur le compte connecté du site, derrière une interface
 * pour les tests (FakeTerminalGateway). Toutes les écritures portent une clé d'idempotence Stripe.
 *
 * Intention normalisée : {id, status, amount, amountReceived, captured, paymentMethodType, cardBrand, last4, failureCode,
 * failureMessage, applicationFee}. Lecteur normalisé : {id, label, deviceType, simulated, status (online|offline),
 * action: null | {type, status (in_progress|succeeded|failed), failureCode, failureMessage, paymentIntent, refund}}.
 * Remboursement normalisé : {id, status, amount, failureReason}.
 */
interface PosTerminalGatewayInterface
{
    /** @throws PosTerminalGatewayException */
    public function createPaymentIntent(int $amount, string $currency, array $paymentMethodTypes, int $applicationFee, array $metadata, string $idempotencyKey): array;

    public function retrievePaymentIntent(string $id): ?array;

    /** @throws PosTerminalGatewayException */
    public function capturePaymentIntent(string $id, int $applicationFee, string $idempotencyKey): array;

    /** @throws PosTerminalGatewayException */
    public function cancelPaymentIntent(string $id, string $idempotencyKey): array;

    /** @throws PosTerminalGatewayException READER_OFFLINE, READER_BUSY, READER_TIMEOUT, PAYMENT_INVALID_STATE */
    public function processOnReader(string $readerId, string $paymentIntentId, bool $customerCancellation, string $idempotencyKey): array;

    public function retrieveReader(string $readerId): ?array;

    /** @throws PosTerminalGatewayException READER_BUSY pendant une autorisation */
    public function cancelReaderAction(string $readerId): array;

    /** @param list<array{description: string, amount: int, quantity: int}> $lines */
    public function setReaderDisplay(string $readerId, string $currency, array $lines, int $tax, int $total): void;

    /** Remboursement en ligne (cartes de crédit) @throws PosTerminalGatewayException */
    public function refundOnline(string $paymentIntentId, int $amount, string $idempotencyKey): array;

    /** Remboursement présenté sur le lecteur (Interac) @throws PosTerminalGatewayException */
    public function refundOnReader(string $readerId, string $paymentIntentId, int $amount, string $idempotencyKey): array;

    public function retrieveRefund(string $refundId): ?array;

    /** @throws PosTerminalGatewayException */
    public function createLocation(string $displayName, array $address): string;

    /** @return list<array> lecteurs normalisés */
    public function listReaders(?string $locationId): array;

    /** @throws PosTerminalGatewayException */
    public function createReader(string $registrationCode, string $label, string $locationId): array;

    /** Aide de test Stripe (lecteur simulé, clé de test seulement) : le porteur présente une carte @throws PosTerminalGatewayException */
    public function presentPaymentMethod(string $readerId, string $type, ?string $cardNumber): array;

    /** Clé Stripe en production (sk_live) */
    public function isLiveMode(): bool;
}
