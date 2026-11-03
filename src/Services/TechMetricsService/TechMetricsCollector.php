<?php

namespace App\Services\TechMetricsService;

use App\Repository\TechMetricRepository;
use App\Services\TenantConnectionManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Collecteur des mesures techniques (tableau de bord technique, T1, 03/11/2026), lancé toutes les 5 minutes par le timer
 * `tech-metrics.timer` via `app:tech-metrics:collect`, jamais pendant une requête web. Chaque étape est indépendante et avale ses
 * erreurs (journalisées) : une base de site injoignable n'empêche pas les autres mesures.
 *
 * Par site : compteurs Redis des requêtes et des tâches vidés en table (heures passées supprimées de Redis, heure courante conservée),
 * appels d'IA et jetons lus dans `ai_usage` et `mv_ai_usage` de chaque base, taille de la base, taille des fichiers référencés en base
 * (une fois par jour). Plateforme : pics par route (journal request-peaks relu en entier, agrégation idempotente), statut PHP-FPM,
 * mesures de l'hôte transmises par le script ops/tech-metrics-host.sh (disque, Docker, timers, sauvegarde). Puis agrégats journaliers
 * des trois derniers jours et purge (90 jours fin, 13 mois journalier).
 */
class TechMetricsCollector
{
    /** Fenêtre relue dans les tables d'IA à chaque passage (les lignes sont réécrites à l'identique : idempotent) */
    private const AI_LOOKBACK_HOURS = 48;
    /** Heure UTC du relevé quotidien des fichiers */
    private const FILES_HOUR = 3;

    public function __construct(
        private readonly TechCounters $counters,
        private readonly TechMetricRepository $repository,
        private readonly TenantConnectionManager $tenants,
        private readonly FpmStatusClient $fpm,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
    }

    /**
     * @param array<string, mixed>|null $host mesures de l'hôte (JSON du script hôte), null si absentes
     * @return array<string, int|string> résumé par étape (nombre de lignes écrites ou message d'erreur)
     */
    public function collect(?\DateTimeImmutable $now = null, bool $withFiles = false, ?array $host = null): array
    {
        $now = $now ?? TechCounters::now();
        $summary = [];
        $steps = [
            'counters' => fn () => $this->flushCounters($now),
            'ai' => fn () => $this->collectAi($now),
            'db_size' => fn () => $this->collectDbSizes($now),
            'files' => fn () => ($withFiles || $this->filesDueToday($now)) ? $this->collectFiles($now) : 0,
            'peaks' => fn () => $this->collectPeaks($now),
            'fpm' => fn () => $this->collectFpm($now),
            'host' => fn () => $host === null ? 0 : $this->ingestHost($host, $now),
            'rollup' => fn () => $this->repository->rollupDays($now->setTime(0, 0)->modify('-3 days'), $now->setTime(0, 0), $now),
            'purge' => fn () => array_sum($this->repository->purge($now)),
        ];
        foreach ($steps as $name => $step) {
            try {
                $summary[$name] = $step();
            } catch (\Throwable $e) {
                $summary[$name] = 'erreur : ' . $e->getMessage();
                $this->logger->error(sprintf('[Tech] collecte %s : %s', $name, $e->getMessage()));
            }
        }

        return $summary;
    }

    /** Compteurs Redis → table ; les heures révolues sont supprimées de Redis après écriture */
    public function flushCounters(\DateTimeImmutable $now): int
    {
        $currentHour = $now->format('YmdH');
        $written = 0;
        $done = [];
        foreach ([TechCounters::KIND_REQUEST, TechCounters::KIND_JOB] as $kind) {
            foreach ($this->counters->keys($kind) as $key) {
                $parsed = TechCounters::parseKey($key);
                if ($parsed === null) {
                    continue;
                }
                [, $site, $hour] = $parsed;
                $fields = $this->counters->readHour($kind, $site, $hour);
                if ($fields === []) {
                    continue;
                }
                if ($kind === TechCounters::KIND_REQUEST) {
                    $this->repository->upsert('requests', $site, '', TechMetricRepository::PERIOD_HOUR, $hour, (float) ($fields['n'] ?? 0), null, $now);
                    $this->repository->upsert('request_ms', $site, '', TechMetricRepository::PERIOD_HOUR, $hour, (float) ($fields['ms'] ?? 0), null, $now);
                    $this->repository->upsert('request_peak_mb', $site, '', TechMetricRepository::PERIOD_HOUR, $hour, (float) ($fields['peak'] ?? 0), null, $now);
                    $written += 3;
                    foreach (['e4' => 'errors_4xx', 'e5' => 'errors_5xx'] as $field => $metric) {
                        if (isset($fields[$field])) {
                            $this->repository->upsert($metric, $site, '', TechMetricRepository::PERIOD_HOUR, $hour, (float) $fields[$field], null, $now);
                            ++$written;
                        }
                    }
                } else {
                    foreach ($fields as $transport => $n) {
                        $this->repository->upsert('jobs', $site, (string) $transport, TechMetricRepository::PERIOD_HOUR, $hour, (float) $n, null, $now);
                        ++$written;
                        if ($transport === 'email') {
                            $this->repository->upsert('emails', $site, '', TechMetricRepository::PERIOD_HOUR, $hour, (float) $n, null, $now);
                            ++$written;
                        }
                    }
                }
                if ($hour->format('YmdH') < $currentHour) {
                    $done[] = $key;
                }
            }
        }
        $this->counters->delete(...$done);

        return $written;
    }

