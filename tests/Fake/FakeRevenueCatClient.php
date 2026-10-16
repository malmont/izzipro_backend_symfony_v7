<?php

namespace App\Tests\Fake;

use App\MemoiresVivantes\Services\RevenueCat\RevenueCatClientInterface;

/** RevenueCat simulé : achats scriptés par les tests, par identifiant utilisateur RevenueCat ("<site>:<userId>") */
final class FakeRevenueCatClient implements RevenueCatClientInterface
{
    /** @var array<string, list<array{transactionId: string, store: string, productId: string, amount: ?float, currency: ?string, purchasedAt: ?string}>> */
    public static array $purchases = [];
    /** @var list<string> identifiants demandés */
    public static array $calls = [];
    public static bool $unavailable = false;

    public static function reset(): void
    {
        self::$purchases = [];
        self::$calls = [];
        self::$unavailable = false;
    }

    public static function addPurchase(string $appUserId, string $transactionId, string $store = 'app_store', string $productId = 'lm_chapter_writing', ?float $amount = 4.99, ?string $currency = 'eur', ?string $environment = 'sandbox'): void
    {
        self::$purchases[$appUserId][] = ['transactionId' => $transactionId, 'store' => $store, 'productId' => $productId, 'amount' => $amount, 'currency' => $currency, 'purchasedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), 'environment' => $environment];
    }

    public function purchases(string $appUserId): array
    {
        self::$calls[] = $appUserId;
        if (self::$unavailable) {
            throw new \RuntimeException('RevenueCat injoignable (simulation)');
        }

        return self::$purchases[$appUserId] ?? [];
    }
}
