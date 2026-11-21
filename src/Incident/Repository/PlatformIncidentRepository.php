<?php

namespace App\Incident\Repository;

use App\Services\TenantConnectionManager;

/**
 * Incidents de la plateforme (lot INC-1a, 18/11/2026, `docs/incidents-et-signalements.md`) : tables `platform_incident` et
 * `platform_incident_event` de la base MAÎTRE (`ops/sql/platform_incident_master.sql`). Comme `LossPairingRepository`, ce dépôt parle à
 * la base maître par PDO ; le site courant n'est jamais changé.
 */
class PlatformIncidentRepository
{
    public const STATUSES = ['new', 'acknowledged', 'fixed', 'ignored'];
    /** Libellés de l'écran d'EasyAdmin (colonne, badge, filtre, choix) et couleur du badge ; la valeur brute ne s'affiche jamais (21/11/2026) */
    public const STATUS_LABELS = ['new' => 'nouveau', 'acknowledged' => 'pris en charge', 'fixed' => 'corrigé', 'ignored' => 'ignoré'];
    public const STATUS_BADGES = ['new' => 'bg-warning text-dark', 'acknowledged' => 'bg-info text-dark', 'fixed' => 'bg-success', 'ignored' => 'bg-secondary'];
    public const LEVELS = ['error', 'crash', 'warning'];
    /** Occurrences gardées au plus par incident */
    public const EVENTS_KEPT = 50;
    private const COLUMNS = 'id, fingerprint, tenant_code, app, app_version, screen, route, level, message, context, count, anonymous, first_seen_at, last_seen_at, status, reopened, note, external_url, fixed_in, status_changed_at, status_changed_by';

    public function __construct(private readonly TenantConnectionManager $connectionManager)
    {
    }

    private function pdo(): \PDO
    {
        return $this->connectionManager->getPdoMaster();
    }

    /**
     * Crée l'incident de cette empreinte ou l'incrémente de `$count`. Un incident corrigé qui revient redevient `new`, marqué `reopened` ;
     * un incident ignoré reste ignoré (il compte). Rend `{ id, created }`.
     *
     * @param array{fingerprint: string, tenant: ?string, app: string, appVersion: ?string, screen: ?string, route: ?string, level: string, message: string, context: ?string, anonymous: bool} $row
     *
     * @return array{id: int, created: bool}
     */
    public function upsert(array $row, int $count, string $now): array
    {
        $stmt = $this->pdo()->prepare('INSERT INTO platform_incident (fingerprint, tenant_code, app, app_version, screen, route, level, message, context, count, anonymous, first_seen_at, last_seen_at)
            VALUES (:fp, :tenant, :app, :version, :screen, :route, :level, :message, :context, :count, :anonymous, :now, :now)
            ON CONFLICT (fingerprint) DO UPDATE SET count = platform_incident.count + EXCLUDED.count, last_seen_at = EXCLUDED.last_seen_at,
                app_version = COALESCE(EXCLUDED.app_version, platform_incident.app_version), context = COALESCE(EXCLUDED.context, platform_incident.context),
                level = CASE WHEN EXCLUDED.level = \'crash\' OR platform_incident.level = \'crash\' THEN \'crash\' WHEN EXCLUDED.level = \'error\' OR platform_incident.level = \'error\' THEN \'error\' ELSE \'warning\' END,
                reopened = platform_incident.reopened OR platform_incident.status = \'fixed\',
                status = CASE WHEN platform_incident.status = \'fixed\' THEN \'new\' ELSE platform_incident.status END
            RETURNING id, (xmax = 0) AS created');
        $stmt->execute(['fp' => $row['fingerprint'], 'tenant' => $row['tenant'], 'app' => $row['app'], 'version' => $row['appVersion'], 'screen' => $row['screen'],
            'route' => $row['route'], 'level' => $row['level'], 'message' => $row['message'], 'context' => $row['context'], 'count' => $count,
            'anonymous' => $row['anonymous'] ? 't' : 'f', 'now' => $now]);
        $out = $stmt->fetch(\PDO::FETCH_ASSOC);

        return ['id' => (int) $out['id'], 'created' => (bool) $out['created']];
    }

    public function exists(string $fingerprint): bool
    {
        $stmt = $this->pdo()->prepare('SELECT 1 FROM platform_incident WHERE fingerprint = ?');
        $stmt->execute([$fingerprint]);

        return $stmt->fetchColumn() !== false;
    }

    /** Incidents créés sans jeton pour ce site depuis `$since` (plafond du jour) */
    public function anonymousCreatedSince(?string $tenant, string $since): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM platform_incident WHERE anonymous AND tenant_code IS NOT DISTINCT FROM ? AND first_seen_at >= ?');
        $stmt->execute([$tenant, $since]);

        return (int) $stmt->fetchColumn();
    }

