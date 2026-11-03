<?php

namespace App\Services\TechMetricsService;

use App\Repository\TechMetricRepository;
use App\Services\TenantConnectionManager;

/**
 * Modèle de la page « Tableau de bord technique » (T2, 03/11/2026) : classement des sites par ressource, détail d'un site avec ses
 * courbes, mesures de la plateforme avec l'indicateur bon / moyen / mauvais (seuils de `tech_threshold`), routes les plus lourdes.
 * Tout est lu dans `tech_metric_sample` (base maître) : aucune requête vers les bases des sites, aucune valeur de configuration.
 * Deux codes de site qui partagent une base sont fusionnés sous le premier code (les compteurs de requêtes portent le code appelé,
 * les tailles le premier code : la fusion rend la page cohérente).
 */
class TechDashboardService
{
    public const WINDOWS = ['24h' => ['label' => '24 h', 'hours' => 24], '7j' => ['label' => '7 jours', 'hours' => 168], '30j' => ['label' => '30 jours', 'hours' => 720]];
    public const DEFAULT_WINDOW = '24h';
    /** Colonnes du classement : agrégation sur la fenêtre (somme, maximum) ou dernier relevé */
    public const RESOURCES = [
        'requests' => ['label' => 'Requêtes', 'kind' => 'sum', 'format' => 'int'],
        'request_ms' => ['label' => 'Temps PHP', 'kind' => 'sum', 'format' => 'duration_ms'],
        'request_peak_mb' => ['label' => 'Pic mémoire', 'kind' => 'max', 'format' => 'mb'],
        'errors_5xx' => ['label' => 'Erreurs 5xx', 'kind' => 'sum', 'format' => 'int'],
        'jobs' => ['label' => 'Tâches de fond', 'kind' => 'sum', 'format' => 'int'],
        'emails' => ['label' => 'Courriels', 'kind' => 'sum', 'format' => 'int'],
        'ai_calls' => ['label' => 'Appels IA', 'kind' => 'sum', 'format' => 'int'],
        'ai_tokens' => ['label' => 'Jetons IA', 'kind' => 'sum', 'format' => 'int', 'metrics' => ['ai_tokens_in', 'ai_tokens_out']],
        'db_size_bytes' => ['label' => 'Base', 'kind' => 'latest', 'format' => 'bytes'],
        'files_bytes' => ['label' => 'Fichiers', 'kind' => 'latest', 'format' => 'bytes'],
    ];
    public const DEFAULT_SORT = 'requests';
    /** Timers quotidiens : jugés avec les seuils de la sauvegarde ; les autres avec ceux du timer de réconciliation */
    private const DAILY_TIMERS = ['backup-daily.timer', 'form-submissions-purge.timer'];
    /** Fraîcheur de la collecte (minutes) : bon, moyen, au-delà mauvais */
    private const FRESH_GOOD_MIN = 10;
    private const FRESH_WARN_MIN = 20;

    public function __construct(
        private readonly TechMetricRepository $repository,
        private readonly TenantConnectionManager $tenants,
    ) {
    }

    /** @return array<string, mixed> modèle complet de la page */
    public function build(string $window, ?string $site, string $sort, ?\DateTimeImmutable $now = null): array
    {
        $now = $now ?? TechCounters::now();
        $window = isset(self::WINDOWS[$window]) ? $window : self::DEFAULT_WINDOW;
        $sort = isset(self::RESOURCES[$sort]) ? $sort : self::DEFAULT_SORT;
        $from = $now->setTime((int) $now->format('G'), 0)->modify('-' . self::WINDOWS[$window]['hours'] . ' hours');
        $to = $now->setTime((int) $now->format('G'), 0)->modify('+1 hour');
        $groups = $this->siteGroups();
        $thresholds = [];
        foreach ($this->repository->thresholds() as $t) {
            $thresholds[$t['metric']] = $t;
        }
        $ranking = $this->ranking($groups, $from, $to, $sort);
        $detailSite = $site !== null && isset($groups[$site]) ? $site : ($ranking[0]['site'] ?? null);

        return [
            'window' => $window,
            'windows' => self::WINDOWS,
            'from' => $from,
            'to' => $now,
            'sort' => $sort,
            'resources' => self::RESOURCES,
            'ranking' => $ranking,
            'detail' => $detailSite === null ? null : $this->siteDetail($detailSite, $groups[$detailSite], $from, $to),
            'platform' => $this->platform($thresholds, $from, $to, $now),
            'routes' => $this->routes($thresholds, $from, $to),
            'thresholds' => array_values($thresholds),
            'freshness' => $this->freshness($now),
        ];
    }

