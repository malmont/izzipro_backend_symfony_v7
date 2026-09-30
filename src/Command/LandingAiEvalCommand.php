<?php

namespace App\Command;

use App\Services\LandingAiService\CompositionEditApplier;
use App\Services\LandingAiService\Eval\LandingAiEvalCases;
use App\Services\LandingAiService\Eval\LandingAiEvalChecks;
use App\Services\LandingAiService\LandingAiCatalogue;
use App\Services\LandingAiService\LandingAiComposer;
use App\Services\LandingAiService\LandingAiDataSources;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiSiteContext;
use App\Services\LandingAiService\LandingAiTuning;
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
    private const MODES = ['edit', 'create', 'page'];
    /**
     * Prix par million de jetons (USD, API Anthropic, relevés le 30/09/2026) : entrée, sortie, lecture du cache.
     * Écriture du cache : 1,25 fois l'entrée (5 minutes), 2 fois (1 heure). À mettre à jour avec les tarifs.
     */
    private const PRICES = [
        'claude-sonnet-5' => [2.0, 10.0, 0.20],
        'claude-sonnet-5-5' => [2.0, 10.0, 0.20],
        'claude-opus-5-5' => [4.0, 20.0, 0.20],
        'claude-opus-5' => [5.0, 25.0, 0.50],
        'claude-fable-5-1' => [10.0, 50.0, 0.25],
    ];
    private const MODE_TITLES = ['edit' => 'Retouche (edit)', 'create' => 'Création (create)', 'page' => 'Page (page, images)', 'review' => 'Relecture visuelle (edit + captures)'];

    public function __construct(
        private readonly TenantConnectionManager $connectionManager,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LandingAiCatalogue $catalogue,
        private readonly LandingAiComposer $composer,
        private readonly LandingAiSiteContext $site,
        private readonly LandingAiDataSources $data,
        private readonly LandingAiEvalCases $cases,
        private readonly LandingAiEvalChecks $checks,
        private readonly LandingAiTuning $tuning,
        #[Autowire('%kernel.project_dir%/var/landing-ai-eval')]
        private readonly string $reportDir,
        #[Autowire('%kernel.project_dir%/src/Services/LandingAiService/Eval/fixtures')]
        private readonly string $fixturesDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Code du tenant de test (palette, médias et données lus sur ce site, rien n\'y est écrit)')
            ->addOption('mode', 'm', InputOption::VALUE_REQUIRED, 'edit, create, page ou all', 'all')
            ->addOption('case', 'c', InputOption::VALUE_REQUIRED, 'Un seul cas (ex. R1, C3)');
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

        $mode = (string) $input->getOption('mode');
        $modes = $mode === 'all' ? self::MODES : array_intersect(self::MODES, [$mode]);
        $only = $input->getOption('case');
        $filter = fn (array $cases) => array_values(array_filter($cases, fn ($c) => !$only || strcasecmp($c['id'], $only) === 0));
        $edit = in_array('edit', $modes, true) ? $filter($this->cases->editCases()) : [];
        $create = in_array('create', $modes, true) ? $filter($this->cases->createCases()) : [];
        $page = in_array('page', $modes, true) ? $filter($this->cases->pageCases()) : [];
        if (!$edit && !$create && !$page) {
            $io->error('Aucun cas à jouer.');
            return Command::INVALID;
        }

        $stored = $this->site->storedCompositions();
        $palette = $this->site->palette($stored);
        $io->title(sprintf('Évaluation de l\'assistant IA : %d cas, modèles %s (edit, create) et %s (page, images), tenant %s', count($edit) + count($create) + count($page), $this->composer->editModel(), $this->composer->pageModel(), $tenant));

        $report = ['date' => date(DATE_ATOM), 'tenant' => $tenant, 'models' => ['edit' => $this->composer->editModel(), 'page' => $this->composer->pageModel()], 'tuning' => $this->tuning->describe(), 'cases' => []];
        $io->writeln('Réglages : ' . json_encode($this->tuning->describe(), JSON_UNESCAPED_UNICODE));
        $rows = [];
        foreach ($edit as $case) {
            [$caseReport, $row] = $this->playEdit($io, $case, $stored, $palette);
            $report['cases'][] = $caseReport;
            $rows[] = $row;
        }
        foreach ($create as $case) {
            [$caseReport, $row] = $this->playCreate($io, $case, $stored);
            $report['cases'][] = $caseReport;
            $rows[] = $row;
        }
        foreach ($page as $case) {
            [$caseReport, $row] = $this->playPage($io, $case, $stored);
            $report['cases'][] = $caseReport;
            $rows[] = $row;
        }

        $report['summary'] = [];
        foreach (array_keys(self::MODE_TITLES) as $m) {
            $modeCases = array_values(array_filter($report['cases'], fn ($c) => ($c['mode'] ?? null) === $m));
            if ($modeCases) {
                $report['summary'][$m] = $this->summarize($modeCases);
            }
        }
        if (!is_dir($this->reportDir)) {
            mkdir($this->reportDir, 0775, true);
        }
        $file = sprintf('%s/eval-%s-%s.json', $this->reportDir, $tenant, date('Ymd-His'));
        file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        $io->table(['Cas', 'Vérifications', 'Essais', 'Jetons entrée', 'Sortie', 'ms', '≈ $', 'Détail propre au cas'], $rows);
        foreach ($report['summary'] as $m => $s) {
            $io->section(self::MODE_TITLES[$m]);
            $lines = [['Cas réussis (composition obtenue)' => sprintf('%d / %d joués%s', $s['succeeded'], $s['played'], $s['skipped'] ? sprintf(' (%d sans objet)', $s['skipped']) : '')]];
            foreach ($s['checks'] as $check => [$ok, $measured]) {
                $lines[] = [$check => $measured ? sprintf('%d / %d', $ok, $measured) : 'sans objet'];
            }
            $lines[] = ['Vérifications propres réussies' => sprintf('%d / %d mesurables', $s['specificOk'], $s['specificMeasured'])];
            $lines[] = ['Essais moyens' => $s['avgAttempts']];
            $lines[] = ['Jetons moyens (entrée / cache lu / sortie)' => sprintf('%s / %s / %s', $s['avgInputTokens'], $s['avgCacheReadTokens'], $s['avgOutputTokens'])];
            $lines[] = ['Durée moyenne' => $s['avgDurationMs'] . ' ms'];
            $lines[] = ['Coût estimé (moyen / total)' => sprintf('%.3f $ / %.2f $', $s['avgCostUsd'], $s['totalCostUsd'])];
            $io->definitionList(...$lines);
        }
        $io->writeln('Rapport : ' . $file);

        return Command::SUCCESS;
    }

    private function playEdit(SymfonyStyle $io, array $case, array $stored, array $palette): array
    {
        [$family, $preset] = $this->catalogue->preset($case['presetId']) ?? [null, null];
        if ($preset === null) {
            return [['id' => $case['id'], 'mode' => 'edit', 'status' => 'skipped', 'reason' => "modèle {$case['presetId']} absent du catalogue"], [$case['id'], 'ignoré', '', '', '', '', '', "modèle {$case['presetId']} absent"]];
        }
        $componentKey = $family['componentKey'];
        $before = CompositionEditApplier::copy(json_decode(json_encode($preset['composition'], JSON_PRESERVE_ZERO_FRACTION), false));
        $allowedMedia = $this->site->allowedMedia([...$stored, $before], [], $case['prompt']);
        $io->writeln(sprintf(' <info>%s</info> %s/%s : « %s »', $case['id'], $componentKey, $case['presetId'], $case['prompt']));

        try {
            $result = $this->composer->edit($componentKey, $before, $case['prompt']);
        } catch (LandingAiException $e) {
            return $this->failure($case['id'], 'edit', $e);
        }

        $common = $this->checks->common($before, $result, $componentKey, $allowedMedia, $case['prompt']);
        $specific = ($case['check'])($before, $result->composition, $result, $palette);

        return [
            ['id' => $case['id'], 'mode' => 'edit', 'presetId' => $case['presetId'], 'componentKey' => $componentKey, 'prompt' => $case['prompt'], 'status' => 'ok',
                'checks' => $common, 'specific' => $specific, 'summary' => $result->summary, 'warnings' => $result->warnings,
                'operations' => $result->operations, 'composition' => $result->composition, 'usage' => $result->stats->toArray()],
            $this->row($case['id'], $common, $specific, $result->stats),
        ];
    }

    private function playCreate(SymfonyStyle $io, array $case, array $stored): array
    {
        if (isset($case['skip'])) {
            return [['id' => $case['id'], 'mode' => 'create', 'status' => 'skipped', 'reason' => $case['skip']], [$case['id'], 'sans objet', '', '', '', '', '', mb_substr($case['skip'], 0, 90)]];
        }
        $componentKey = $case['componentKey'];
        $available = $this->data->familyUsesData($componentKey) ? $this->data->available($componentKey) : [];
        $allowedMedia = $this->site->allowedMedia([...$stored, ...$this->site->presetCompositions($componentKey)], $case['media'], $case['prompt']);
        $io->writeln(sprintf(' <info>%s</info> %s (création, %d donnée(s)) : « %s »', $case['id'], $componentKey, count($available), $case['prompt']));

        try {
            $result = $this->composer->create($componentKey, $case['prompt'], 'fr', $case['media']);
        } catch (LandingAiException $e) {
            return $this->failure($case['id'], 'create', $e);
        }

        $knownText = $case['prompt'] . "\n" . implode("\n", array_map(fn ($d) => $d['title'] . ' ' . $d['details'], $available));
        $common = $this->checks->commonCreate($result->composition, $componentKey, $allowedMedia, $knownText);
        $specific = ($case['check'])($result, array_column($available, 'id'));

        return [
            ['id' => $case['id'], 'mode' => 'create', 'componentKey' => $componentKey, 'prompt' => $case['prompt'], 'status' => 'ok',
                'dataType' => $result->dataType, 'checks' => $common, 'specific' => $specific, 'summary' => $result->summary,
                'warnings' => $result->warnings, 'composition' => $result->composition, 'usage' => $result->stats->toArray()],
            $this->row($case['id'], $common, $specific, $result->stats),
        ];
    }

    private function playPage(SymfonyStyle $io, array $case, array $stored): array
    {
        if (isset($case['skip'])) {
            return [['id' => $case['id'], 'mode' => 'page', 'status' => 'skipped', 'reason' => $case['skip']], [$case['id'], 'sans objet', '', '', '', '', '', mb_substr($case['skip'], 0, 90)]];
        }
        if (isset($case['presetId'])) {
            return $this->playReview($io, $case, $stored);
        }
        $images = $this->images($case['images']);
        $families = $case['componentKey'] !== null ? [$case['componentKey']] : $this->catalogue->componentKeys();
        $presets = array_merge(...array_map(fn ($key) => $this->site->presetCompositions($key), $families));
        $allowedMedia = $this->site->allowedMedia([...$stored, ...$presets], $case['media'], $case['prompt']);
        $knownText = $case['prompt'];
        foreach ($families as $family) {
            foreach ($this->data->available($family) as $d) {
                $knownText .= "\n" . $d['title'] . ' ' . $d['details'];
            }
        }
        $io->writeln(sprintf(' <info>%s</info> page (%s) : « %s »', $case['id'], implode(', ', $case['images']) ?: 'sans image', $case['prompt']));

        try {
            $result = $this->composer->page($case['prompt'], 'fr', $case['media'], $images, $case['componentKey']);
        } catch (LandingAiException $e) {
            return $this->failure($case['id'], 'page', $e);
        }

        $common = $this->checks->commonPage($result->sections, $allowedMedia, $knownText);
        $specific = ($case['check'])($result);

        return [
            ['id' => $case['id'], 'mode' => 'page', 'images' => $case['images'], 'prompt' => $case['prompt'], 'status' => 'ok',
                'checks' => $common, 'specific' => $specific, 'summary' => $result->summary, 'warnings' => $result->warnings,
                'sections' => $result->sections, 'usage' => $result->stats->toArray()],
            $this->row($case['id'], $common, $specific, $result->stats),
        ];
    }

    /**
     * Relecture visuelle : retouche d'une composition dégradée ($case['prepare']) avec les captures de son rendu.
     */
    private function playReview(SymfonyStyle $io, array $case, array $stored): array
    {
        [$family, $preset] = $this->catalogue->preset($case['presetId']) ?? [null, null];
        if ($preset === null) {
            return [['id' => $case['id'], 'mode' => 'review', 'status' => 'skipped', 'reason' => "modèle {$case['presetId']} absent du catalogue"], [$case['id'], 'ignoré', '', '', '', '', '', "modèle {$case['presetId']} absent"]];
        }
        $componentKey = $family['componentKey'];
        $before = CompositionEditApplier::copy(json_decode(json_encode($preset['composition'], JSON_PRESERVE_ZERO_FRACTION), false));
        ($case['prepare'])($before);
        $allowedMedia = $this->site->allowedMedia([...$stored, $before], [], $case['prompt']);
        $io->writeln(sprintf(' <info>%s</info> %s/%s (%s) : « %s »', $case['id'], $componentKey, $case['presetId'], implode(', ', $case['images']), $case['prompt']));

        try {
            $result = $this->composer->edit($componentKey, $before, $case['prompt'], 'fr', [], $this->images($case['images']));
        } catch (LandingAiException $e) {
            return $this->failure($case['id'], 'review', $e);
        }

        $common = $this->checks->common($before, $result, $componentKey, $allowedMedia, $case['prompt']);
        $specific = ($case['check'])($before, $result->composition);

        return [
            ['id' => $case['id'], 'mode' => 'review', 'presetId' => $case['presetId'], 'componentKey' => $componentKey, 'images' => $case['images'], 'prompt' => $case['prompt'], 'status' => 'ok',
                'checks' => $common, 'specific' => $specific, 'summary' => $result->summary, 'warnings' => $result->warnings,
                'operations' => $result->operations, 'composition' => $result->composition, 'usage' => $result->stats->toArray()],
            $this->row($case['id'], $common, $specific, $result->stats),
        ];
    }

    /** @return list<array{mediaType: string, data: string}> images du dossier fixtures */
    private function images(array $files): array
    {
        return array_map(fn (string $file) => [
            'mediaType' => str_ends_with($file, '.jpg') ? 'image/jpeg' : 'image/png',
            'data' => base64_encode(file_get_contents($this->fixturesDir . '/' . $file)),
        ], $files);
    }

    private function failure(string $id, string $mode, LandingAiException $e): array
    {
        $stats = $e->getStats()?->toArray() ?? [];

        return [
            ['id' => $id, 'mode' => $mode, 'status' => 'failed', 'httpStatus' => $e->getStatusCode(), 'message' => $e->getMessage(), 'errors' => $e->getErrors(), 'usage' => $stats],
            [$id, 'échec ' . $e->getStatusCode(), $stats['attempts'] ?? '', $stats['inputTokens'] ?? '', $stats['outputTokens'] ?? '', $stats['durationMs'] ?? '', sprintf('%.3f', $this->cost($stats)), $e->getMessage()],
        ];
    }

    private function row(string $id, array $common, array $specific, $stats): array
    {
        $failed = array_keys(array_filter($common, fn ($c) => $c['ok'] === false));

        return [
            $id,
            ($failed ? 'V ✗ ' . implode(',', $failed) : 'V ✓') . ' / ' . ($specific['ok'] === null ? 'propre ?' : ($specific['ok'] ? 'propre ✓' : 'propre ✗')),
            $stats->attempts,
            $stats->inputTokens . ' (+' . $stats->cacheReadTokens . ' cache)',
            $stats->outputTokens,
            $stats->durationMs,
            sprintf('%.3f', $this->cost($stats->toArray())),
            mb_substr($specific['detail'], 0, 90),
        ];
    }

    /** Coût estimé d'une demande (USD), d'après les jetons et les prix du modèle ; 0 si le modèle est inconnu */
    private function cost(array $usage): float
    {
        [$input, $output, $read] = self::PRICES[$usage['model'] ?? ''] ?? [0.0, 0.0, 0.0];
        $write = (int) ($usage['cacheWriteTokens'] ?? 0);
        $writeFactor = $this->tuning->cacheTtl('page') !== null && ($usage['model'] ?? '') === $this->composer->pageModel() ? 2.0 : 1.25;

        return (((int) ($usage['inputTokens'] ?? 0) - $write) * $input
            + $write * $input * $writeFactor
            + (int) ($usage['cacheReadTokens'] ?? 0) * $read
            + (int) ($usage['outputTokens'] ?? 0) * $output) / 1000000;
    }

    private function summarize(array $cases): array
    {
        $played = array_values(array_filter($cases, fn ($c) => $c['status'] !== 'skipped'));
        $ok = array_values(array_filter($played, fn ($c) => $c['status'] === 'ok'));
        $checks = [];
        foreach (['V1', 'V2', 'V3', 'V4', 'V5', 'V6'] as $v) {
            $measured = array_filter($ok, fn ($c) => $c['checks'][$v]['ok'] !== null);
            $checks[$v] = [count(array_filter($measured, fn ($c) => $c['checks'][$v]['ok'])), count($measured)];
        }
        $measured = array_filter($ok, fn ($c) => $c['specific']['ok'] !== null);
        $avg = fn (string $key) => $played ? (int) round(array_sum(array_map(fn ($c) => $c['usage'][$key] ?? 0, $played)) / count($played)) : 0;

        return [
            'played' => count($played),
            'skipped' => count($cases) - count($played),
            'succeeded' => count($ok),
            'checks' => $checks,
            'specificMeasured' => count($measured),
            'specificOk' => count(array_filter($measured, fn ($c) => $c['specific']['ok'])),
            'avgAttempts' => $played ? round(array_sum(array_map(fn ($c) => $c['usage']['attempts'] ?? 0, $played)) / count($played), 2) : 0,
            'avgInputTokens' => $avg('inputTokens'),
            'avgCacheReadTokens' => $avg('cacheReadTokens'),
            'avgOutputTokens' => $avg('outputTokens'),
            'avgDurationMs' => $avg('durationMs'),
            'avgCacheWriteTokens' => $avg('cacheWriteTokens'),
            'avgCostUsd' => $played ? round(array_sum(array_map(fn ($c) => $this->cost($c['usage'] ?? []), $played)) / count($played), 4) : 0,
            'totalCostUsd' => round(array_sum(array_map(fn ($c) => $this->cost($c['usage'] ?? []), $played)), 3),
        ];
    }
}
