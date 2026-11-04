<?php

namespace App\Services\Platform;

use App\Services\TenantConnectionManager;

/** Droits d'usage, base maître (table tenant_entitlements + journal tenant_entitlement_events), PDO (lot 3, 04/11/2026) */
class EntitlementRepository
{
    public const SOURCES = ['manual', 'trial', 'free', 'subscription', 'purchase'];
    public const STATUSES = ['active', 'grace', 'suspended', 'expired', 'cancelled'];
    public const LIVE_STATUSES = ['active', 'grace'];
    private const COLUMNS = 'id, tenant_code, feature_key, source, status, starts_at, ends_at, grace_until, offer_code, reference, granted_by, note, created_at, updated_at';

    public function __construct(private readonly TenantConnectionManager $tenants)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listForTenant(string $tenantCode, bool $liveOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM tenant_entitlements WHERE tenant_code = ?' . ($liveOnly ? " AND status IN ('active', 'grace')" : '') . ' ORDER BY feature_key, id DESC';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([$tenantCode]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> tous les droits vivants (grille de la plateforme) */
    public function listLive(): array
    {
        return $this->pdo()->query('SELECT ' . self::COLUMNS . " FROM tenant_entitlements WHERE status IN ('active', 'grace') ORDER BY tenant_code, feature_key, id DESC")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> droits vivants d'une fonctionnalité d'un site */
    public function liveFor(string $tenantCode, string $featureKey): array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . " FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = ? AND status IN ('active', 'grace') ORDER BY id DESC");
        $stmt->execute([$tenantCode, $featureKey]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM tenant_entitlements WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed> la ligne créée */
    public function insert(string $tenantCode, string $featureKey, string $source, ?\DateTimeInterface $endsAt, ?string $grantedBy, ?string $note, ?string $offerCode, ?string $reference, string $status = 'active'): array
    {
        $stmt = $this->pdo()->prepare('INSERT INTO tenant_entitlements (tenant_code, feature_key, source, status, starts_at, ends_at, offer_code, reference, granted_by, note) VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?) RETURNING ' . self::COLUMNS);
        $stmt->execute([$tenantCode, $featureKey, $source, $status, $endsAt?->format('Y-m-d H:i:s'), $offerCode, $reference, $grantedBy !== null ? mb_substr($grantedBy, 0, 255) : null, $note]);

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $fields status, ends_at, grace_until, note, reference */
    public function update(int $id, array $fields): void
    {
        $allowed = ['status', 'ends_at', 'grace_until', 'note', 'reference', 'offer_code'];
        $sets = ['updated_at = NOW()'];
        $params = [];
        foreach ($fields as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                throw new \InvalidArgumentException('colonne inconnue : ' . $column);
            }
            $sets[] = "$column = ?";
            $params[] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value;
        }
        $params[] = $id;
        $this->pdo()->prepare('UPDATE tenant_entitlements SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    /** @return list<array<string, mixed>> droits vivants dont la fin ou la grâce est dépassée */
    public function due(\DateTimeInterface $now): array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . " FROM tenant_entitlements WHERE (status = 'active' AND ends_at IS NOT NULL AND ends_at <= ?) OR (status = 'grace' AND grace_until IS NOT NULL AND grace_until <= ?) ORDER BY id");
        $stmt->execute([$now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> droits actifs qui se terminent dans les $days prochains jours */
    public function endingWithin(\DateTimeInterface $now, int $days): array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . " FROM tenant_entitlements WHERE status = 'active' AND ends_at IS NOT NULL AND ends_at > ? AND ends_at <= ? ORDER BY id");
        $stmt->execute([$now->format('Y-m-d H:i:s'), $now->modify("+$days days")->format('Y-m-d H:i:s')]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function recordEvent(int $entitlementId, string $tenantCode, string $featureKey, string $event, ?array $payload = null): void
    {
        $this->pdo()->prepare('INSERT INTO tenant_entitlement_events (entitlement_id, tenant_code, feature_key, event, payload) VALUES (?, ?, ?, ?, ?)')
            ->execute([$entitlementId, $tenantCode, $featureKey, $event, $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null]);
    }

    public function hasEvent(int $entitlementId, string $event): bool
    {
        $stmt = $this->pdo()->prepare('SELECT 1 FROM tenant_entitlement_events WHERE entitlement_id = ? AND event = ? LIMIT 1');
        $stmt->execute([$entitlementId, $event]);

        return (bool) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> derniers événements (journal de la console) */
    public function events(?string $tenantCode, int $limit = 100): array
    {
        $sql = 'SELECT e.id, e.entitlement_id, e.tenant_code, e.feature_key, e.event, e.payload, e.created_at FROM tenant_entitlement_events e' . ($tenantCode !== null ? ' WHERE e.tenant_code = ?' : '') . ' ORDER BY e.id DESC LIMIT ' . max(1, min(500, $limit));
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($tenantCode !== null ? [$tenantCode] : []);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function pdo(): \PDO
    {
        return $this->tenants->getPdoMaster();
    }
}
