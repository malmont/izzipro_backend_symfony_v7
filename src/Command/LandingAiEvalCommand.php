<?php

namespace App\Command;

use App\Services\LandingAiService\CompositionEditApplier;
use App\Services\LandingAiService\Eval\LandingAiEvalCases;
use App\Services\LandingAiService\Eval\LandingAiEvalChecks;
use App\Services\LandingAiService\LandingAiCatalogue;
use App\Services\LandingAiService\LandingAiComposer;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiSiteContext;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:landingpage-ai:eval',
    description: 'Rejoue le jeu d\'essai de l\'assistant IA (sans HTTP ni quota, aucune écriture) et écrit un rapport'
)]
class LandingAiEvalCommand extends Command
{
    public function __construct(
        private readonly TenantConnectionManager $connectionManager,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LandingAiCatalogue $catalogue,
        private readonly LandingAiComposer $composer,
        private readonly LandingAiSiteContext $site,
        private readonly LandingAiEvalCases $cases,
        private readonly LandingAiEvalChecks $checks,
        #[Autowire('%kernel.project_dir%/var/landing-ai-eval')]
        private readonly string $reportDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Code du tenant de test (palette et médias lus sur ce site, rien n\'y est écrit)')
            ->addOption('case', 'c', InputOption::VALUE_REQUIRED, 'Un seul cas (ex. R1)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenant = (string) $input->getOption('tenant');
        if ($tenant === '') {
            $io->error('--tenant est obligatoire (tenant de test, jamais un client).');
            return Command::INVALID;
        }
        $stmt = $this->connectionManager->getPdoMaster()->prepare('SELECT dbname FROM tenants WHERE code = :code');
        $stmt->execute(['code' => $tenant]);
        $dbName = $stmt->fetchColumn();
        if (!$dbName) {
            $io->error("Tenant « $tenant » introuvable.");
            return Command::INVALID;
        }
        $this->emProvider->switchTenant($dbName, $tenant);

        $cases = $this->cases->editCases();
        if ($only = $input->getOption('case')) {
            $cases = array_values(array_filter($cases, fn ($c) => strcasecmp($c['id'], $only) === 0));
        }
        if (!$cases) {
            $io->error('Aucun cas à jouer.');
            return Command::INVALID;
        }

        $stored = $this->site->storedCompositions();
        $palette = $this->site->palette($stored);
        $io->title(sprintf('Évaluation de l\'assistant IA : %d cas, modèle %s, tenant %s', count($cases), $this->composer->editModel(), $tenant));

        $report = ['date' => date(DATE_ATOM), 'tenant' => $tenant, 'model' => $this->composer->editModel(), 'cases' => []];
        $rows = [];
        foreach ($cases as $case) {
            [$family, $preset] = $this->catalogue->preset($case['presetId']) ?? [null, null];
            if ($preset === null) {
                $report['cases'][] = ['id' => $case['id'], 'status' => 'skipped', 'reason' => "modèle {$case['presetId']} absent du catalogue"];
                $rows[] = [$case['id'], 'ignoré', '', '', '', '', "modèle {$case['presetId']} absent"];
                continue;
            }
            $componentKey = $family['componentKey'];
            $before = CompositionEditApplier::copy(json_decode(json_encode($preset['composition'], JSON_PRESERVE_ZERO_FRACTION), false));
            $allowedMedia = $this->site->allowedMedia([...$stored, $before]);
            $io->writeln(sprintf(' <info>%s</info> %s/%s : « %s »', $case['id'], $componentKey, $case['presetId'], $case['prompt']));

            try {
                $result = $this->composer->edit($componentKey, $before, $case['prompt']);
            } catch (LandingAiException $e) {
                $stats = $e->getStats()?->toArray() ?? [];
                $report['cases'][] = ['id' => $case['id'], 'status' => 'failed', 'httpStatus' => $e->getStatusCode(), 'message' => $e->getMessage(), 'errors' => $e->getErrors(), 'usage' => $stats];
                $rows[] = [$case['id'], 'échec ' . $e->getStatusCode(), $stats['attempts'] ?? '', $stats['inputTokens'] ?? '', $stats['outputTokens'] ?? '', $stats['durationMs'] ?? '', $e->getMessage()];
                continue;
            }

            $common = $this->checks->common($before, $result, $componentKey, $allowedMedia, $case['prompt']);
            $specific = ($case['check'])($before, $result->composition, $result, $palette);
            $failed = array_keys(array_filter($common, fn ($c) => !$c['ok']));
            $report['cases'][] = [
                'id' => $case['id'],
                'presetId' => $case['presetId'],
                'componentKey' => $componentKey,
                'prompt' => $case['prompt'],
                'status' => 'ok',
                'checks' => $common,
                'specific' => $specific,
                'summary' => $result->summary,
                'warnings' => $result->warnings,
                'operations' => $result->operations,
                'composition' => $result->composition,
                'usage' => $result->stats->toArray(),
            ];
            $rows[] = [
                $case['id'],
                ($failed ? 'V ✗ ' . implode(',', $failed) : 'V1-V6 ✓') . ' / ' . ($specific['ok'] === null ? 'propre ?' : ($specific['ok'] ? 'propre ✓' : 'propre ✗')),
                $result->stats->attempts,
                $result->stats->inputTokens . ' (+' . $result->stats->cacheReadTokens . ' cache)',
                $result->stats->outputTokens,
                $result->stats->durationMs,
                mb_substr($specific['detail'], 0, 90),
            ];
        }

        $report['summary'] = $this->summarize($report['cases']);
        if (!is_dir($this->reportDir)) {
            mkdir($this->reportDir, 0775, true);
        }
        $file = sprintf('%s/eval-%s-%s.json', $this->reportDir, $tenant, date('Ymd-His'));
        file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        $io->table(['Cas', 'Vérifications', 'Essais', 'Jetons entrée', 'Sortie', 'ms', 'Détail propre au cas'], $rows);
        $s = $report['summary'];
        $lines = [['Cas réussis (composition obtenue)' => sprintf('%d / %d', $s['succeeded'], $s['played'])]];
        foreach ($s['checks'] as $check => $count) {
            $lines[] = [$check => sprintf('%d / %d', $count, $s['succeeded'])];
        }
        $lines[] = ['Vérifications propres réussies' => sprintf('%d / %d mesurables', $s['specificOk'], $s['specificMeasured'])];
        $lines[] = ['Essais moyens' => $s['avgAttempts']];
        $lines[] = ['Jetons moyens (entrée / cache lu / sortie)' => sprintf('%s / %s / %s', $s['avgInputTokens'], $s['avgCacheReadTokens'], $s['avgOutputTokens'])];
        $lines[] = ['Durée moyenne' => $s['avgDurationMs'] . ' ms'];
        $lines[] = ['Rapport' => $file];
        $io->definitionList(...$lines);

        return Command::SUCCESS;
    }

    private function summarize(array $cases): array
    {
        $played = array_values(array_filter($cases, fn ($c) => $c['status'] !== 'skipped'));
        $ok = array_values(array_filter($played, fn ($c) => $c['status'] === 'ok'));
        $checks = [];
        foreach (['V1', 'V2', 'V3', 'V4', 'V5', 'V6'] as $v) {
            $checks[$v] = count(array_filter($ok, fn ($c) => $c['checks'][$v]['ok']));
        }
        $measured = array_filter($ok, fn ($c) => $c['specific']['ok'] !== null);
        $avg = fn (string $key) => $played ? (int) round(array_sum(array_map(fn ($c) => $c['usage'][$key] ?? 0, $played)) / count($played)) : 0;

        return [
            'played' => count($played),
            'succeeded' => count($ok),
            'checks' => $checks,
            'specificMeasured' => count($measured),
            'specificOk' => count(array_filter($measured, fn ($c) => $c['specific']['ok'])),
            'avgAttempts' => $played ? round(array_sum(array_map(fn ($c) => $c['usage']['attempts'] ?? 0, $played)) / count($played), 2) : 0,
            'avgInputTokens' => $avg('inputTokens'),
            'avgCacheReadTokens' => $avg('cacheReadTokens'),
            'avgOutputTokens' => $avg('outputTokens'),
            'avgDurationMs' => $avg('durationMs'),
        ];
    }
}
