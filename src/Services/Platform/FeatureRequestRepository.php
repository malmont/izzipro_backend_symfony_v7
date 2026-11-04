<?php

namespace App\Services\Platform;

use App\Services\TenantConnectionManager;

/** Demandes des sites pour une fonctionnalité de la plateforme, base maître tenant_feature_requests (lot 3, 04/11/2026) */
class FeatureRequestRepository
{
    private const COLUMNS = 'id, tenant_code, feature_key, requested_by, message, status, created_at, decided_at, decided_by';

    public function __construct(private readonly TenantConnectionManager $tenants)
    {
    }

    public function insert(string $tenantCode, string $featureKey, ?string $by, ?string $message): array
    {
        $stmt = $this->tenants->getPdoMaster()->prepare('INSERT INTO tenant_feature_requests (tenant_code, feature_key, requested_by, message) VALUES (?, ?, ?, ?) RETURNING ' . self::COLUMNS);
        $stmt->execute([$tenantCode, $featureKey, $by !== null ? mb_substr($by, 0, 255) : null, $message !== null ? mb_substr($message, 0, 2000) : null]);

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /** @return array<string, string> clé => 'pending' pour les demandes en attente du site */
    public function pendingFor(string $tenantCode): array
    {
        $stmt = $this->tenants->getPdoMaster()->prepare("SELECT feature_key FROM tenant_feature_requests WHERE tenant_code = ? AND status = 'pending'");
        $stmt->execute([$tenantCode]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $key) {
            $out[$key] = 'pending';
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function listPending(): array
    {
        return $this->tenants->getPdoMaster()->query('SELECT ' . self::COLUMNS . " FROM tenant_feature_requests WHERE status = 'pending' ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->tenants->getPdoMaster()->prepare('SELECT ' . self::COLUMNS . ' FROM tenant_feature_requests WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function decide(int $id, string $status, ?string $by): void
    {
        if (!in_array($status, ['granted', 'refused'], true)) {
            throw new \InvalidArgumentException('statut : granted ou refused attendu');
        }
        $this->tenants->getPdoMaster()->prepare('UPDATE tenant_feature_requests SET status = ?, decided_at = NOW(), decided_by = ? WHERE id = ?')->execute([$status, $by, $id]);
    }
}
