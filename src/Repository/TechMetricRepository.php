<?php

namespace App\Repository;

use App\Services\TenantConnectionManager;

/**
 * Mesures techniques de la plateforme et des sites, dans la base maître (tables `tech_metric_sample` et `tech_threshold`,
 * créées par scripts/sql/tech_metrics_master.sql ; tableau de bord technique, T1, 03/11/2026). Accès PDO maître, comme la table
 * `tenants` : ces mesures couvrent tous les sites et n'appartiennent à aucun.
 *
 * Une mesure = (metric, site, subject, period, period_start) → value + detail JSON. `site` vide = plateforme ; `subject` précise
 * la mesure (route, file Messenger, fournisseur d'IA, point de montage, unité systemd). `period` : `hour` (compteurs horaires),
 * `point` (relevé daté, toutes les 5 min), `day` (agrégat journalier calculé par le collecteur).
 */
class TechMetricRepository
{
    public const PERIOD_HOUR = 'hour';
    public const PERIOD_POINT = 'point';
    public const PERIOD_DAY = 'day';
    /** Jours conservés pour les périodes `hour` et `point` */
    public const KEEP_DAYS = 90;
    /** Mois conservés pour les agrégats `day` */
    public const KEEP_DAY_MONTHS = 13;
    /** Mesures additionnées dans l'agrégat journalier ; les autres y sont au maximum */
    public const SUM_METRICS = ['requests', 'request_ms', 'errors_4xx', 'errors_5xx', 'jobs', 'emails', 'ai_calls', 'ai_tokens_in', 'ai_tokens_out', 'ai_mv_calls', 'route_peak_count'];

    public function __construct(private readonly TenantConnectionManager $tenants)
    {
    }

    private function pdo(): \PDO
    {
        return $this->tenants->getPdoMaster();
    }