    /**
     * Sites regroupés par base : [étiquette => {label, codes, names}] ; l'étiquette est le premier code (comme le collecteur).
     * @return array<string, array{label: string, codes: list<string>, names: list<string>}>
     */
    public function siteGroups(): array
    {
        $byDb = [];
        foreach ($this->tenants->getAllTenants() as $tenant) {
            $db = (string) $tenant['dbname'];
            $byDb[$db] ??= ['label' => (string) $tenant['code'], 'codes' => [], 'names' => []];
            $byDb[$db]['codes'][] = (string) $tenant['code'];
            $byDb[$db]['names'][] = (string) ($tenant['name'] ?? $tenant['code']);
        }
        $groups = [];
        foreach ($byDb as $g) {
            $groups[$g['label']] = $g;
        }
        ksort($groups);

        return $groups;
    }

    private function ranking(array $groups, \DateTimeImmutable $from, \DateTimeImmutable $to, string $sort): array
    {
        $codeToLabel = [];
        foreach ($groups as $label => $g) {
            foreach ($g['codes'] as $code) {
                $codeToLabel[$code] = $label;
            }
        }
        $rows = [];
        foreach ($groups as $label => $g) {
            $rows[$label] = ['site' => $label, 'names' => $g['names'], 'codes' => $g['codes'], 'values' => array_fill_keys(array_keys(self::RESOURCES), 0.0), 'known' => true];
        }
        foreach (self::RESOURCES as $key => $resource) {
            foreach ($resource['metrics'] ?? [$key] as $metric) {
                $entries = $resource['kind'] === 'latest' ? $this->repository->latestPerSite($metric) : $this->repository->ranking($metric, $from, $to);
                foreach ($entries as $entry) {
                    $code = $entry['site'];
                    if ($code === '' || $code === TechCounters::PLATFORM_SITE) {
                        continue;
                    }
                    $label = $codeToLabel[$code] ?? $code;
                    $rows[$label] ??= ['site' => $label, 'names' => [$label], 'codes' => [$code], 'values' => array_fill_keys(array_keys(self::RESOURCES), 0.0), 'known' => false];
                    $rows[$label]['values'][$key] = $resource['kind'] === 'max' ? max($rows[$label]['values'][$key], $entry['value']) : $rows[$label]['values'][$key] + $entry['value'];
                }
            }
        }
        $max = array_fill_keys(array_keys(self::RESOURCES), 0.0);
        foreach ($rows as $row) {
            foreach ($row['values'] as $key => $value) {
                $max[$key] = max($max[$key], $value);
            }
        }
        foreach ($rows as &$row) {
            $row['display'] = [];
            $row['share'] = [];
            foreach ($row['values'] as $key => $value) {
                $row['display'][$key] = self::format($value, self::RESOURCES[$key]['format']);
                $row['share'][$key] = $max[$key] > 0 ? (int) round(100 * $value / $max[$key]) : 0;
            }
        }
        unset($row);
        usort($rows, fn ($a, $b) => $b['values'][$sort] <=> $a['values'][$sort] ?: strcmp($a['site'], $b['site']));

        return $rows;
    }