    /** Une occurrence, puis seules les `EVENTS_KEPT` dernières de l'incident restent */
    public function addEvent(int $incidentId, string $now, ?string $appVersion, ?string $userHash, ?string $requestId, int $repeats = 1): void
    {
        $pdo = $this->pdo();
        $pdo->prepare('INSERT INTO platform_incident_event (incident_id, occurred_at, app_version, user_hash, request_id, repeats) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$incidentId, $now, $appVersion, $userHash, $requestId, $repeats]);
        $pdo->prepare('DELETE FROM platform_incident_event WHERE incident_id = ? AND id NOT IN (SELECT id FROM platform_incident_event WHERE incident_id = ? ORDER BY id DESC LIMIT ' . self::EVENTS_KEPT . ')')
            ->execute([$incidentId, $incidentId]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM platform_incident WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param array{status?: ?string, app?: ?string, tenant?: ?string, level?: ?string} $filters
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $limit, int $offset): array
    {
        $where = [];
        $params = [];
        foreach (['status' => 'status', 'app' => 'app', 'tenant' => 'tenant_code', 'level' => 'level'] as $key => $column) {
            if (($filters[$key] ?? null) !== null) {
                $where[] = "$column = ?";
                $params[] = $filters[$key];
            }
        }
        $sql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM platform_incident' . $sql);
        $count->execute($params);
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM platform_incident' . $sql . ' ORDER BY last_seen_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
        $stmt->execute($params);

        return ['items' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'total' => (int) $count->fetchColumn()];
    }

    /** @return list<array<string, mixed>> les dernières occurrences, la plus récente d'abord */
    public function events(int $incidentId, int $limit = 20): array
    {
        $stmt = $this->pdo()->prepare('SELECT occurred_at, app_version, user_hash, request_id, repeats FROM platform_incident_event WHERE incident_id = ? ORDER BY id DESC LIMIT ' . $limit);
        $stmt->execute([$incidentId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $set colonnes de `status`, `note`, `external_url`, `fixed_in`, `status_changed_at`, `status_changed_by` */
    public function update(int $id, array $set): void
    {
        $allowed = ['status', 'note', 'external_url', 'fixed_in', 'status_changed_at', 'status_changed_by', 'reopened'];
        $cols = array_values(array_intersect(array_keys($set), $allowed));
        if ($cols === []) {
            return;
        }
        $sql = 'UPDATE platform_incident SET ' . implode(', ', array_map(fn (string $c): string => "$c = ?", $cols)) . ' WHERE id = ?';
        $values = array_map(fn (string $c): mixed => is_bool($set[$c]) ? ($set[$c] ? 't' : 'f') : $set[$c], $cols);
        $this->pdo()->prepare($sql)->execute([...$values, $id]);
    }

    /**
     * Pour le courriel du jour : incidents vus pour la première fois depuis `$since`, et incidents revenus après correction.
     *
     * @return array{new: list<array<string, mixed>>, reopened: list<array<string, mixed>>}
     */
    public function digest(string $since): array
    {
        $new = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM platform_incident WHERE first_seen_at >= ? AND status <> \'ignored\' ORDER BY count DESC, id LIMIT 100');
        $new->execute([$since]);
        $back = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM platform_incident WHERE reopened AND status = \'new\' AND last_seen_at >= ? AND first_seen_at < ? ORDER BY count DESC, id LIMIT 100');
        $back->execute([$since, $since]);

        return ['new' => $new->fetchAll(\PDO::FETCH_ASSOC), 'reopened' => $back->fetchAll(\PDO::FETCH_ASSOC)];
    }

    /** Purge : occurrences au-delà de 90 jours, incidents corrigés ou ignorés depuis plus d'un an. Rend `[occurrences, incidents]` */
    public function purge(string $eventsBefore, string $incidentsBefore): array
    {
        $events = $this->pdo()->prepare('DELETE FROM platform_incident_event WHERE occurred_at < ?');
        $events->execute([$eventsBefore]);
        $incidents = $this->pdo()->prepare('DELETE FROM platform_incident WHERE status IN (\'fixed\', \'ignored\') AND COALESCE(status_changed_at, last_seen_at) < ? AND last_seen_at < ?');
        $incidents->execute([$incidentsBefore, $incidentsBefore]);

        return [$events->rowCount(), $incidents->rowCount()];
    }
}
