<?php

namespace App\Services\Platform;

use App\Services\TenantConnectionManager;

/**
 * Solde prépayé d'expédition d'un site, base maître (lot 2 Livraison, 07/11/2026) : platform_shipping_balances (un solde par site),
 * platform_shipping_balance_events (chaque mouvement : credit | label | refund), platform_shipping_charges (chaque étiquette achetée
 * par la plateforme : coût EasyPost, frais de service, total, suivi). Le débit d'une étiquette verrouille la ligne du solde et refuse si
 * le solde ne couvre pas le total.
 */
class ShippingBalanceRepository
{
    public function __construct(private readonly TenantConnectionManager $tenants)
    {
    }

    /** @return array{tenantCode: string, balanceCents: int, currency: string, updatedAt: ?string} */
    public function balance(string $tenantCode): array
    {
        $stmt = $this->tenants->getPdoMaster()->prepare('SELECT balance_cents, currency, updated_at FROM platform_shipping_balances WHERE tenant_code = ?');
        $stmt->execute([$tenantCode]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return ['tenantCode' => $tenantCode, 'balanceCents' => (int) ($row['balance_cents'] ?? 0), 'currency' => (string) ($row['currency'] ?? 'CAD'), 'updatedAt' => $row['updated_at'] ?? null];
    }

    /** @return list<array{tenantCode: string, balanceCents: int, currency: string, updatedAt: ?string}> */
    public function all(): array
    {
        $rows = $this->tenants->getPdoMaster()->query('SELECT tenant_code, balance_cents, currency, updated_at FROM platform_shipping_balances ORDER BY tenant_code')->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_map(fn ($r) => ['tenantCode' => (string) $r['tenant_code'], 'balanceCents' => (int) $r['balance_cents'], 'currency' => (string) $r['currency'], 'updatedAt' => $r['updated_at']], $rows);
    }

    /**
     * Crédit (montant positif) ou remboursement ; kind : credit | refund.
     *
     * @return array{balanceCents: int, currency: string}
     */
    public function credit(string $tenantCode, int $cents, string $kind, ?string $reference, ?string $note, ?string $by, string $currency = 'CAD'): array
    {
        if ($cents <= 0) {
            throw new \InvalidArgumentException('montant positif attendu');
        }
        if (!in_array($kind, ['credit', 'refund'], true)) {
            throw new \InvalidArgumentException('kind : credit ou refund attendu');
        }

        return $this->move($tenantCode, $cents, $kind, $reference, $note, $by, $currency);
    }

    /**
     * Débit d'une étiquette ; refuse si le solde ne couvre pas le total.
     *
     * @return array{balanceCents: int, currency: string}
     * @throws InsufficientShippingBalanceException
     */
    public function debit(string $tenantCode, int $cents, ?string $reference, ?string $note, ?string $by): array
    {
        if ($cents <= 0) {
            throw new \InvalidArgumentException('montant positif attendu');
        }

        return $this->move($tenantCode, -$cents, 'label', $reference, $note, $by, null);
    }

    /** @return array{balanceCents: int, currency: string} */
    private function move(string $tenantCode, int $delta, string $kind, ?string $reference, ?string $note, ?string $by, ?string $currency): array
    {
        $pdo = $this->tenants->getPdoMaster();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO platform_shipping_balances (tenant_code, balance_cents, currency) VALUES (?, 0, ?) ON CONFLICT (tenant_code) DO NOTHING')->execute([$tenantCode, $currency ?? 'CAD']);
            $stmt = $pdo->prepare('SELECT balance_cents, currency FROM platform_shipping_balances WHERE tenant_code = ? FOR UPDATE');
            $stmt->execute([$tenantCode]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $current = (int) $row['balance_cents'];
            $after = $current + $delta;
            if ($after < 0) {
                $pdo->rollBack();
                throw new InsufficientShippingBalanceException($current, -$delta, (string) $row['currency']);
            }
            $pdo->prepare('UPDATE platform_shipping_balances SET balance_cents = ?, updated_at = NOW() WHERE tenant_code = ?')->execute([$after, $tenantCode]);
            $pdo->prepare('INSERT INTO platform_shipping_balance_events (tenant_code, kind, delta_cents, balance_after_cents, reference, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$tenantCode, $kind, $delta, $after, $reference !== null ? mb_substr($reference, 0, 255) : null, $note !== null ? mb_substr($note, 0, 2000) : null, $by !== null ? mb_substr($by, 0, 255) : null]);
            $pdo->commit();

            return ['balanceCents' => $after, 'currency' => (string) $row['currency']];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> derniers mouvements du site */
    public function events(string $tenantCode, int $limit = 50): array
    {
        $stmt = $this->tenants->getPdoMaster()->prepare('SELECT id, kind, delta_cents, balance_after_cents, reference, note, created_by, created_at FROM platform_shipping_balance_events WHERE tenant_code = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
        $stmt->execute([$tenantCode]);

        return array_map(fn ($r) => ['id' => (int) $r['id'], 'kind' => $r['kind'], 'deltaCents' => (int) $r['delta_cents'], 'balanceAfterCents' => (int) $r['balance_after_cents'], 'reference' => $r['reference'], 'note' => $r['note'], 'by' => $r['created_by'], 'at' => $r['created_at']], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /** @param list<string> $trackingCodes */
    public function recordCharge(string $tenantCode, int $orderId, ?string $orderReference, string $carrier, string $service, int $parcels, int $easypostCostCents, int $feeCents, string $currency, array $trackingCodes, string $environment, ?string $by): int
    {
        $stmt = $this->tenants->getPdoMaster()->prepare('INSERT INTO platform_shipping_charges (tenant_code, order_id, order_reference, carrier, service, parcels, easypost_cost_cents, fee_cents, total_cents, currency, tracking_codes, environment, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id');
        $stmt->execute([$tenantCode, $orderId, $orderReference, mb_substr($carrier, 0, 100), mb_substr($service, 0, 100), $parcels, $easypostCostCents, $feeCents, $easypostCostCents + $feeCents, $currency, json_encode($trackingCodes), $environment, $by]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function charges(string $tenantCode, int $limit = 50): array
    {
        $stmt = $this->tenants->getPdoMaster()->prepare('SELECT id, order_id, order_reference, carrier, service, parcels, easypost_cost_cents, fee_cents, total_cents, currency, tracking_codes, environment, created_by, created_at FROM platform_shipping_charges WHERE tenant_code = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
        $stmt->execute([$tenantCode]);

        return array_map(fn ($r) => ['id' => (int) $r['id'], 'orderId' => (int) $r['order_id'], 'orderReference' => $r['order_reference'], 'carrier' => $r['carrier'], 'service' => $r['service'], 'parcels' => (int) $r['parcels'], 'easypostCostCents' => (int) $r['easypost_cost_cents'], 'feeCents' => (int) $r['fee_cents'], 'totalCents' => (int) $r['total_cents'], 'currency' => $r['currency'], 'trackingCodes' => json_decode((string) $r['tracking_codes'], true) ?: [], 'environment' => $r['environment'], 'by' => $r['created_by'], 'at' => $r['created_at']], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }
}