    private function siteDetail(string $label, array $group, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $codes = $group['codes'];
        $hours = $this->hourLabels($from, $to);
        $charts = [];
        $charts[] = $this->hourChart('requests', 'Requêtes par heure', '', $hours, $this->repository->seriesGrouped('requests', $codes, TechMetricRepository::PERIOD_HOUR, $from, $to), 'Requêtes');
        $charts[] = $this->hourChart('request_ms', 'Temps PHP cumulé par heure', 's', $hours, $this->repository->seriesGrouped('request_ms', $codes, TechMetricRepository::PERIOD_HOUR, $from, $to), 'Secondes', 0.001);
        $charts[] = $this->hourChart('request_peak_mb', 'Pic mémoire par heure', 'Mo', $hours, $this->repository->seriesGrouped('request_peak_mb', $codes, TechMetricRepository::PERIOD_HOUR, $from, $to), 'Mo');
        $errors = array_merge(
            array_map(fn ($r) => $r + ['subject' => '4xx'], $this->repository->seriesGrouped('errors_4xx', $codes, TechMetricRepository::PERIOD_HOUR, $from, $to)),
            array_map(fn ($r) => $r + ['subject' => '5xx'], array_map(fn ($r) => ['period_start' => $r['period_start'], 'value' => $r['value']], $this->repository->seriesGrouped('errors_5xx', $codes, TechMetricRepository::PERIOD_HOUR, $from, $to))),
        );
        $charts[] = $this->hourChart('errors', 'Erreurs par heure', '', $hours, array_map(fn ($r) => ['period_start' => $r['period_start'], 'subject' => $r['subject'], 'value' => $r['value']], $errors), null);
        $charts[] = $this->hourChart('jobs', 'Tâches de fond par file', '', $hours, $this->repository->seriesGrouped('jobs', $codes, TechMetricRepository::PERIOD_HOUR, $from, $to), null);
        $charts[] = $this->hourChart('ai_calls', 'Appels d\'IA par fournisseur', '', $hours, $this->repository->seriesGrouped('ai_calls', $codes, TechMetricRepository::PERIOD_HOUR, $from, $to), null);
        $tokens = array_merge(
            array_map(fn ($r) => ['period_start' => $r['period_start'], 'subject' => 'entrée', 'value' => $r['value']], $this->aggregateSubjects($this->repository->seriesGrouped('ai_tokens_in', $codes, TechMetricRepository::PERIOD_HOUR, $from, $to))),
            array_map(fn ($r) => ['period_start' => $r['period_start'], 'subject' => 'sortie', 'value' => $r['value']], $this->aggregateSubjects($this->repository->seriesGrouped('ai_tokens_out', $codes, TechMetricRepository::PERIOD_HOUR, $from, $to))),
        );
        $charts[] = $this->hourChart('ai_tokens', 'Jetons d\'IA par heure', '', $hours, $tokens, null);
        $charts[] = $this->pointChart('db_size_bytes', 'Taille de la base', 'Mo', $this->repository->seriesGrouped('db_size_bytes', $codes, TechMetricRepository::PERIOD_POINT, $from, $to), 1 / 1048576);
        $charts[] = $this->pointChart('files_bytes', 'Fichiers référencés', 'Mo', $this->repository->seriesGrouped('files_bytes', $codes, TechMetricRepository::PERIOD_POINT, $from, $to), 1 / 1048576);

        return ['site' => $label, 'names' => $group['names'], 'codes' => $codes, 'charts' => array_values(array_filter($charts))];
    }

