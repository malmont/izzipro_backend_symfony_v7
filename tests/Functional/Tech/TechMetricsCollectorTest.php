<?php

namespace App\Tests\Functional\Tech;

use App\Repository\TechMetricRepository;
use App\Services\TechMetricsService\FpmStatusClient;
use App\Services\TechMetricsService\TechCounters;
use App\Services\TechMetricsService\TechMetricsCollector;
use App\Services\TenantConnectionManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Collecteur des mesures techniques (tableau de bord technique, T1, 03/11/2026) : compteurs Redis vidés en table (heures révolues
 * supprimées de Redis, heure courante conservée), appels d'IA et jetons par site et fournisseur lus dans ai_usage, taille des bases et
 * des fichiers par site, pics par route depuis le journal, statut PHP-FPM, mesures de l'hôte, agrégats journaliers et purge.
 * Isolation : une mesure d'un site n'apparaît jamais sous un autre.
 */
class TechMetricsCollectorTest extends KernelTestCase
{
    private TechMetricsCollector $collector;
    private TechMetricRepository $repository;
    private TechCounters $counters;
    private \PDO $master;
    private \DateTimeImmutable $now;
    private \DateTimeImmutable $pastHour;

    protected function setUp(): void
    {
        static::bootKernel();
        $c = static::getContainer();
        $this->collector = $c->get(TechMetricsCollector::class);
        $this->repository = $c->get(TechMetricRepository::class);
        $this->counters = $c->get(TechCounters::class);
        $this->master = $c->get(TenantConnectionManager::class)->getPdoMaster();
        $this->master->exec('DELETE FROM tech_metric_sample');
        $this->now = TechCounters::now()->setTime(14, 7, 0);
        $this->pastHour = $this->now->modify('-3 hours')->setTime((int) $this->now->modify('-3 hours')->format('H'), 0);
        foreach ($this->counters->keys(TechCounters::KIND_REQUEST) as $k) {
            $this->counters->delete($k);
        }
        foreach ($this->counters->keys(TechCounters::KIND_JOB) as $k) {
            $this->counters->delete($k);
        }
    }

