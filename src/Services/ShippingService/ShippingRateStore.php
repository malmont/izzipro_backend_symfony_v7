<?php

namespace App\Services\ShippingService;

use App\Services\OrderService\TaxEngine;
use App\Services\TenantConnectionProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lot CHECKOUT-LIVRAISON (21/11/2026) : le serveur ne croit jamais un prix de livraison EasyPost venu du navigateur. Chaque tarif que
 * propose `POST /api/shipping/summary` reçoit un jeton opaque (`rateToken`), gardé ici 30 minutes (Redis `cache.state`, clé site +
 * jeton) avec ce qu'il couvre : les articles et leurs quantités, la destination (pays, province, code postal), le compte EasyPost
 * et le service, le montant en cents et la devise. Le devis web le relit ; jamais 0 par défaut. Après un vidage de Redis ou
 * l'expiration : SHIPPING_RATE_EXPIRED, la boutique redemande le résumé.
 */
final class ShippingRateStore
{
    public const TTL = 1800;

    public function __construct(
        #[Autowire(service: 'cache.state')] private readonly CacheItemPoolInterface $pool,
        private readonly TenantConnectionProvider $tenant,
    ) {
    }

    /**
     * @param array<int, int> $products identifiant d'article => quantité
     * @param array<string, mixed> $destination adresse de livraison du résumé
     */
    public function put(array $products, array $destination, string $carrierAccountId, string $service, int $amountCents, string $currency): string
    {
        $token = bin2hex(random_bytes(16));
        ksort($products);
        $item = $this->pool->getItem($this->key($token));
        $item->set(['products' => $products, 'destination' => self::destination($destination), 'carrierAccountId' => $carrierAccountId,
            'service' => $service, 'amount' => $amountCents, 'currency' => strtoupper($currency)]);
        $item->expiresAfter(self::TTL);
        $this->pool->save($item);

        return $token;
    }

    /** @return array{products: array<int, int>, destination: array{country: ?string, province: ?string, postalCode: ?string}, carrierAccountId: string, service: string, amount: int, currency: string}|null */
    public function get(string $token): ?array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            return null;
        }
        $item = $this->pool->getItem($this->key($token));

        return $item->isHit() && is_array($item->get()) ? $item->get() : null;
    }

    public function forget(string $token): void
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token) === 1) {
            $this->pool->deleteItem($this->key($token));
        }
    }

    /** @return array{country: ?string, province: ?string, postalCode: ?string} destination normalisée, comparable */
    public static function destination(?array $raw): array
    {
        if (is_array($raw) && !isset($raw['postalCode']) && isset($raw['postal_code'])) {
            $raw['postalCode'] = $raw['postal_code']; // forme du résumé des tarifs (ShipmentAddressBuilder::buildTo)
        }
        $normalized = is_array($raw) ? TaxEngine::normalizeAddress($raw) : null;
        $postal = $normalized['postalCode'] ?? null;
        $postal = $postal !== null ? strtoupper(str_replace([' ', '-'], '', (string) $postal)) : null;
        if ($postal !== null && ($normalized['country'] ?? null) === 'US') {
            $postal = substr($postal, 0, 5);
        }

        return ['country' => $normalized['country'] ?? null, 'province' => $normalized['province'] ?? null, 'postalCode' => $postal !== '' ? $postal : null];
    }

    private function key(string $token): string
    {
        // le site entre dans la clé : le jeton d'un site n'existe pas pour un autre
        return 'shipping_rate_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $this->tenant->getTenantCode()) . '_' . $token;
    }
}
