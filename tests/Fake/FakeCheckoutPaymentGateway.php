<?php

namespace App\Tests\Fake;

use App\Services\CheckoutService\CheckoutPaymentGatewayInterface;

/** Intents de paiement simulés : en mémoire, aucun appel réseau ; confirm() joue le paiement du navigateur */
final class FakeCheckoutPaymentGateway implements CheckoutPaymentGatewayInterface
{
    /** @var array<string, array> */
    public static array $intents = [];

    public static function reset(): void
    {
        self::$intents = [];
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

    public function cancel(string $id): void
    {
        if (isset(self::$intents[$id])) {
            self::$intents[$id]['status'] = 'canceled';
        }
    }
}
