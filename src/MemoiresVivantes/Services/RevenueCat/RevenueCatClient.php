<?php

namespace App\MemoiresVivantes\Services\RevenueCat;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Client RevenueCat réel (clé secrète REVENUECAT_SECRET_KEY) ; sans clé : exception explicite, aucun appel */
class RevenueCatClient implements RevenueCatClientInterface
{
    private const BASE_URL = 'https://api.revenuecat.com/v1';

    public function __construct(private readonly HttpClientInterface $httpClient, private readonly LoggerInterface $logger, private readonly string $secretKey = '')
    {
    }

    public function purchases(string $appUserId): array
    {
        $key = trim($this->secretKey);
        if ($key === '') {
            throw new \RuntimeException('RevenueCat non configuré (REVENUECAT_SECRET_KEY)');
        }
        $response = $this->httpClient->request('GET', self::BASE_URL . '/subscribers/' . rawurlencode($appUserId), [
            'headers' => ['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'],
            'timeout' => 15,
        ]);
        if ($response->getStatusCode() >= 400) {
            $this->logger->warning('RevenueCat : réponse ' . $response->getStatusCode() . ' pour ' . $appUserId);
            throw new \RuntimeException('RevenueCat injoignable (' . $response->getStatusCode() . ')');
        }
        $data = $response->toArray(false);
        $out = [];
        foreach ((array) ($data['subscriber']['non_subscriptions'] ?? []) as $productId => $list) {
            foreach ((array) $list as $purchase) {
                $out[] = [
                    'transactionId' => (string) ($purchase['store_transaction_id'] ?? $purchase['id'] ?? ''),
                    'store' => self::normalizeStore((string) ($purchase['store'] ?? '')),
                    'productId' => (string) $productId,
                    'amount' => isset($purchase['price']['amount']) ? (float) $purchase['price']['amount'] : null,
                    'currency' => isset($purchase['price']['currency']) ? strtolower((string) $purchase['price']['currency']) : null,
                    'purchasedAt' => isset($purchase['purchase_date']) ? (string) $purchase['purchase_date'] : null,
                    'environment' => isset($purchase['is_sandbox']) ? ($purchase['is_sandbox'] ? 'sandbox' : 'production') : null,
                ];
            }
        }

        return array_values(array_filter($out, fn ($p) => $p['transactionId'] !== ''));
    }

    /** Valeurs RevenueCat (APP_STORE, PLAY_STORE, MAC_APP_STORE, STRIPE, PROMOTIONAL…) et saisies des applications (ios, android) */
    public static function normalizeStore(string $store): string
    {
        return match (strtolower(trim($store))) {
            'app_store', 'mac_app_store', 'ios', 'apple' => 'app_store',
            'play_store', 'android', 'google' => 'play_store',
            default => strtolower(trim($store)),
        };
    }
}
