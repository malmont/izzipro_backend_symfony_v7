<?php

namespace App\Services\Platform;

use App\Services\TenantConnectionManager;
use Psr\Log\LoggerInterface;

/**
 * Étiquettes par la plateforme (lot 2 Livraison, 07/11/2026), côté plateforme : frais de service appliqués au coût EasyPost
 * (`shipping_fee_bp` en points de base + `shipping_fee_fixed_cents` par étiquette), comptes transporteurs de la clé EasyPost de la
 * plateforme (`easypost_platform_carriers`, JSON : [{id: "ca_…", carrier: "CanadaPost", label: "Postes Canada"}]), et crédit des soldes
 * prépayés par le super administrateur (plus tard par la boutique de forfaits). Tout en base maître (platform_settings et tables des soldes).
 */
class PlatformShippingService
{
    public const SETTING_FEE_BP = 'shipping_fee_bp';
    public const SETTING_FEE_FIXED = 'shipping_fee_fixed_cents';
    public const SETTING_CARRIERS = 'easypost_platform_carriers';

    public function __construct(private readonly TenantConnectionManager $tenants, private readonly ShippingBalanceRepository $balances, private readonly LoggerInterface $logger)
    {
    }

    /** @return array{feeBp: int, feeFixedCents: int, carriers: list<array{id: string, carrier: string, label: string}>} */
    public function settings(): array
    {
        $rows = [];
        try {
            foreach ($this->tenants->getPdoMaster()->query("SELECT key, value FROM platform_settings WHERE key IN ('shipping_fee_bp', 'shipping_fee_fixed_cents', 'easypost_platform_carriers')")->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
                $rows[$r['key']] = $r['value'];
            }
        } catch (\Throwable) {
            $rows = [];
        }
        $carriers = json_decode((string) ($rows[self::SETTING_CARRIERS] ?? '[]'), true);

        return [
            'feeBp' => max(0, (int) ($rows[self::SETTING_FEE_BP] ?? 0)),
            'feeFixedCents' => max(0, (int) ($rows[self::SETTING_FEE_FIXED] ?? 0)),
            'carriers' => is_array($carriers) ? array_values(array_filter(array_map(fn ($c) => is_array($c) && isset($c['id']) ? ['id' => (string) $c['id'], 'carrier' => (string) ($c['carrier'] ?? ''), 'label' => (string) ($c['label'] ?? $c['carrier'] ?? $c['id'])] : null, $carriers))) : [],
        ];
    }

    /** @return list<string> identifiants des comptes transporteurs de la plateforme */
    public function carrierAccountIds(): array
    {
        return array_map(fn ($c) => $c['id'], $this->settings()['carriers']);
    }

    /** Frais de service pour un coût EasyPost donné (cents) : marge en points de base + frais fixes par étiquette */
    public function fee(int $easypostCostCents, int $parcels = 1): int
    {
        $s = $this->settings();

        return (int) round($easypostCostCents * $s['feeBp'] / 10000) + $s['feeFixedCents'] * max(1, $parcels);
    }

    public function setFees(int $feeBp, int $feeFixedCents, ?string $by): void
    {
        if ($feeBp < 0 || $feeBp > 10000 || $feeFixedCents < 0 || $feeFixedCents > 100000) {
            throw new \InvalidArgumentException('frais : marge entre 0 et 10000 points de base, frais fixes entre 0 et 100000 cents');
        }
        $pdo = $this->tenants->getPdoMaster();
        foreach ([self::SETTING_FEE_BP => $feeBp, self::SETTING_FEE_FIXED => $feeFixedCents] as $key => $value) {
            $pdo->prepare('INSERT INTO platform_settings (key, value, updated_at, updated_by) VALUES (?, ?, NOW(), ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by')->execute([$key, (string) $value, $by]);
        }
        $this->logger->notice(sprintf('Frais d\'étiquette de la plateforme : %d pb + %d cents (par %s)', $feeBp, $feeFixedCents, $by ?? 'inconnu'));
    }

    /** @param list<array{id: string, carrier?: string, label?: string}> $carriers */
    public function setCarriers(array $carriers, ?string $by): void
    {
        $clean = [];
        foreach ($carriers as $c) {
            $id = trim((string) ($c['id'] ?? ''));
            if ($id === '' || !preg_match('/^ca_[a-zA-Z0-9_]{6,64}$/', $id)) {
                throw new \InvalidArgumentException('compte transporteur : identifiant EasyPost « ca_… » attendu');
            }
            $clean[] = ['id' => $id, 'carrier' => mb_substr(trim((string) ($c['carrier'] ?? '')), 0, 60), 'label' => mb_substr(trim((string) ($c['label'] ?? $c['carrier'] ?? $id)), 0, 100)];
        }
        $this->tenants->getPdoMaster()->prepare('INSERT INTO platform_settings (key, value, updated_at, updated_by) VALUES (?, ?, NOW(), ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by')
            ->execute([self::SETTING_CARRIERS, json_encode($clean, JSON_UNESCAPED_UNICODE), $by]);
        $this->logger->notice(sprintf('Comptes transporteurs de la plateforme : %d (par %s)', count($clean), $by ?? 'inconnu'));
    }

    /** Crédit par le super administrateur (ou par la boutique de forfaits plus tard) @return array{balanceCents: int, currency: string} */
    public function credit(string $tenantCode, int $cents, ?string $note, ?string $by, string $kind = 'credit', ?string $reference = null): array
    {
        if (!is_array($this->tenants->findTenantByCode($tenantCode))) {
            throw new \InvalidArgumentException('site inconnu');
        }
        $result = $this->balances->credit($tenantCode, $cents, $kind, $reference, $note, $by);
        $this->logger->notice(sprintf('Solde d\'expédition de %s : +%d cents (%s, par %s) → %d', $tenantCode, $cents, $kind, $by ?? 'inconnu', $result['balanceCents']));

        return $result;
    }
}