    /** Appels d'IA par site, heure et fournisseur (ai_usage : jetons ; mv_ai_usage : nombre par type) */
    public function collectAi(\DateTimeImmutable $now): int
    {
        $since = $now->modify('-' . self::AI_LOOKBACK_HOURS . ' hours')->setTime((int) $now->format('H'), 0)->format('Y-m-d H:i:s');
        $written = 0;
        foreach ($this->sites() as $site) {
            $pdo = $this->tenantPdo($site['dbname']);
            if ($pdo === null) {
                continue;
            }
            try {
                $stmt = $pdo->prepare("SELECT date_trunc('hour', created_at) AS h, COALESCE(model, '') AS model, COUNT(*) AS n, COALESCE(SUM(input_tokens), 0) AS tin, COALESCE(SUM(output_tokens), 0) AS tout FROM ai_usage WHERE created_at >= :since GROUP BY 1, 2");
                $stmt->execute(['since' => $since]);
                $byHour = [];
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                    $provider = self::provider($row['model']);
                    $slot = &$byHour[$row['h']][$provider];
                    $slot = ['n' => ($slot['n'] ?? 0) + (int) $row['n'], 'tin' => ($slot['tin'] ?? 0) + (int) $row['tin'], 'tout' => ($slot['tout'] ?? 0) + (int) $row['tout'], 'models' => array_values(array_unique(array_merge($slot['models'] ?? [], [$row['model']])))];
                    unset($slot);
                }
                foreach ($byHour as $h => $providers) {
                    $hour = new \DateTimeImmutable($h, new \DateTimeZone('UTC'));
                    foreach ($providers as $provider => $v) {
                        $detail = ['models' => $v['models'], 'codes' => $site['codes']];
                        $this->repository->upsert('ai_calls', $site['code'], $provider, TechMetricRepository::PERIOD_HOUR, $hour, $v['n'], $detail, $now);
                        $this->repository->upsert('ai_tokens_in', $site['code'], $provider, TechMetricRepository::PERIOD_HOUR, $hour, $v['tin'], null, $now);
                        $this->repository->upsert('ai_tokens_out', $site['code'], $provider, TechMetricRepository::PERIOD_HOUR, $hour, $v['tout'], null, $now);
                        $written += 3;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf('[Tech] ai_usage %s : %s', $site['code'], $e->getMessage()));
            }
            try {
                $stmt = $pdo->prepare("SELECT date_trunc('hour', created_at) AS h, kind, COUNT(*) AS n FROM mv_ai_usage WHERE created_at >= :since GROUP BY 1, 2");
                $stmt->execute(['since' => $since]);
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                    $this->repository->upsert('ai_mv_calls', $site['code'], (string) $row['kind'], TechMetricRepository::PERIOD_HOUR, new \DateTimeImmutable($row['h'], new \DateTimeZone('UTC')), (float) $row['n'], null, $now);
                    ++$written;
                }
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf('[Tech] mv_ai_usage %s : %s', $site['code'], $e->getMessage()));
            }
        }

        return $written;
    }

    public static function provider(string $model): string
    {
        $m = strtolower($model);
        if (str_starts_with($m, 'claude')) {
            return 'anthropic';
        }
        if (str_starts_with($m, 'gpt') || str_starts_with($m, 'o1') || str_starts_with($m, 'o3') || str_starts_with($m, 'o4') || str_starts_with($m, 'whisper') || str_starts_with($m, 'text-embedding')) {
            return 'openai';
        }

        return 'autre';
    }

    public function collectDbSizes(\DateTimeImmutable $now): int
    {
        $written = 0;
        $pdo = $this->tenants->getPdoMaster();
        $point = self::point($now);
        foreach ($this->sites() as $site) {
            try {
                $stmt = $pdo->prepare('SELECT pg_database_size(:db)');
                $stmt->execute(['db' => $site['dbname']]);
                $size = $stmt->fetchColumn();
                if ($size === false || $size === null) {
                    continue;
                }
                $this->repository->upsert('db_size_bytes', $site['code'], '', TechMetricRepository::PERIOD_POINT, $point, (float) $size, ['dbname' => $site['dbname'], 'codes' => $site['codes']], $now);
                ++$written;
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf('[Tech] taille de base %s : %s', $site['code'], $e->getMessage()));
            }
        }

        return $written;
    }

    /** Taille des fichiers référencés dans la base de chaque site (colonnes file_size), et total brut du stockage de la plateforme */
    public function collectFiles(\DateTimeImmutable $now): int
    {
        $written = 0;
        $point = self::point($now);
        foreach ($this->sites() as $site) {
            $pdo = $this->tenantPdo($site['dbname']);
            if ($pdo === null) {
                continue;
            }
            $total = 0;
            $detail = ['codes' => $site['codes']];
            foreach (['shared_media' => 'medias', 'mv_chapter_photo' => 'photos_memoires', 'esg_document' => 'documents_esg', 'esg_diag_report' => 'rapports_esg'] as $table => $label) {
                try {
                    $bytes = (int) $pdo->query("SELECT COALESCE(SUM(file_size), 0) FROM $table")->fetchColumn();
                    $detail[$label] = $bytes;
                    $total += $bytes;
                } catch (\Throwable) {
                    // table absente de cette base : ignorée
                }
            }
            $this->repository->upsert('files_bytes', $site['code'], '', TechMetricRepository::PERIOD_POINT, $point, $total, $detail, $now);
            ++$written;
        }
        $storage = $this->projectDir . '/var/storage';
        if (is_dir($storage)) {
            $bytes = 0;
            $files = 0;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($storage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD) as $file) {
                if ($file->isFile()) {
                    $bytes += $file->getSize();
                    ++$files;
                }
            }
            $this->repository->upsert('storage_bytes', '', '', TechMetricRepository::PERIOD_POINT, $point, $bytes, ['files' => $files, 'path' => 'var/storage'], $now);
            ++$written;
        }

        return $written;
    }

    private function filesDueToday(\DateTimeImmutable $now): bool
    {
        if ((int) $now->format('G') < self::FILES_HOUR) {
            return false;
        }
        $latest = $this->repository->latest('storage_bytes');

        return $latest === null || substr($latest['period_start'], 0, 10) < $now->format('Y-m-d');
    }

    /** Journal request-peaks (mois courant et précédent) agrégé par route et par heure : nombre, pic mémoire max, durée max */
    public function collectPeaks(\DateTimeImmutable $now): int
    {
        $suffix = $this->environment === 'test' ? '-test' : '';
        $byRoute = [];
        foreach ([$now->format('Y-m'), $now->modify('first day of last month')->format('Y-m')] as $month) {
            $file = $this->projectDir . '/var/log/request-peaks' . $suffix . '-' . $month . '.log';
            if (!is_file($file)) {
                continue;
            }
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $entry = json_decode($line, true);
                if (!is_array($entry) || empty($entry['at']) || empty($entry['route'])) {
                    continue;
                }
                try {
                    $hour = (new \DateTimeImmutable($entry['at']))->setTimezone(new \DateTimeZone('UTC'))->setTime((int) (new \DateTimeImmutable($entry['at']))->setTimezone(new \DateTimeZone('UTC'))->format('H'), 0);
                } catch (\Throwable) {
                    continue;
                }
                $route = (string) $entry['route'];
                $slot = &$byRoute[$route][$hour->format('Y-m-d H:i:s')];
                $slot = ['n' => ($slot['n'] ?? 0) + 1, 'mb' => max($slot['mb'] ?? 0, (int) ($entry['peakMb'] ?? 0)), 'ms' => max($slot['ms'] ?? 0, (int) ($entry['durationMs'] ?? 0))];
                unset($slot);
            }
        }
        $written = 0;
        foreach ($byRoute as $route => $hours) {
            foreach ($hours as $h => $v) {
                $hour = new \DateTimeImmutable($h, new \DateTimeZone('UTC'));
                $this->repository->upsert('route_peak_count', '', $route, TechMetricRepository::PERIOD_HOUR, $hour, $v['n'], null, $now);
                $this->repository->upsert('route_peak_mb_max', '', $route, TechMetricRepository::PERIOD_HOUR, $hour, $v['mb'], null, $now);
                $this->repository->upsert('route_ms_max', '', $route, TechMetricRepository::PERIOD_HOUR, $hour, $v['ms'], null, $now);
                $written += 3;
            }
        }

        return $written;
    }

    public function collectFpm(\DateTimeImmutable $now): int
    {
        $status = $this->fpm->status();
        if ($status === null) {
            $this->logger->warning('[Tech] statut PHP-FPM illisible');

            return 0;
        }
        $point = self::point($now);
        $values = [
            'fpm_active' => (float) ($status['active processes'] ?? 0),
            'fpm_idle' => (float) ($status['idle processes'] ?? 0),
            'fpm_total' => (float) ($status['total processes'] ?? 0),
            'fpm_queue' => (float) ($status['listen queue'] ?? 0),
            'fpm_max_children_reached' => (float) ($status['max children reached'] ?? 0),
            'fpm_slow_requests' => (float) ($status['slow requests'] ?? 0),
        ];
        if (!empty($status['max_children'])) {
            $values['fpm_active_pct'] = round(100 * $values['fpm_active'] / (int) $status['max_children'], 1);
        }
        $detail = array_intersect_key($status, array_flip(['pool', 'process manager', 'start since', 'accepted conn', 'max listen queue', 'max active processes', 'max children reached', 'slow requests', 'max_children']));
        foreach ($values as $metric => $value) {
            $this->repository->upsert($metric, '', '', TechMetricRepository::PERIOD_POINT, $point, $value, $metric === 'fpm_active' ? $detail : null, $now);
        }

        return count($values);
    }

    /**
     * Mesures de l'hôte : {disk: [{mount, total, used}], docker: {reclaimable, images, build_cache}, timers: [{unit, last_trigger, result, next}],
     * backup: {last_success, bytes, databases, files}}, horodatages en secondes Unix.
     */
    public function ingestHost(array $host, \DateTimeImmutable $now): int
    {
        $point = self::point($now);
        $written = 0;
        foreach ($host['disk'] ?? [] as $disk) {
            if (empty($disk['mount']) || empty($disk['total'])) {
                continue;
            }
            $pct = round(100 * (float) ($disk['used'] ?? 0) / (float) $disk['total'], 1);
            $this->repository->upsert('disk_used_pct', '', (string) $disk['mount'], TechMetricRepository::PERIOD_POINT, $point, $pct, ['total' => (int) $disk['total'], 'used' => (int) ($disk['used'] ?? 0)], $now);
            ++$written;
        }
        if (isset($host['docker']['reclaimable'])) {
            $this->repository->upsert('docker_reclaimable_bytes', '', '', TechMetricRepository::PERIOD_POINT, $point, (float) $host['docker']['reclaimable'], $host['docker'], $now);
            ++$written;
        }
        foreach ($host['timers'] ?? [] as $timer) {
            if (empty($timer['unit'])) {
                continue;
            }
            $last = (int) ($timer['last_trigger'] ?? 0);
            $age = $last > 0 ? max(0, $now->getTimestamp() - $last) : -1;
            $this->repository->upsert('timer_last_age_s', '', (string) $timer['unit'], TechMetricRepository::PERIOD_POINT, $point, $age, ['result' => $timer['result'] ?? null, 'next' => $timer['next'] ?? null, 'last_trigger' => $last ?: null], $now);
            ++$written;
        }
        if (isset($host['backup'])) {
            $b = $host['backup'];
            $last = (int) ($b['last_success'] ?? 0);
            $this->repository->upsert('backup_age_s', '', '', TechMetricRepository::PERIOD_POINT, $point, $last > 0 ? max(0, $now->getTimestamp() - $last) : -1, ['last_success' => $last ?: null, 'files' => $b['files'] ?? null], $now);
            $this->repository->upsert('backup_bytes', '', '', TechMetricRepository::PERIOD_POINT, $point, (float) ($b['bytes'] ?? 0), null, $now);
            $this->repository->upsert('backup_db_count', '', '', TechMetricRepository::PERIOD_POINT, $point, (float) ($b['databases'] ?? 0), null, $now);
            $written += 3;
        }

        return $written;
    }

    /** Horodatage d'un relevé ponctuel : minute ramenée au multiple de 5 */
    public static function point(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->setTime((int) $now->format('G'), (int) $now->format('i') - ((int) $now->format('i') % 5), 0);
    }

    /**
     * Sites regroupés par base : deux codes qui partagent une base sont une seule ligne (premier code = étiquette, tous dans `codes`).
     * @return list<array{code: string, dbname: string, codes: list<string>}>
     */
    public function sites(): array
    {
        $byDb = [];
        foreach ($this->tenants->getAllTenants() as $tenant) {
            $db = (string) $tenant['dbname'];
            if (!isset($byDb[$db])) {
                $byDb[$db] = ['code' => (string) $tenant['code'], 'dbname' => $db, 'codes' => []];
            }
            $byDb[$db]['codes'][] = (string) $tenant['code'];
        }

        return array_values($byDb);
    }

    private function tenantPdo(string $dbname): ?\PDO
    {
        $p = $this->tenants->getTenantParams();
        try {
            return new \PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $p['host'] ?? 'localhost', (int) ($p['port'] ?? 5432), $dbname), (string) ($p['user'] ?? ''), (string) ($p['password'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5]);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('[Tech] base %s injoignable : %s', $dbname, $e->getMessage()));

            return null;
        }
    }
}
