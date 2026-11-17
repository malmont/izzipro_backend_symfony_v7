<?php

namespace App\LossPrevention\Master;

use App\Services\TenantConnectionManager;

/**
 * Le matériel vendu par la plateforme (lot L6-a, 17/11/2026, `docs/prevention-pertes.md` § 19) : table `loss_hardware_products` de la
 * BASE MAÎTRE (les prix et les liens d'achat sont ceux de la plateforme, les mêmes pour tous les sites). Schéma :
 * `ops/sql/loss_hardware_master.sql`. Comme `LossPairingRepository`, ce dépôt parle à la base maître : il est un service, hors du
 * dossier `Repository/` du module.
 */
class LossHardwareProductRepository
{
    public const KINDS = ['box', 'hand_reader', 'counter_mat', 'zone_antenna', 'printer', 'signal', 'tags'];
    public const LEVELS = ['pro', 'standard'];
    private const COLUMNS = 'code, kind, level, label_fr, label_en, description_fr, description_en, price_cents, currency, buy_url, is_active, position, updated_at, updated_by';
    /** Champs modifiables par le super administrateur, et leur colonne */
    public const EDITABLE = ['kind' => 'kind', 'level' => 'level', 'labelFr' => 'label_fr', 'labelEn' => 'label_en', 'descriptionFr' => 'description_fr',
        'descriptionEn' => 'description_en', 'priceCents' => 'price_cents', 'currency' => 'currency', 'buyUrl' => 'buy_url', 'isActive' => 'is_active', 'position' => 'position'];

    public function __construct(private readonly TenantConnectionManager $tenants)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(bool $activeOnly = false): array
    {
        $rows = $this->tenants->getPdoMaster()->query('SELECT ' . self::COLUMNS . ' FROM loss_hardware_products' . ($activeOnly ? ' WHERE is_active' : '') . ' ORDER BY position, code')
            ->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_map([self::class, 'normalize'], $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(string $code): ?array
    {
        $stmt = $this->tenants->getPdoMaster()->prepare('SELECT ' . self::COLUMNS . ' FROM loss_hardware_products WHERE code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? self::normalize($row) : null;
    }

    /**
     * Crée ou modifie un article (clés de EDITABLE). Rend la liste des erreurs (vide si écrit), au format `{ path, message }`.
     *
     * @param array<string, mixed> $values
     *
     * @return list<array{path: string, message: string}>
     */
    public function upsert(string $code, array $values, ?string $by): array
    {
        $errors = [];
        if (!preg_match('/^[a-z0-9_]{2,64}$/', $code)) {
            $errors[] = ['path' => 'code', 'message' => 'code : lettres minuscules, chiffres et _ (2 à 64)'];
        }
        $merged = $this->find($code) ?? ['code' => $code, 'kind' => null, 'level' => null, 'labelFr' => '', 'labelEn' => '', 'descriptionFr' => '', 'descriptionEn' => '',
            'priceCents' => null, 'currency' => 'CAD', 'buyUrl' => null, 'isActive' => false, 'position' => 0];
        foreach ($values as $key => $value) {
            if (!isset(self::EDITABLE[$key])) {
                $errors[] = ['path' => (string) $key, 'message' => 'champ inconnu'];
                continue;
            }
            $merged[$key] = $value;
        }
        if (!in_array($merged['kind'], self::KINDS, true)) {
            $errors[] = ['path' => 'kind', 'message' => 'parmi : ' . implode(', ', self::KINDS)];
        }
        if (!in_array($merged['level'], self::LEVELS, true)) {
            $errors[] = ['path' => 'level', 'message' => 'parmi : ' . implode(', ', self::LEVELS)];
        }
        foreach (['labelFr', 'labelEn'] as $k) {
            if (!is_string($merged[$k]) || trim($merged[$k]) === '' || mb_strlen($merged[$k]) > 160) {
                $errors[] = ['path' => $k, 'message' => 'libellé de 1 à 160 caractères attendu'];
            }
        }
        foreach (['descriptionFr', 'descriptionEn'] as $k) {
            if (!is_string($merged[$k]) || mb_strlen($merged[$k]) > 600) {
                $errors[] = ['path' => $k, 'message' => 'texte de 600 caractères au plus attendu'];
            }
        }
        $price = $merged['priceCents'];
        if ($price !== null && (!is_int($price) || $price < 0 || $price > 100000000)) {
            $errors[] = ['path' => 'priceCents', 'message' => 'entier en cents ≥ 0 attendu, ou null'];
        }
        if (!is_string($merged['currency']) || !preg_match('/^[A-Z]{3}$/', $merged['currency'])) {
            $errors[] = ['path' => 'currency', 'message' => 'code ISO 4217 attendu (ex. CAD)'];
        }
        $url = $merged['buyUrl'];
        if ($url !== null && (!is_string($url) || mb_strlen($url) > 500 || !self::isHttpsUrl($url))) {
            $errors[] = ['path' => 'buyUrl', 'message' => 'adresse https:// complète attendue, ou null'];
        }
        if (!is_bool($merged['isActive'])) {
            $errors[] = ['path' => 'isActive', 'message' => 'booléen attendu'];
        } elseif ($merged['isActive'] && $price === null) {
            $errors[] = ['path' => 'isActive', 'message' => 'un article montré aux sites a un prix'];
        }
        if (!is_int($merged['position']) || $merged['position'] < 0 || $merged['position'] > 100000) {
            $errors[] = ['path' => 'position', 'message' => 'entier de 0 à 100000 attendu'];
        }
        if ($errors !== []) {
            return $errors;
        }
        $this->tenants->getPdoMaster()->prepare('INSERT INTO loss_hardware_products (' . self::COLUMNS . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
            . ' ON CONFLICT (code) DO UPDATE SET kind = EXCLUDED.kind, level = EXCLUDED.level, label_fr = EXCLUDED.label_fr, label_en = EXCLUDED.label_en,'
            . ' description_fr = EXCLUDED.description_fr, description_en = EXCLUDED.description_en, price_cents = EXCLUDED.price_cents, currency = EXCLUDED.currency,'
            . ' buy_url = EXCLUDED.buy_url, is_active = EXCLUDED.is_active, position = EXCLUDED.position, updated_at = NOW(), updated_by = EXCLUDED.updated_by')
            ->execute([$code, $merged['kind'], $merged['level'], trim($merged['labelFr']), trim($merged['labelEn']), $merged['descriptionFr'], $merged['descriptionEn'],
                $price, $merged['currency'], $url, $merged['isActive'] ? 'true' : 'false', $merged['position'], $by]);

        return [];
    }

    /** Une adresse https:// complète, avec un hôte, sans identifiants ni espaces */
    public static function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
            && preg_match('/\s/u', $url) !== 1;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        return [
            'code' => (string) $row['code'], 'kind' => (string) $row['kind'], 'level' => (string) $row['level'],
            'labelFr' => (string) $row['label_fr'], 'labelEn' => (string) $row['label_en'],
            'descriptionFr' => (string) $row['description_fr'], 'descriptionEn' => (string) $row['description_en'],
            'priceCents' => $row['price_cents'] !== null ? (int) $row['price_cents'] : null, 'currency' => (string) $row['currency'],
            'buyUrl' => $row['buy_url'] !== null ? (string) $row['buy_url'] : null,
            'isActive' => in_array($row['is_active'], [true, 't', 'true', 1, '1'], true), 'position' => (int) $row['position'],
            'updatedAt' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null, 'updatedBy' => $row['updated_by'] !== null ? (string) $row['updated_by'] : null,
        ];
    }
}