    private function platform(array $thresholds, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $now): array
    {
        $indicators = [];
        $fpmPct = $this->repository->latest('fpm_active_pct');
        $fpmActive = $this->repository->latest('fpm_active');
        $fpmQueue = $this->repository->latest('fpm_queue');
        $fpmTotal = $this->repository->latest('fpm_total');
        if ($fpmActive !== null) {
            $detail = $fpmActive['detail'] ?? [];
            $max = (int) ($detail['max_children'] ?? 0);
            $indicators[] = $this->indicator('PHP-FPM : processus occupés', $fpmPct ? $fpmPct['value'] : null, '%', $this->status($thresholds, 'fpm_active_pct', $fpmPct['value'] ?? null),
                sprintf('%d actif(s) sur %s (total %d, max atteint %d fois, requêtes lentes %d)', (int) $fpmActive['value'], $max ?: '?', (int) ($fpmTotal['value'] ?? 0), (int) ($detail['max children reached'] ?? 0), (int) ($detail['slow requests'] ?? 0)), $fpmActive['period_start']);
        }
        if ($fpmQueue !== null) {
            $indicators[] = $this->indicator('PHP-FPM : file d\'attente', $fpmQueue['value'], 'req', $this->status($thresholds, 'fpm_queue', $fpmQueue['value']), 'Requêtes qui attendent un processus libre', $fpmQueue['period_start']);
        }
        foreach ($this->repository->latestBySubject('disk_used_pct') as $disk) {
            $d = $disk['detail'] ?? [];
            $indicators[] = $this->indicator('Disque ' . $disk['subject'], $disk['value'], '%', $this->status($thresholds, 'disk_used_pct', $disk['value']), sprintf('%s utilisés sur %s', self::format((float) ($d['used'] ?? 0), 'bytes'), self::format((float) ($d['total'] ?? 0), 'bytes')), $disk['period_start']);
        }
        $backupAge = $this->repository->latest('backup_age_s');
        if ($backupAge !== null) {
            $bytes = $this->repository->latest('backup_bytes');
            $dbs = $this->repository->latest('backup_db_count');
            $indicators[] = $this->indicator('Sauvegarde quotidienne', $backupAge['value'] < 0 ? null : $backupAge['value'], 'age', $this->status($thresholds, 'backup_age_s', $backupAge['value']),
                $backupAge['value'] < 0 ? 'Aucune sauvegarde réussie trouvée dans le journal' : sprintf('dernier jeu : %s, %d base(s)', self::format((float) ($bytes['value'] ?? 0), 'bytes'), (int) ($dbs['value'] ?? 0)), $backupAge['period_start']);
        }
        foreach ($this->repository->latestBySubject('timer_last_age_s') as $timer) {
            $d = $timer['detail'] ?? [];
            $metric = in_array($timer['subject'], self::DAILY_TIMERS, true) ? 'backup_age_s' : 'timer_last_age_s';
            $status = $this->status($thresholds, $metric, $timer['value']);
            if (($d['result'] ?? null) !== null && $d['result'] !== 'success') {
                $status = 'bad';
            }
            $indicators[] = $this->indicator('Timer ' . preg_replace('/\.timer$/', '', $timer['subject']), $timer['value'] < 0 ? null : $timer['value'], 'age', $status,
                $timer['value'] < 0 ? 'Jamais déclenché' : sprintf('résultat %s, prochain passage %s', $d['result'] ?? '?', $d['next'] ?? '?'), $timer['period_start']);
        }
        $docker = $this->repository->latest('docker_reclaimable_bytes');
        if ($docker !== null) {
            $indicators[] = $this->indicator('Docker : espace récupérable', $docker['value'], 'bytes', null, 'Images inutilisées et cache de construction (docker system prune)', $docker['period_start']);
        }
        $storage = $this->repository->latest('storage_bytes');
        if ($storage !== null) {
            $indicators[] = $this->indicator('Stockage var/storage', $storage['value'], 'bytes', null, sprintf('%d fichier(s), relevé quotidien', (int) (($storage['detail'] ?? [])['files'] ?? 0)), $storage['period_start']);
        }

        $hours = $this->hourLabels($from, $to);
        $charts = array_values(array_filter([
            $this->hourChart('platform_requests', 'Requêtes par heure, tous les sites', '', $hours, $this->aggregateSubjects($this->repository->seriesGrouped('requests', null, TechMetricRepository::PERIOD_HOUR, $from, $to)), 'Requêtes'),
            $this->pointChart('fpm_active_pct', 'PHP-FPM : processus occupés', '%', $this->repository->seriesGrouped('fpm_active_pct', [''], TechMetricRepository::PERIOD_POINT, $from, $to)),
            $this->pointChart('fpm_queue', 'PHP-FPM : file d\'attente', 'req', $this->repository->seriesGrouped('fpm_queue', [''], TechMetricRepository::PERIOD_POINT, $from, $to)),
            $this->pointChart('disk_used_pct', 'Disque utilisé', '%', $this->repository->seriesGrouped('disk_used_pct', [''], TechMetricRepository::PERIOD_POINT, $from, $to)),
        ]));

        return ['indicators' => $indicators, 'charts' => $charts];
    }

    private function routes(array $thresholds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = [];
        foreach ($this->repository->routeRanking($from, $to) as $r) {
            $rows[] = $r + [
                'mb_status' => $this->status($thresholds, 'route_peak_mb_max', $r['mb']),
                'ms_status' => $this->status($thresholds, 'route_ms_max', $r['ms']),
                'ms_display' => self::format($r['ms'], 'duration_ms'),
            ];
        }

        return $rows;
    }

    private function freshness(\DateTimeImmutable $now): array
    {
        $last = $this->repository->lastMeasuredAt();
        if ($last === null) {
            return ['last' => null, 'age' => null, 'status' => 'bad', 'label' => 'Aucune collecte enregistrée'];
        }
        $age = max(0, $now->getTimestamp() - $last->getTimestamp());
        $status = $age <= self::FRESH_GOOD_MIN * 60 ? 'good' : ($age <= self::FRESH_WARN_MIN * 60 ? 'warn' : 'bad');

        return ['last' => $last, 'age' => $age, 'status' => $status, 'label' => 'Dernière collecte il y a ' . self::format((float) $age, 'age')];
    }

    /** Indicateur bon / moyen / mauvais d'une valeur selon les seuils (direction max : bon jusqu'à good, moyen jusqu'à warn) ; -1 = jamais → mauvais */
    public function status(array $thresholds, string $metric, ?float $value): ?string
    {
        if ($value === null || !isset($thresholds[$metric])) {
            return null;
        }
        if ($value < 0) {
            return 'bad';
        }
        $t = $thresholds[$metric];
        if (($t['direction'] ?? 'max') === 'min') {
            return $value >= $t['good'] ? 'good' : ($value >= $t['warn'] ? 'warn' : 'bad');
        }

        return $value <= $t['good'] ? 'good' : ($value <= $t['warn'] ? 'warn' : 'bad');
    }

