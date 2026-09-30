<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:landingpage-ai:compare',
    description: 'Assemble plusieurs rapports d\'évaluation en un fichier de comparaison lu par le banc du frontend (?compare=<fichier>)'
)]
class LandingAiCompareCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/landing-ai-eval')]
        private readonly string $reportDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sujet', InputArgument::REQUIRED, 'Nom du fichier : comparaison-<sujet>-<AAAAMMJJ>.json (lettres, chiffres, tirets)')
            ->addArgument('variantes', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Une par variante : <nom>=<rapport> (rapport : nom de fichier dans var/landing-ai-eval ou chemin), ex. avant=eval-….json apres=eval-….json')
            ->addOption('objet', null, InputOption::VALUE_REQUIRED, 'Description libre de la comparaison');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $subject = (string) $input->getArgument('sujet');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,60}$/', $subject)) {
            $io->error('Sujet invalide : lettres, chiffres et tirets seulement.');

            return Command::INVALID;
        }

        $reports = [];
        foreach ($input->getArgument('variantes') as $spec) {
            if (!preg_match('/^([^=]+)=(.+)$/', $spec, $m)) {
                $io->error("Variante attendue sous la forme <nom>=<rapport> : $spec");

                return Command::INVALID;
            }
            $path = str_contains($m[2], '/') ? $m[2] : $this->reportDir . '/' . basename($m[2]);
            if (!is_file($path)) {
                $io->error("Rapport introuvable : $path");

                return Command::INVALID;
            }
            $reports[$m[1]] = ['file' => basename($path), 'data' => json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)];
        }
        if (count($reports) < 2) {
            $io->error('Au moins deux variantes sont nécessaires.');

            return Command::INVALID;
        }

        $comparison = $this->build($subject, $reports, $input->getOption('objet'));
        $file = sprintf('%s/comparaison-%s-%s.json', $this->reportDir, $subject, date('Ymd'));
        file_put_contents($file, json_encode($comparison, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $io->success(sprintf('%d cas, %d variante(s) : %s', count($comparison['cas']), count($reports), $file));

        return Command::SUCCESS;
    }

    /**
     * Format lu par le banc du frontend : objet, site, modele, rapports, puis cas[] { id, componentKey, prompt,
     * <variante>: { dataType, summary, warnings, blocs, essais, jetonsSortie, dureeMs, composition | sections } }.
     * Un cas absent ou en échec dans une variante y vaut null.
     *
     * @param array<string, array{file: string, data: array}> $reports
     */
    private function build(string $subject, array $reports, ?string $objet): array
    {
        $byVariant = [];
        $order = [];
        foreach ($reports as $variant => $report) {
            foreach ($report['data']['cases'] ?? [] as $case) {
                if (($case['status'] ?? null) !== 'ok') {
                    continue;
                }
                $byVariant[$variant][$case['id']] = $case;
                $order[$case['id']] = $order[$case['id']] ?? count($order);
            }
        }

        $cases = [];
        foreach (array_keys($order) as $id) {
            $first = null;
            foreach ($byVariant as $variantCases) {
                $first ??= $variantCases[$id] ?? null;
            }
            $entry = ['id' => $id, 'componentKey' => $first['componentKey'] ?? ($first['sections'][0]['componentKey'] ?? null), 'prompt' => $first['prompt'] ?? ''];
            foreach (array_keys($reports) as $variant) {
                $case = $byVariant[$variant][$id] ?? null;
                $entry[$variant] = $case === null ? null : [
                    'dataType' => $case['dataType'] ?? null,
                    'summary' => $case['summary'] ?? '',
                    'warnings' => $case['warnings'] ?? [],
                    'blocs' => isset($case['composition']) ? count($case['composition']['blocks'] ?? []) : array_sum(array_map(fn ($s) => count($s['composition']['blocks'] ?? []), $case['sections'] ?? [])),
                    'essais' => $case['usage']['attempts'] ?? null,
                    'jetonsSortie' => $case['usage']['outputTokens'] ?? null,
                    'dureeMs' => $case['usage']['durationMs'] ?? null,
                ] + (isset($case['composition']) ? ['composition' => $case['composition']] : ['sections' => $case['sections'] ?? []]);
            }
            $cases[] = $entry;
        }

        $tenants = array_values(array_unique(array_map(fn ($r) => $r['data']['tenant'] ?? '?', $reports)));
        $models = [];
        foreach ($reports as $variant => $report) {
            $models[$variant] = $report['data']['models'] ?? ['edit' => $report['data']['model'] ?? '?'];
        }

        return [
            'objet' => $objet ?? sprintf('Comparaison « %s » : %s', $subject, implode(' / ', array_keys($reports))),
            'site' => count($tenants) === 1 ? $tenants[0] . ' (les dataType et les médias sont ceux de ce site)' : implode(', ', $tenants),
            'modele' => $models,
            'reglages' => array_map(fn ($r) => $r['data']['tuning'] ?? null, $reports),
            'rapports' => array_map(fn ($r) => $r['file'], $reports),
            'cas' => $cases,
        ];
    }
}
