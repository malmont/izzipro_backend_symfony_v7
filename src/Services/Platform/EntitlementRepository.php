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

    /**
     * Date telle que la table la garde : en UTC, sans décalage (lot DROITS-UTC, 13/11/2026). Les colonnes de date de
     * `tenant_entitlements` n'ont pas de fuseau et toutes les comparaisons se font en UTC. Avant, une date reçue avec un décalage
     * (« 2026-12-01T00:00:00-05:00 » envoyée à la route d'administration) était écrite telle qu'affichée, 00:00:00 au lieu de
     * 05:00:00 : le droit s'éteignait cinq heures trop tôt. Seul endroit qui écrit ou compare une date de cette table.
     */
    private static function utc(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @return array<string, mixed> la ligne créée */
    public function insert(string $tenantCode, string $featureKey, string $source, ?\DateTimeInterface $endsAt, ?string $grantedBy, ?string $note, ?string $offerCode, ?string $reference, string $status = 'active'): array
    {
        $stmt = $this->pdo()->prepare('INSERT INTO tenant_entitlements (tenant_code, feature_key, source, status, starts_at, ends_at, offer_code, reference, granted_by, note) VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?) RETURNING ' . self::COLUMNS);
        $stmt->execute([$tenantCode, $featureKey, $source, $status, $endsAt !== null ? self::utc($endsAt) : null, $offerCode, $reference, $grantedBy !== null ? mb_substr($grantedBy, 0, 255) : null, $note]);

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
            $params[] = $value instanceof \DateTimeInterface ? self::utc($value) : $value;
        }
        $params[] = $id;
        $this->pdo()->prepare('UPDATE tenant_entitlements SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    /** @return list<array<string, mixed>> droits vivants dont la fin ou la grâce est dépassée */
    public function due(\DateTimeInterface $now): array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . " FROM tenant_entitlements WHERE (status = 'active' AND ends_at IS NOT NULL AND ends_at <= ?) OR (status = 'grace' AND grace_until IS NOT NULL AND grace_until <= ?) ORDER BY id");
        $stmt->execute([self::utc($now), self::utc($now)]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> droits actifs qui se terminent dans les $days prochains jours */
    public function endingWithin(\DateTimeInterface $now, int $days): array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . " FROM tenant_entitlements WHERE status = 'active' AND ends_at IS NOT NULL AND ends_at > ? AND ends_at <= ? ORDER BY id");
        $stmt->execute([self::utc($now), self::utc(\DateTimeImmutable::createFromInterface($now)->modify("+$days days"))]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Droits portant une référence externe (abonnement, commande) ; $statuses : filtre (vivants par défaut), [] = tous
     * @return list<array<string, mixed>>
     */
    public function byReference(string $reference, array $statuses = self::LIVE_STATUSES): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM tenant_entitlements WHERE reference = ?';
        $params = [$reference];
        if ($statuses !== []) {
            $sql .= ' AND status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';
            $params = [...$params, ...$statuses];
        }
        $stmt = $this->pdo()->prepare($sql . ' ORDER BY id');
        $stmt->execute($params);

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
