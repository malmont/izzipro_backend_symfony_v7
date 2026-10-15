<?php

namespace App\Repository;

use App\Services\TenantConnectionManager;

/**
 * Clés d'activation des applications (base maître, table tenant_app_keys) : donnée de plateforme qui associe une
 * clé (hachée) à un site et à une application. Schéma : scripts/migrate_master_app_keys.sh.
 */
class TenantAppKeyRepository
{
    private const COLUMNS = 'id, tenant_code, app, key_hint, label, created_by, created_at, expires_at, revoked_at, last_used_at, last_device, use_count, cash_register_id, cash_register_name';

    public function __construct(private readonly TenantConnectionManager $connectionManager)
    {
    }

    /** @param array{id: int, name: string}|null $cashRegister poste de caisse (clé POS), copié ici : l'activation n'a pas accès au site */
    public function insert(string $tenantCode, string $app, string $keyHash, string $keyHint, string $label, string $createdBy, ?\DateTimeInterface $expiresAt, ?array $cashRegister = null): array
    {
        $pdo = $this->connectionManager->getPdoMaster();
        $stmt = $pdo->prepare('INSERT INTO tenant_app_keys (tenant_code, app, key_hash, key_hint, label, created_by, created_at, expires_at, cash_register_id, cash_register_name) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?) RETURNING ' . self::COLUMNS);
        $stmt->execute([$tenantCode, $app, $keyHash, $keyHint, mb_substr($label, 0, 100), mb_substr($createdBy, 0, 255), $expiresAt?->format('Y-m-d H:i:s'), $cashRegister['id'] ?? null, isset($cashRegister['name']) ? mb_substr($cashRegister['name'], 0, 100) : null]);

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /** Libellé et poste modifiables ; $cashRegister : tableau pour poser, null pour effacer, non fourni ($setRegister faux) pour garder */
    public function update(int $id, string $tenantCode, ?string $label, bool $setRegister, ?array $cashRegister): void
    {
        $sets = [];
        $params = [];
        if ($label !== null) {
            $sets[] = 'label = ?';
            $params[] = mb_substr($label, 0, 100);
        }
        if ($setRegister) {
            $sets[] = 'cash_register_id = ?';
            $sets[] = 'cash_register_name = ?';
            $params[] = $cashRegister['id'] ?? null;
            $params[] = isset($cashRegister['name']) ? mb_substr($cashRegister['name'], 0, 100) : null;
        }
        if (!$sets) {
            return;
        }
        $params[] = $id;
        $params[] = $tenantCode;
        $this->connectionManager->getPdoMaster()->prepare('UPDATE tenant_app_keys SET ' . implode(', ', $sets) . ' WHERE id = ? AND tenant_code = ?')->execute($params);
    }

    public function findByHash(string $keyHash): ?array
    {
        $stmt = $this->connectionManager->getPdoMaster()->prepare('SELECT ' . self::COLUMNS . ' FROM tenant_app_keys WHERE key_hash = ? LIMIT 1');
        $stmt->execute([$keyHash]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function findOneForTenant(int $id, string $tenantCode): ?array
    {
        $stmt = $this->connectionManager->getPdoMaster()->prepare('SELECT ' . self::COLUMNS . ' FROM tenant_app_keys WHERE id = ? AND tenant_code = ? LIMIT 1');
        $stmt->execute([$id, $tenantCode]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<array<string, mixed>> clés du site, les plus récentes d'abord */
    public function listForTenant(string $tenantCode): array
    {
        $stmt = $this->connectionManager->getPdoMaster()->prepare('SELECT ' . self::COLUMNS . ' FROM tenant_app_keys WHERE tenant_code = ? ORDER BY id DESC');
        $stmt->execute([$tenantCode]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function revoke(int $id, string $tenantCode): bool
    {
        $stmt = $this->connectionManager->getPdoMaster()->prepare('UPDATE tenant_app_keys SET revoked_at = NOW() WHERE id = ? AND tenant_code = ? AND revoked_at IS NULL');
        $stmt->execute([$id, $tenantCode]);

        return $stmt->rowCount() > 0;
    }

    public function revokeAllForApp(string $tenantCode, string $app): int
    {
        $stmt = $this->connectionManager->getPdoMaster()->prepare('UPDATE tenant_app_keys SET revoked_at = NOW() WHERE tenant_code = ? AND app = ? AND revoked_at IS NULL');
        $stmt->execute([$tenantCode, $app]);

        return $stmt->rowCount();
    }

    public function touch(int $id, ?string $device): void
    {
        $this->connectionManager->getPdoMaster()
            ->prepare('UPDATE tenant_app_keys SET last_used_at = NOW(), use_count = use_count + 1, last_device = COALESCE(?, last_device) WHERE id = ?')
            ->execute([$device !== null ? mb_substr($device, 0, 100) : null, $id]);
    }
}
