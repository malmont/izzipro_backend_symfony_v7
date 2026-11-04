<?php

namespace App\Services\Platform;

use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\TenantConnectionManager;

/** Offres de la plateforme (fonctionnalité seule ou lot, prix mensuel), base maître platform_offers (lot 3 bis, 04/11/2026) */
class PlatformOfferRepository
{
    private const COLUMNS = 'code, label_fr, label_en, features, monthly_price_cents, yearly_price_cents, currency, trial_days, is_active, position, updated_at, updated_by';
    public const EDITABLE = ['labelFr' => 'label_fr', 'labelEn' => 'label_en', 'features' => 'features', 'monthlyPriceCents' => 'monthly_price_cents', 'yearlyPriceCents' => 'yearly_price_cents', 'currency' => 'currency', 'trialDays' => 'trial_days', 'isActive' => 'is_active', 'position' => 'position'];

    public function __construct(private readonly TenantConnectionManager $tenants)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(bool $activeOnly = false): array
    {
        $rows = $this->tenants->getPdoMaster()->query('SELECT ' . self::COLUMNS . ' FROM platform_offers' . ($activeOnly ? ' WHERE is_active' : '') . ' ORDER BY position, code')->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_map([self::class, 'normalize'], $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(string $code): ?array
    {
        $stmt = $this->tenants->getPdoMaster()->prepare('SELECT ' . self::COLUMNS . ' FROM platform_offers WHERE code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? self::normalize($row) : null;
    }

    /**
     * Crée ou modifie une offre (clés de EDITABLE) ; valide : libellés non vides, features ⊂ fonctionnalités gouvernées et non vide, prix ≥ 0, devise ISO, essai 0..365.
     * @param array<string, mixed> $values
     * @return list<array{path: string, message: string}> erreurs (vide si écrit)
     */
    public function upsert(string $code, array $values, ?string $by): array
    {
        $errors = [];
        if (!preg_match('/^[a-z0-9_]{2,64}$/', $code)) {
            $errors[] = ['path' => 'code', 'message' => 'code : lettres minuscules, chiffres et _ (2 à 64)'];
        }
        $current = $this->find($code) ?? ['code' => $code, 'labelFr' => '', 'labelEn' => '', 'features' => [], 'monthlyPriceCents' => 0, 'yearlyPriceCents' => null, 'currency' => 'CAD', 'trialDays' => 0, 'isActive' => true, 'position' => 0];
        $merged = $current;
        foreach ($values as $key => $value) {
            if (!isset(self::EDITABLE[$key])) {
                $errors[] = ['path' => (string) $key, 'message' => 'champ inconnu'];
                continue;
            }
            $merged[$key] = $value;
        }
        foreach (['labelFr', 'labelEn'] as $k) {
            if (!is_string($merged[$k]) || trim($merged[$k]) === '' || mb_strlen($merged[$k]) > 160) {
                $errors[] = ['path' => $k, 'message' => 'libellé de 1 à 160 caractères attendu'];
            }
        }
        $features = $merged['features'];
        if (!is_array($features) || $features === [] || array_diff($features, array_keys(FeatureCatalog::GOVERNED)) !== [] || count(array_unique($features)) !== count($features)) {
            $errors[] = ['path' => 'features', 'message' => 'liste non vide, sans doublon, parmi : ' . implode(', ', array_keys(FeatureCatalog::GOVERNED))];
        }
        foreach (['monthlyPriceCents', 'yearlyPriceCents'] as $k) {
            $v = $merged[$k];
            if ($v !== null && (!is_int($v) || $v < 0 || $v > 100000000)) {
                $errors[] = ['path' => $k, 'message' => 'entier en cents ≥ 0 attendu' . ($k === 'yearlyPriceCents' ? ', ou null' : '')];
            }
        }
        if ($merged['monthlyPriceCents'] === null) {
            $errors[] = ['path' => 'monthlyPriceCents', 'message' => 'entier en cents ≥ 0 attendu'];
        }
        if (!is_string($merged['currency']) || !preg_match('/^[A-Z]{3}$/', $merged['currency'])) {
            $errors[] = ['path' => 'currency', 'message' => 'code ISO 4217 attendu (ex. CAD)'];
        }
        if (!is_int($merged['trialDays']) || $merged['trialDays'] < 0 || $merged['trialDays'] > 365) {
            $errors[] = ['path' => 'trialDays', 'message' => 'entier de 0 à 365 attendu'];
        }
        if (!is_bool($merged['isActive'])) {
            $errors[] = ['path' => 'isActive', 'message' => 'booléen attendu'];
        }
        if (!is_int($merged['position'])) {
            $errors[] = ['path' => 'position', 'message' => 'entier attendu'];
        }
        if ($errors !== []) {
            return $errors;
        }
        $this->tenants->getPdoMaster()->prepare('INSERT INTO platform_offers (code, label_fr, label_en, features, monthly_price_cents, yearly_price_cents, currency, trial_days, is_active, position, updated_at, updated_by) VALUES (?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?, NOW(), ?) ON CONFLICT (code) DO UPDATE SET label_fr = EXCLUDED.label_fr, label_en = EXCLUDED.label_en, features = EXCLUDED.features, monthly_price_cents = EXCLUDED.monthly_price_cents, yearly_price_cents = EXCLUDED.yearly_price_cents, currency = EXCLUDED.currency, trial_days = EXCLUDED.trial_days, is_active = EXCLUDED.is_active, position = EXCLUDED.position, updated_at = NOW(), updated_by = EXCLUDED.updated_by')
            ->execute([$code, trim($merged['labelFr']), trim($merged['labelEn']), json_encode(array_values($features)), $merged['monthlyPriceCents'], $merged['yearlyPriceCents'], $merged['currency'], $merged['trialDays'], $merged['isActive'] ? 't' : 'f', $merged['position'], $by]);

        return [];
    }

    /** @return array<string, mixed> */
    private static function normalize(array $row): array
    {
        $features = is_string($row['features']) ? json_decode($row['features'], true) : $row['features'];

        return [
            'code' => $row['code'], 'labelFr' => $row['label_fr'], 'labelEn' => $row['label_en'], 'features' => is_array($features) ? array_values($features) : [],
            'monthlyPriceCents' => (int) $row['monthly_price_cents'], 'yearlyPriceCents' => $row['yearly_price_cents'] !== null ? (int) $row['yearly_price_cents'] : null,
            'currency' => $row['currency'], 'trialDays' => (int) $row['trial_days'], 'isActive' => filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN), 'position' => (int) $row['position'],
            'updatedAt' => $row['updated_at'], 'updatedBy' => $row['updated_by'],
        ];
    }
}