    private function indicator(string $label, ?float $value, string $format, ?string $status, string $hint, ?string $at): array
    {
        return ['label' => $label, 'value' => $value, 'display' => $value === null ? '—' : self::format($value, $format), 'status' => $status, 'hint' => $hint, 'at' => $at];
    }

    /** @return list<string> débuts d'heure de la fenêtre, 'Y-m-d H:i:s' */
    private function hourLabels(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $labels = [];
        for ($h = $from; $h < $to; $h = $h->modify('+1 hour')) {
            $labels[] = $h->format('Y-m-d H:i:s');
        }

        return $labels;
    }

    /** Courbe horaire : une série par sujet (ou une seule, nommée) alignée sur toutes les heures de la fenêtre ; null si rien */
    private function hourChart(string $id, string $title, string $unit, array $hours, array $rows, ?string $singleLabel, float $scale = 1.0): ?array
    {
        if ($rows === []) {
            return null;
        }
        $bySubject = [];
        foreach ($rows as $r) {
            $subject = $singleLabel ?? ($r['subject'] === '' ? 'total' : $r['subject']);
            $bySubject[$subject][$r['period_start']] = ($bySubject[$subject][$r['period_start']] ?? 0) + $r['value'] * $scale;
        }
        ksort($bySubject);
        $datasets = [];
        foreach ($bySubject as $subject => $values) {
            $datasets[] = ['label' => (string) $subject, 'data' => array_map(fn ($h) => isset($values[$h]) ? round($values[$h], 3) : 0, $hours)];
        }

        return ['id' => $id, 'title' => $title, 'unit' => $unit, 'labels' => array_map(fn ($h) => substr($h, 5, 11), $hours), 'datasets' => $datasets];
    }

    /** Courbe des relevés ponctuels (toutes les 5 min) : étiquettes = horodatages réels */
    private function pointChart(string $id, string $title, string $unit, array $rows, float $scale = 1.0): ?array
    {
        if ($rows === []) {
            return null;
        }
        $bySubject = [];
        foreach ($rows as $r) {
            $bySubject[$r['subject'] === '' ? $title : $r['subject']][$r['period_start']] = $r['value'] * $scale;
        }
        $labels = array_values(array_unique(array_column($rows, 'period_start')));
        sort($labels);
        $datasets = [];
        foreach ($bySubject as $subject => $values) {
            $datasets[] = ['label' => (string) $subject, 'data' => array_map(fn ($l) => isset($values[$l]) ? round($values[$l], 2) : null, $labels)];
        }

        return ['id' => $id, 'title' => $title, 'unit' => $unit, 'labels' => array_map(fn ($l) => substr($l, 5, 11), $labels), 'datasets' => $datasets];
    }

    /** Fusionne les sujets d'une série (tous confondus) */
    private function aggregateSubjects(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r['period_start']] = ($out[$r['period_start']] ?? 0) + $r['value'];
        }
        $result = [];
        foreach ($out as $start => $value) {
            $result[] = ['period_start' => $start, 'subject' => '', 'value' => $value];
        }

        return $result;
    }

    public static function format(float $value, string $format): string
    {
        switch ($format) {
            case 'bytes':
                foreach (['octets', 'Ko', 'Mo', 'Go', 'To'] as $i => $unit) {
                    if ($value < 1024 || $i === 4) {
                        return ($i === 0 ? (string) (int) $value : number_format($value, 1, ',', ' ')) . ' ' . $unit;
                    }
                    $value /= 1024;
                }
                // no break
            case 'mb':
                return number_format($value, 0, ',', ' ') . ' Mo';
            case 'duration_ms':
                return $value >= 60000 ? number_format($value / 60000, 1, ',', ' ') . ' min' : ($value >= 1000 ? number_format($value / 1000, 1, ',', ' ') . ' s' : number_format($value, 0, ',', ' ') . ' ms');
            case 'age':
                $s = (int) $value;
                if ($s < 60) {
                    return $s . ' s';
                }
                if ($s < 3600) {
                    return intdiv($s, 60) . ' min';
                }
                if ($s < 172800) {
                    return intdiv($s, 3600) . ' h ' . str_pad((string) intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT);
                }

                return intdiv($s, 86400) . ' j';
            case '%':
                return number_format($value, 1, ',', ' ') . ' %';
            case 'req':
                return number_format($value, 0, ',', ' ');
            default:
                return number_format($value, 0, ',', ' ');
        }
    }
}