    public function testCountersAiSizesPeaksFpmAndHostMeasuresEndUpInTheMasterTablePerSite(): void
    {
        // Compteurs : heure révolue (2 sites) et heure courante
        $this->counters->countRequest(MV_TEST_TENANT_CODE, 120, 40, 200, $this->pastHour);
        $this->counters->countRequest(MV_TEST_TENANT_CODE, 80, 64, 500, $this->pastHour);
        $this->counters->countRequest(MV_TEST_TENANT2_CODE, 10, 20, 404, $this->pastHour);
        $this->counters->countRequest(MV_TEST_TENANT_CODE, 5, 30, 200, $this->now);
        $this->counters->countJob(MV_TEST_TENANT_CODE, 'email', $this->pastHour);
        $this->counters->countJob(MV_TEST_TENANT_CODE, 'email', $this->pastHour);
        $this->counters->countJob(MV_TEST_TENANT_CODE, 'async', $this->pastHour);

        // IA : deux appels Anthropic et un OpenAI sur le premier site, à l'heure révolue ; une transcription Mémoires Vivantes
        $tenant = $this->tenantPdo(MV_TEST_TENANT_DB);
        $tenant->exec('DELETE FROM ai_usage; DELETE FROM mv_ai_usage');
        $at = $this->pastHour->modify('+12 minutes')->format('Y-m-d H:i:s');
        $tenant->exec("INSERT INTO ai_usage (tenant, created_at, mode, component_key, status, credits, model, input_tokens, output_tokens) VALUES
            ('" . MV_TEST_TENANT_CODE . "', '$at', 'page', 'hero', 'done', 1, 'claude-sonnet-5-5', 1000, 200),
            ('" . MV_TEST_TENANT_CODE . "', '$at', 'page', 'hero', 'done', 1, 'claude-opus-5-5', 3000, 400),
            ('" . MV_TEST_TENANT_CODE . "', '$at', 'edit', 'hero', 'done', 1, 'gpt-5', 500, 50)");
        $userId = (int) $tenant->query('SELECT MIN(id) FROM "user"')->fetchColumn();
        $tenant->exec("INSERT INTO mv_ai_usage (user_id, kind, created_at) VALUES ($userId, 'transcription', '$at')");

        // Journal des pics (suffixe -test en environnement de test)
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        $log = $projectDir . '/var/log/request-peaks-test-' . $this->now->format('Y-m') . '.log';
        @unlink($log);
        file_put_contents($log, json_encode(['at' => $this->pastHour->modify('+5 minutes')->format(\DateTimeInterface::ATOM), 'route' => 'api_memoires_book_generate_pdfs', 'method' => 'POST', 'status' => 200, 'site' => MV_TEST_TENANT_CODE, 'peakMb' => 199, 'durationMs' => 15714]) . "\n"
            . json_encode(['at' => $this->pastHour->modify('+9 minutes')->format(\DateTimeInterface::ATOM), 'route' => 'api_memoires_book_generate_pdfs', 'method' => 'POST', 'status' => 200, 'site' => MV_TEST_TENANT_CODE, 'peakMb' => 120, 'durationMs' => 9000]) . "\n");

        // Agrégat journalier et purge : une heure d'avant-hier, une heure vieille de 100 jours, un jour vieux de 14 mois
        $dayBefore = $this->now->modify('-2 days')->setTime(10, 0);
        $this->repository->upsert('requests', MV_TEST_TENANT_CODE, '', TechMetricRepository::PERIOD_HOUR, $dayBefore, 7);
        $this->repository->upsert('requests', MV_TEST_TENANT_CODE, '', TechMetricRepository::PERIOD_HOUR, $dayBefore->modify('+1 hour'), 5);
        $this->repository->upsert('request_peak_mb', MV_TEST_TENANT_CODE, '', TechMetricRepository::PERIOD_HOUR, $dayBefore, 90);
        $this->repository->upsert('request_peak_mb', MV_TEST_TENANT_CODE, '', TechMetricRepository::PERIOD_HOUR, $dayBefore->modify('+1 hour'), 70);
        $this->repository->upsert('requests', MV_TEST_TENANT_CODE, '', TechMetricRepository::PERIOD_HOUR, $this->now->modify('-100 days'), 1);
        $this->repository->upsert('requests', MV_TEST_TENANT_CODE, '', TechMetricRepository::PERIOD_DAY, $this->now->modify('-14 months')->setTime(0, 0), 1);

        $host = [
            'disk' => [['mount' => '/', 'total' => 200_000_000_000, 'used' => 31_000_000_000]],
            'docker' => ['reclaimable' => 5_000_000_000, 'images' => 2_000_000_000, 'build_cache' => 3_000_000_000],
            'timers' => [['unit' => 'pos-terminal-reconcile.timer', 'last_trigger' => $this->now->getTimestamp() - 120, 'result' => 'success', 'next' => 'bientôt']],
            'backup' => ['last_success' => $this->now->getTimestamp() - 7200, 'bytes' => 150_000_000, 'files' => 12, 'databases' => 11],
        ];

        $summary = $this->collector->collect($this->now, true, $host);
        $this->assertSame([], array_filter($summary, 'is_string'), 'aucune étape en erreur : ' . json_encode($summary));

        // Compteurs → table, par site ; heure révolue supprimée de Redis, heure courante conservée
        $this->assertSame(2.0, $this->value('requests', MV_TEST_TENANT_CODE, '', $this->pastHour));
        $this->assertSame(200.0, $this->value('request_ms', MV_TEST_TENANT_CODE, '', $this->pastHour));
        $this->assertSame(64.0, $this->value('request_peak_mb', MV_TEST_TENANT_CODE, '', $this->pastHour));
        $this->assertSame(1.0, $this->value('errors_5xx', MV_TEST_TENANT_CODE, '', $this->pastHour));
        $this->assertSame(1.0, $this->value('requests', MV_TEST_TENANT2_CODE, '', $this->pastHour));
        $this->assertSame(1.0, $this->value('errors_4xx', MV_TEST_TENANT2_CODE, '', $this->pastHour));
        $this->assertNull($this->value('errors_5xx', MV_TEST_TENANT2_CODE, '', $this->pastHour), 'isolation : l\'erreur 5xx du premier site n\'est pas au second');
        $this->assertSame(1.0, $this->value('requests', MV_TEST_TENANT_CODE, '', $this->now->setTime((int) $this->now->format('H'), 0)));
        $this->assertSame(2.0, $this->value('jobs', MV_TEST_TENANT_CODE, 'email', $this->pastHour));
        $this->assertSame(2.0, $this->value('emails', MV_TEST_TENANT_CODE, '', $this->pastHour));
        $this->assertSame(1.0, $this->value('jobs', MV_TEST_TENANT_CODE, 'async', $this->pastHour));
        $this->assertSame([], $this->counters->readHour(TechCounters::KIND_REQUEST, MV_TEST_TENANT_CODE, $this->pastHour), 'heure révolue effacée de Redis');
        $this->assertSame([], $this->counters->readHour(TechCounters::KIND_JOB, MV_TEST_TENANT_CODE, $this->pastHour));
        $this->assertNotSame([], $this->counters->readHour(TechCounters::KIND_REQUEST, MV_TEST_TENANT_CODE, $this->now), 'heure courante conservée dans Redis');

        // IA par fournisseur, jetons exacts, isolation
        $this->assertSame(2.0, $this->value('ai_calls', MV_TEST_TENANT_CODE, 'anthropic', $this->pastHour));
        $this->assertSame(4000.0, $this->value('ai_tokens_in', MV_TEST_TENANT_CODE, 'anthropic', $this->pastHour));
        $this->assertSame(600.0, $this->value('ai_tokens_out', MV_TEST_TENANT_CODE, 'anthropic', $this->pastHour));
        $this->assertSame(1.0, $this->value('ai_calls', MV_TEST_TENANT_CODE, 'openai', $this->pastHour));
        $this->assertSame(500.0, $this->value('ai_tokens_in', MV_TEST_TENANT_CODE, 'openai', $this->pastHour));
        $this->assertSame(1.0, $this->value('ai_mv_calls', MV_TEST_TENANT_CODE, 'transcription', $this->pastHour));
        $this->assertNull($this->value('ai_calls', MV_TEST_TENANT2_CODE, 'anthropic', $this->pastHour), 'isolation IA');

        // Tailles : base et fichiers par site, stockage de la plateforme
        $point = TechMetricsCollector::point($this->now);
        $this->assertGreaterThan(1_000_000, $this->value('db_size_bytes', MV_TEST_TENANT_CODE, '', $point, TechMetricRepository::PERIOD_POINT));
        $this->assertGreaterThan(1_000_000, $this->value('db_size_bytes', MV_TEST_TENANT2_CODE, '', $point, TechMetricRepository::PERIOD_POINT));
        $this->assertNotNull($this->value('files_bytes', MV_TEST_TENANT_CODE, '', $point, TechMetricRepository::PERIOD_POINT));
        $this->assertNotNull($this->value('storage_bytes', '', '', $point, TechMetricRepository::PERIOD_POINT));

        // Pics par route
        $this->assertSame(2.0, $this->value('route_peak_count', '', 'api_memoires_book_generate_pdfs', $this->pastHour));
        $this->assertSame(199.0, $this->value('route_peak_mb_max', '', 'api_memoires_book_generate_pdfs', $this->pastHour));
        $this->assertSame(15714.0, $this->value('route_ms_max', '', 'api_memoires_book_generate_pdfs', $this->pastHour));

        // PHP-FPM (le pool tourne dans le conteneur des tests)
        $status = static::getContainer()->get(FpmStatusClient::class)->status();
        $this->assertIsArray($status, 'statut PHP-FPM lisible en FastCGI');
        $this->assertArrayHasKey('active processes', $status);
        $this->assertArrayHasKey('listen queue', $status);
        $this->assertNotNull($this->value('fpm_active', '', '', $point, TechMetricRepository::PERIOD_POINT));
        $this->assertNotNull($this->value('fpm_queue', '', '', $point, TechMetricRepository::PERIOD_POINT));

        // Hôte
        $this->assertSame(15.5, $this->value('disk_used_pct', '', '/', $point, TechMetricRepository::PERIOD_POINT));
        $this->assertSame(5_000_000_000.0, $this->value('docker_reclaimable_bytes', '', '', $point, TechMetricRepository::PERIOD_POINT));
        $this->assertSame(120.0, $this->value('timer_last_age_s', '', 'pos-terminal-reconcile.timer', $point, TechMetricRepository::PERIOD_POINT));
        $this->assertSame(7200.0, $this->value('backup_age_s', '', '', $point, TechMetricRepository::PERIOD_POINT));
        $this->assertSame(11.0, $this->value('backup_db_count', '', '', $point, TechMetricRepository::PERIOD_POINT));

        // Agrégat journalier (somme des compteurs, maximum des pics) et purge
        $this->assertSame(12.0, $this->value('requests', MV_TEST_TENANT_CODE, '', $dayBefore->setTime(0, 0), TechMetricRepository::PERIOD_DAY));
        $this->assertSame(90.0, $this->value('request_peak_mb', MV_TEST_TENANT_CODE, '', $dayBefore->setTime(0, 0), TechMetricRepository::PERIOD_DAY));
        $this->assertNull($this->value('requests', MV_TEST_TENANT_CODE, '', $this->now->modify('-100 days')), 'heure de 100 jours purgée');
        $this->assertNull($this->value('requests', MV_TEST_TENANT_CODE, '', $this->now->modify('-14 months')->setTime(0, 0), TechMetricRepository::PERIOD_DAY), 'jour de 14 mois purgé');

        // Classement des sites sur 24 h et seuils par défaut
        $ranking = $this->repository->ranking('requests', $this->now->modify('-24 hours'), $this->now->modify('+1 hour'));
        $this->assertSame([MV_TEST_TENANT_CODE, MV_TEST_TENANT2_CODE], array_column($ranking, 'site'));
        $this->assertSame(3.0, $ranking[0]['value']);
        $this->assertContains('disk_used_pct', array_column($this->repository->thresholds(), 'metric'));

        // Second passage : idempotent (mêmes valeurs, aucune ligne en double)
        $count = (int) $this->master->query('SELECT COUNT(*) FROM tech_metric_sample')->fetchColumn();
        $this->collector->collect($this->now, true, $host);
        $this->assertSame($count, (int) $this->master->query('SELECT COUNT(*) FROM tech_metric_sample')->fetchColumn());
        $this->assertSame(2.0, $this->value('ai_calls', MV_TEST_TENANT_CODE, 'anthropic', $this->pastHour));
        @unlink($log);
    }

    public function testTheCollectCommandRunsAndReportsEachStep(): void
    {
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel);
        $application->setAutoExit(false);
        $tester = new \Symfony\Component\Console\Tester\ApplicationTester($application);
        $hostFile = tempnam(sys_get_temp_dir(), 'tech');
        file_put_contents($hostFile, json_encode(['disk' => [['mount' => '/', 'total' => 100, 'used' => 50]]]));
        $this->assertSame(0, $tester->run(['command' => 'app:tech-metrics:collect', '--host-json' => $hostFile]), $tester->getDisplay());
        $display = $tester->getDisplay();
        foreach (['counters', 'ai', 'db_size', 'peaks', 'fpm', 'host', 'rollup', 'purge'] as $step) {
            $this->assertStringContainsString($step, $display);
        }
        $this->assertStringNotContainsString('erreur', $display);
        $this->assertSame(50.0, $this->repository->latest('disk_used_pct', '', '/')['value']);
        @unlink($hostFile);
    }

    private function value(string $metric, string $site, string $subject, \DateTimeImmutable $periodStart, string $period = TechMetricRepository::PERIOD_HOUR): ?float
    {
        $stmt = $this->master->prepare('SELECT value FROM tech_metric_sample WHERE metric = ? AND site = ? AND subject = ? AND period = ? AND period_start = ?');
        $stmt->execute([$metric, $site, $subject, $period, $periodStart->format('Y-m-d H:i:s')]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (float) $v;
    }

    private function tenantPdo(string $db): \PDO
    {
        $p = static::getContainer()->get(TenantConnectionManager::class)->getTenantParams();

        return new \PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $p['host'], (int) ($p['port'] ?? 5432), $db), $p['user'], $p['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }
}