    public function upsert(string $metric, string $site, string $subject, string $period, \DateTimeInterface $periodStart, float $value, ?array $detail = null, ?\DateTimeInterface $measuredAt = null): void
    {
        $stmt = $this->pdo()->prepare('INSERT INTO tech_metric_sample (metric, site, subject, period, period_start, value, detail, measured_at) VALUES (:metric, :site, :subject, :period, :start, :value, :detail, :measured)
            ON CONFLICT (metric, site, subject, period, period_start) DO UPDATE SET value = EXCLUDED.value, detail = EXCLUDED.detail, measured_at = EXCLUDED.measured_at');
        $stmt->execute([
            'metric' => $metric, 'site' => $site, 'subject' => $subject, 'period' => $period,
            'start' => $periodStart->format('Y-m-d H:i:s'), 'value' => number_format($value, 3, '.', ''),
            'detail' => $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'measured' => ($measuredAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
    }

    /** Agrégats journaliers des jours [from, to) : somme des compteurs, maximum des autres mesures ; idempotent */
    public function rollupDays(\DateTimeInterface $from, \DateTimeInterface $to, ?\DateTimeInterface $measuredAt = null): int
    {
        $pdo = $this->pdo();
        $in = implode(',', array_map(fn ($m) => $pdo->quote($m), self::SUM_METRICS));
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s'), 'measured' => ($measuredAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')];
        $count = 0;
        foreach (['SUM' => "metric IN ($in)", 'MAX' => "metric NOT IN ($in)"] as $fn => $where) {
            $stmt = $pdo->prepare("INSERT INTO tech_metric_sample (metric, site, subject, period, period_start, value, detail, measured_at)
                SELECT metric, site, subject, 'day', date_trunc('day', period_start), $fn(value), NULL, :measured
                FROM tech_metric_sample WHERE period IN ('hour', 'point') AND period_start >= :from AND period_start < :to AND $where
                GROUP BY metric, site, subject, date_trunc('day', period_start)
                ON CONFLICT (metric, site, subject, period, period_start) DO UPDATE SET value = EXCLUDED.value, measured_at = EXCLUDED.measured_at");
            $stmt->execute($params);
            $count += $stmt->rowCount();
        }

        return $count;
    }

    /** @return array{hour_point: int, day: int} lignes supprimées */
    public function purge(\DateTimeInterface $now): array
    {
        $pdo = $this->pdo();
        $fine = $pdo->prepare("DELETE FROM tech_metric_sample WHERE period IN ('hour', 'point') AND period_start < :limit");
        $fine->execute(['limit' => \DateTimeImmutable::createFromInterface($now)->modify('-' . self::KEEP_DAYS . ' days')->format('Y-m-d H:i:s')]);
        $day = $pdo->prepare("DELETE FROM tech_metric_sample WHERE period = 'day' AND period_start < :limit");
        $day->execute(['limit' => \DateTimeImmutable::createFromInterface($now)->modify('-' . self::KEEP_DAY_MONTHS . ' months')->format('Y-m-d H:i:s')]);

        return ['hour_point' => $fine->rowCount(), 'day' => $day->rowCount()];
    }

    /** @return list<array{period_start: string, value: float, detail: ?array}> */
    public function series(string $metric, string $site, string $subject, string $period, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $stmt = $this->pdo()->prepare('SELECT period_start, value, detail FROM tech_metric_sample WHERE metric = :metric AND site = :site AND subject = :subject AND period = :period AND period_start >= :from AND period_start < :to ORDER BY period_start');
        $stmt->execute(['metric' => $metric, 'site' => $site, 'subject' => $subject, 'period' => $period, 'from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')]);

        return array_map(fn (array $r) => ['period_start' => $r['period_start'], 'value' => (float) $r['value'], 'detail' => $r['detail'] === null ? null : json_decode($r['detail'], true)], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /** Classement des sites sur une fenêtre : somme (compteurs) ou maximum (autres) de la mesure, tous sujets confondus */
    public function ranking(string $metric, \DateTimeInterface $from, \DateTimeInterface $to, string $period = self::PERIOD_HOUR): array
    {
        $fn = in_array($metric, self::SUM_METRICS, true) ? 'SUM' : 'MAX';
        $stmt = $this->pdo()->prepare("SELECT site, $fn(value) AS value FROM tech_metric_sample WHERE metric = :metric AND period = :period AND period_start >= :from AND period_start < :to GROUP BY site ORDER BY value DESC, site");
        $stmt->execute(['metric' => $metric, 'period' => $period, 'from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')]);

        return array_map(fn (array $r) => ['site' => $r['site'], 'value' => (float) $r['value']], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /** Dernier relevé d'une mesure ponctuelle */
    public function latest(string $metric, string $site = '', string $subject = ''): ?array
    {
        $stmt = $this->pdo()->prepare("SELECT period_start, value, detail, measured_at FROM tech_metric_sample WHERE metric = :metric AND site = :site AND subject = :subject AND period = 'point' ORDER BY period_start DESC LIMIT 1");
        $stmt->execute(['metric' => $metric, 'site' => $site, 'subject' => $subject]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? ['period_start' => $row['period_start'], 'value' => (float) $row['value'], 'detail' => $row['detail'] === null ? null : json_decode($row['detail'], true), 'measured_at' => $row['measured_at']] : null;
    }

    public function count(string $metric, ?string $period = null): int
    {
        $sql = 'SELECT COUNT(*) FROM tech_metric_sample WHERE metric = :metric' . ($period ? ' AND period = :period' : '');
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($period ? ['metric' => $metric, 'period' => $period] : ['metric' => $metric]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array{metric: string, label: string, unit: string, direction: string, good: float, warn: float}> */
    public function thresholds(): array
    {
        $rows = $this->pdo()->query('SELECT metric, label, unit, direction, good, warn FROM tech_threshold ORDER BY metric')->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_map(fn (array $r) => ['metric' => $r['metric'], 'label' => $r['label'], 'unit' => $r['unit'], 'direction' => $r['direction'], 'good' => (float) $r['good'], 'warn' => (float) $r['warn']], $rows);
    }

    public function saveThreshold(string $metric, float $good, float $warn): void
    {
        $stmt = $this->pdo()->prepare('UPDATE tech_threshold SET good = :good, warn = :warn, updated_at = :now WHERE metric = :metric');
        $stmt->execute(['good' => number_format($good, 3, '.', ''), 'warn' => number_format($warn, 3, '.', ''), 'now' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), 'metric' => $metric]);
    }
}
