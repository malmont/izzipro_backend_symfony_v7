<?php

namespace App\MemoiresVivantes\UseCase\Payment;

use App\Entity\User;
use App\MemoiresVivantes\Services\ChapterPaymentService;
use App\MemoiresVivantes\Services\RevenueCat\RevenueCatClient;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Événement du webhook RevenueCat (POST /api/webhooks/revenuecat). L'identifiant utilisateur "<code du site>:<userId>"
 * désigne le site (bascule de base) et le compte. Achat (INITIAL_PURCHASE, NON_RENEWING_PURCHASE…) : ligne
 * enregistrée, non affectée. CANCELLATION (remboursement d'un consommable) : ligne remboursée, chapitre masqué.
 * Tout le reste est ignoré. Rend ce qui a été fait, pour le journal et la réponse.
 */
class HandleRevenueCatEventUseCase
{
    private const PURCHASE_TYPES = ['INITIAL_PURCHASE', 'NON_RENEWING_PURCHASE', 'RENEWAL', 'UNCANCELLATION'];
    private const REFUND_TYPES = ['CANCELLATION', 'REFUND'];

    public function __construct(
        private readonly TenantConnectionManager $tenantManager,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ChapterPaymentService $payments,
        private readonly LoggerInterface $logger
    ) {}

    /** @param array<string, mixed> $event */
    public function execute(array $event): array
    {
        $type = strtoupper((string) ($event['type'] ?? ''));
        $transactionId = (string) ($event['transaction_id'] ?? $event['original_transaction_id'] ?? '');
        $store = RevenueCatClient::normalizeStore((string) ($event['store'] ?? ''));
        $ids = array_values(array_unique(array_filter(array_merge([(string) ($event['app_user_id'] ?? '')], (array) ($event['aliases'] ?? [])))));
        $resolved = null;
        foreach ($ids as $id) {
            if (preg_match('/^([a-z0-9_-]+):(\d+)$/i', $id, $m)) {
                $resolved = ['code' => $m[1], 'userId' => (int) $m[2]];
                break;
            }
        }
        if ($resolved === null || $transactionId === '') {
            return ['action' => 'ignored', 'reason' => 'identifiant utilisateur ou transaction absents'];
        }
        $stmt = $this->tenantManager->getPdoMaster()->prepare('SELECT dbname, code FROM tenants WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $resolved['code']]);
        $tenant = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$tenant) {
            return ['action' => 'ignored', 'reason' => 'site inconnu'];
        }
        $this->emProvider->switchTenant((string) $tenant['dbname'], (string) $tenant['code']);
        $user = $this->emProvider->getEntityManager()->getRepository(User::class)->find($resolved['userId']);
        if (!$user instanceof User) {
            return ['action' => 'ignored', 'reason' => 'utilisateur inconnu'];
        }

        if (in_array($type, self::PURCHASE_TYPES, true)) {
            $amount = isset($event['price_in_purchased_currency']) ? (float) $event['price_in_purchased_currency'] : (isset($event['price']) ? (float) $event['price'] : null);
            $currency = isset($event['currency']) ? strtolower((string) $event['currency']) : null;
            $at = isset($event['purchased_at_ms']) ? (new \DateTimeImmutable())->setTimestamp((int) ((int) $event['purchased_at_ms'] / 1000)) : null;
            $environment = isset($event['environment']) ? strtolower((string) $event['environment']) : null;
            $created = $this->payments->recordPurchase($user, $store, (string) ($event['product_id'] ?? ''), $transactionId, $amount, $currency, $at, true, $environment);

            return ['action' => $created ? 'recorded' : 'already_known', 'transactionId' => $transactionId];
        }
        if (in_array($type, self::REFUND_TYPES, true)) {
            $payment = $this->payments->refund($store, $transactionId, isset($event['price_in_purchased_currency']) ? abs((float) $event['price_in_purchased_currency']) : null);

            return ['action' => $payment === null ? 'unknown_transaction' : 'refunded', 'transactionId' => $transactionId];
        }
        $this->logger->info('RevenueCat : événement ignoré ' . $type);

        return ['action' => 'ignored', 'reason' => 'type ' . $type];
    }
}
