<?php

namespace App\MemoiresVivantes\Services\RevenueCat;

/**
 * Achats non renouvelables (consommables) d'un utilisateur RevenueCat, lus par l'API REST v1
 * (GET /v1/subscribers/{app_user_id}, clé secrète). Doublure dans les tests : tests/Fake/FakeRevenueCatClient.
 */
interface RevenueCatClientInterface
{
    /**
     * @return list<array{transactionId: string, store: string, productId: string, amount: ?float, currency: ?string, purchasedAt: ?string, environment: ?string}>
     *         store normalisé : app_store | play_store | autre ; purchasedAt ISO 8601 ; environment sandbox | production | null
     * @throws \RuntimeException RevenueCat non configuré ou injoignable
     */
    public function purchases(string $appUserId): array;
}
