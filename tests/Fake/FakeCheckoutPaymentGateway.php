<?php

namespace App\Tests\Fake;

use App\Services\CheckoutService\CheckoutPaymentGatewayInterface;

/** Intents de paiement simulés : en mémoire, aucun appel réseau ; confirm() joue le paiement du navigateur */
final class FakeCheckoutPaymentGateway implements CheckoutPaymentGatewayInterface
{
    /** @var array<string, array> */
    public static array $intents = [];
    /** @var list<array{id: string, intent: string, amount: ?int, key: string}> remboursements simulés (lot 3 Opérations, 07/11/2026) */
    public static array $refunds = [];
    public static bool $refundFails = false;

    public static function reset(): void
    {
        self::$intents = [];
        self::$refunds = [];
        self::$refundFails = false;
    }

    public function refund(string $id, ?int $amountCents, string $idempotencyKey): ?array
    {
        if (!isset(self::$intents[$id]) || self::$intents[$id]['status'] !== 'succeeded' || self::$refundFails) {
            return null;
        }
        foreach (self::$refunds as $r) {
            if ($r['key'] === $idempotencyKey) {
                return ['id' => $r['id'], 'status' => 'succeeded', 'amount' => $r['amount'] ?? self::$intents[$id]['amount']];
            }
        }
        $refund = ['id' => 're_test_' . bin2hex(random_bytes(4)), 'intent' => $id, 'amount' => $amountCents, 'key' => $idempotencyKey];
        self::$refunds[] = $refund;

        return ['id' => $refund['id'], 'status' => 'succeeded', 'amount' => $amountCents ?? self::$intents[$id]['amount']];
    }

    /** Le client a payé : autorisation obtenue, à capturer */
    public static function confirm(string $id): void
    {
        self::$intents[$id]['status'] = 'requires_capture';
        self::$intents[$id] = array_merge(self::$intents[$id], ['receiptUrl' => 'https://pay.stripe.test/receipt/' . $id, 'cardBrand' => 'visa', 'last4' => '4242', 'riskLevel' => 'normal']);
    }

    public function create(int $amount, string $currency, array $metadata): ?array
    {
        $id = 'pi_test_' . bin2hex(random_bytes(6));

        return self::$intents[$id] = ['id' => $id, 'status' => 'requires_payment_method', 'amount' => $amount, 'currency' => strtolower($currency),
            'clientSecret' => $id . '_secret_test', 'metadata' => $metadata, 'receiptUrl' => null, 'cardBrand' => null, 'last4' => null, 'riskLevel' => null];
    }

    public function retrieve(string $id): ?array
    {
        return self::$intents[$id] ?? null;
    }

    public function updateAmount(string $id, int $amount): ?array
    {
        if (!isset(self::$intents[$id]) || self::$intents[$id]['status'] !== 'requires_payment_method') {
            return null;
        }
        self::$intents[$id]['amount'] = $amount;

        return self::$intents[$id];
    }

    public function capture(string $id): ?array
    {
        if (isset(self::$intents[$id]) && self::$intents[$id]['status'] === 'requires_capture') {
            self::$intents[$id]['status'] = 'succeeded';
        }

        return self::$intents[$id] ?? null;
    }

    /** Annulation simulée : une entrée « cancelFails » sur l'intent fait échouer l'annulation (test de authorizationCanceled faux) */
    public function cancel(string $id): bool
    {
        if (!isset(self::$intents[$id])) {
            return false;
        }
        if (self::$intents[$id]['status'] === 'canceled') {
            return true;
        }
        if (!empty(self::$intents[$id]['cancelFails'])) {
            return false;
        }
        self::$intents[$id]['status'] = 'canceled';

        return true;
    }
}
