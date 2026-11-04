<?php

namespace App\Services\Platform;

use App\Services\TenantConnectionManager;

/** Politique par fonctionnalité (essai, gratuité, grâce), base maître platform_feature_policies ; valeurs par défaut sans ligne (lot 3) */
class FeaturePolicyRepository
{
    public const DEFAULTS = ['trial_allowed' => true, 'trial_days' => 30, 'free_allowed' => true, 'grace_days' => 7];

    public function __construct(private readonly TenantConnectionManager $tenants)
    {
    }

    /** @return array{feature_key: string, trial_allowed: bool, trial_days: int, free_allowed: bool, grace_days: int, updated_at: ?string, updated_by: ?string} */
    public function get(string $featureKey): array
    {
        $stmt = $this->tenants->getPdoMaster()->prepare('SELECT feature_key, trial_allowed, trial_days, free_allowed, grace_days, updated_at, updated_by FROM platform_feature_policies WHERE feature_key = ?');
        $stmt->execute([$featureKey]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return self::normalize($featureKey, $row ?: []);
    }

    /** @return array<string, array<string, mixed>> toutes les politiques, défauts compris, par clé */
    public function all(array $featureKeys): array
    {
        $rows = [];
        foreach ($this->tenants->getPdoMaster()->query('SELECT feature_key, trial_allowed, trial_days, free_allowed, grace_days, updated_at, updated_by FROM platform_feature_policies')->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[$row['feature_key']] = $row;
        }
        $out = [];
        foreach ($featureKeys as $key) {
            $out[$key] = self::normalize($key, $rows[$key] ?? []);
        }

        return $out;
    }

    /** @param array{trial_allowed?: bool, trial_days?: int, free_allowed?: bool, grace_days?: int} $values */
    public function upsert(string $featureKey, array $values, ?string $by): array
    {
        $current = $this->get($featureKey);
        $merged = array_merge($current, array_intersect_key($values, self::DEFAULTS));
        $this->tenants->getPdoMaster()->prepare('INSERT INTO platform_feature_policies (feature_key, trial_allowed, trial_days, free_allowed, grace_days, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, NOW(), ?) ON CONFLICT (feature_key) DO UPDATE SET trial_allowed = EXCLUDED.trial_allowed, trial_days = EXCLUDED.trial_days, free_allowed = EXCLUDED.free_allowed, grace_days = EXCLUDED.grace_days, updated_at = NOW(), updated_by = EXCLUDED.updated_by')
            ->execute([$featureKey, $merged['trial_allowed'] ? 't' : 'f', (int) $merged['trial_days'], $merged['free_allowed'] ? 't' : 'f', (int) $merged['grace_days'], $by]);

        return $this->get($featureKey);
    }

    private static function normalize(string $key, array $row): array
    {
        return [
            'feature_key' => $key,
            'trial_allowed' => array_key_exists('trial_allowed', $row) ? filter_var($row['trial_allowed'], FILTER_VALIDATE_BOOLEAN) : self::DEFAULTS['trial_allowed'],
            'trial_days' => (int) ($row['trial_days'] ?? self::DEFAULTS['trial_days']),
            'free_allowed' => array_key_exists('free_allowed', $row) ? filter_var($row['free_allowed'], FILTER_VALIDATE_BOOLEAN) : self::DEFAULTS['free_allowed'],
            'grace_days' => (int) ($row['grace_days'] ?? self::DEFAULTS['grace_days']),
            'updated_at' => $row['updated_at'] ?? null,
            'updated_by' => $row['updated_by'] ?? null,
        ];
    }
}
