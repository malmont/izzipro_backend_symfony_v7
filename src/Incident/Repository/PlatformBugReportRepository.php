<?php

namespace App\Incident\Repository;

use App\Services\TenantConnectionManager;

/**
 * Signalements de la plateforme (lot INC-3, 18/11/2026, `docs/incidents-et-signalements.md`) : table `platform_bug_report` de la base
 * MAÎTRE (`ops/sql/platform_bug_report_master.sql`), par PDO comme `PlatformIncidentRepository` ; le site courant n'est jamais changé.
 */
class PlatformBugReportRepository
{
    public const STATUSES = ['received', 'in_progress', 'fixed', 'wont_fix'];
    /** Libellés de l'écran d'EasyAdmin (colonne, badge, filtre, choix) et couleur du badge ; la valeur brute ne s'affiche jamais (21/11/2026) */
    public const STATUS_LABELS = ['received' => 'reçu', 'in_progress' => 'en cours', 'fixed' => 'corrigé', 'wont_fix' => 'ne sera pas corrigé'];
    public const STATUS_BADGES = ['received' => 'bg-warning text-dark', 'in_progress' => 'bg-info text-dark', 'fixed' => 'bg-success', 'wont_fix' => 'bg-secondary'];
    public const OPEN = ['received', 'in_progress'];
    public const SETTING_SCREENSHOT_DAYS = 'bug_report_screenshot_days';
    private const COLUMNS = 'id, tenant_code, app, app_version, screen, description, context, screenshot_path, screenshot_deleted_at, reporter_kind, reporter_user_id, reporter_hash, status, incident_id, answer, note, support_ticket_id, created_at, updated_at, answered_at, status_changed_by';

    public function __construct(private readonly TenantConnectionManager $connectionManager)
    {
    }

    private function pdo(): \PDO
    {
        return $this->connectionManager->getPdoMaster();
    }

    /**
     * Rend l'identifiant, ou null si le ticket a déjà un signalement ouvert (index unique partiel).
     *
     * @param array<string, mixed> $row
     */
    public function insert(array $row): ?int
    {
        $stmt = $this->pdo()->prepare('INSERT INTO platform_bug_report (tenant_code, app, app_version, screen, description, context, reporter_kind, reporter_user_id, reporter_hash, support_ticket_id, created_at, updated_at)
            VALUES (:tenant, :app, :version, :screen, :description, :context, :kind, :user, :hash, :ticket, :now, :now)
            ON CONFLICT (tenant_code, support_ticket_id) WHERE support_ticket_id IS NOT NULL AND status IN (\'received\', \'in_progress\') DO NOTHING RETURNING id');
        $stmt->execute(['tenant' => $row['tenant'], 'app' => $row['app'], 'version' => $row['appVersion'], 'screen' => $row['screen'], 'description' => $row['description'],
            'context' => $row['context'], 'kind' => $row['kind'], 'user' => $row['userId'], 'hash' => $row['userHash'], 'ticket' => $row['ticketId'], 'now' => $row['now']]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function setScreenshot(int $id, ?string $path): void
    {
        $this->pdo()->prepare('UPDATE platform_bug_report SET screenshot_path = ? WHERE id = ?')->execute([$path, $id]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM platform_bug_report WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** Signalements créés depuis `$since` par ce compte de ce site, ou par tout le site (`$userId` null) */
    public function countSince(string $tenant, ?int $userId, string $since): int
    {
        $sql = 'SELECT COUNT(*) FROM platform_bug_report WHERE tenant_code = ? AND created_at >= ? AND support_ticket_id IS NULL';
        $params = [$tenant, $since];
        if ($userId !== null) {
            $sql .= ' AND reporter_user_id = ?';
            $params[] = $userId;
        }
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> « Mes signalements » : ceux de ce compte sur ce site, hors escalades, 50 derniers */
    public function mine(string $tenant, int $userId): array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM platform_bug_report WHERE tenant_code = ? AND reporter_user_id = ? AND support_ticket_id IS NULL ORDER BY id DESC LIMIT 50');
        $stmt->execute([$tenant, $userId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null le dernier signalement d'un ticket de l'Assistance de ce site */
    public function forTicket(string $tenant, int $ticketId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM platform_bug_report WHERE tenant_code = ? AND support_ticket_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$tenant, $ticketId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param array{status?: ?string, tenant?: ?string, app?: ?string} $filters
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $limit, int $offset): array
    {
        $where = [];
        $params = [];
        foreach (['status' => 'status', 'tenant' => 'tenant_code', 'app' => 'app'] as $key => $column) {
            if (($filters[$key] ?? null) !== null) {
                $where[] = "$column = ?";
                $params[] = $filters[$key];
            }
        }
        $sql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM platform_bug_report' . $sql);
        $count->execute($params);
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM platform_bug_report' . $sql . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
        $stmt->execute($params);

        return ['items' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'total' => (int) $count->fetchColumn()];
    }

    /** @param array<string, mixed> $set */
    public function update(int $id, array $set): void
    {
        $allowed = ['status', 'answer', 'note', 'incident_id', 'answered_at', 'status_changed_by', 'updated_at'];
        $cols = array_values(array_intersect(array_keys($set), $allowed));
        if ($cols === []) {
            return;
        }
        $this->pdo()->prepare('UPDATE platform_bug_report SET ' . implode(', ', array_map(fn (string $c): string => "$c = ?", $cols)) . ' WHERE id = ?')
            ->execute([...array_map(fn (string $c): mixed => $set[$c], $cols), $id]);
    }

    public function incidentExists(int $id): bool
    {
        $stmt = $this->pdo()->prepare('SELECT 1 FROM platform_incident WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return list<array<string, mixed>> signalements sans réponse ni changement d'état depuis `$before` (courriel du jour) */
    public function unanswered(string $before): array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM platform_bug_report WHERE status = \'received\' AND answered_at IS NULL AND created_at < ? ORDER BY id LIMIT 100');
        $stmt->execute([$before]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array{id: int, screenshot_path: string}> captures plus vieilles que `$before` */
    public function screenshotsBefore(string $before): array
    {
        $stmt = $this->pdo()->prepare('SELECT id, screenshot_path FROM platform_bug_report WHERE screenshot_path IS NOT NULL AND created_at < ?');
        $stmt->execute([$before]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function screenshotDeleted(int $id, string $now): void
    {
        $this->pdo()->prepare('UPDATE platform_bug_report SET screenshot_path = NULL, screenshot_deleted_at = ? WHERE id = ?')->execute([$now, $id]);
    }

    public function screenshotDays(): int
    {
        $stmt = $this->pdo()->prepare('SELECT value FROM platform_settings WHERE key = ?');
        $stmt->execute([self::SETTING_SCREENSHOT_DAYS]);
        $value = $stmt->fetchColumn();

        return is_numeric($value) && (int) $value >= 1 ? (int) $value : 180;
    }

    public function setScreenshotDays(int $days, ?string $by): void
    {
        $this->pdo()->prepare('INSERT INTO platform_settings (key, value, updated_at, updated_by) VALUES (?, ?, NOW(), ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by')
            ->execute([self::SETTING_SCREENSHOT_DAYS, (string) $days, $by]);
    }
}
