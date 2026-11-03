<?php

namespace App\Command;

use App\Services\TechMetricsService\TechMetricsCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Collecte des mesures techniques (tableau de bord technique, T1, 03/11/2026), lancée toutes les 5 minutes par le timer systemd
 * `tech-metrics.timer` (ops/systemd) à travers ops/tech-metrics-host.sh, qui lui transmet les mesures de l'hôte en JSON sur l'entrée
 * standard (`--host-json=-`). Un verrou fichier empêche deux passages simultanés. Sortie : une ligne par étape.
 */
#[AsCommand(name: 'app:tech-metrics:collect', description: 'Relève les mesures techniques par site et pour la plateforme (tableau de bord technique)')]
class TechMetricsCollectCommand extends Command
{
    public function __construct(
        private readonly TechMetricsCollector $collector,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host-json', null, InputOption::VALUE_REQUIRED, 'Mesures de l\'hôte : chemin d\'un fichier JSON, ou « - » pour l\'entrée standard')
            ->addOption('files', null, InputOption::VALUE_NONE, 'Force le relevé des tailles de fichiers (sinon une fois par jour)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = fopen($this->projectDir . '/var/tech-metrics.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('Collecte déjà en cours : passage ignoré.');

            return Command::SUCCESS;
        }
        $host = null;
        $source = $input->getOption('host-json');
        if ($source !== null) {
            $raw = $source === '-' ? stream_get_contents(STDIN) : @file_get_contents($source);
            $host = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($host)) {
                $output->writeln('<comment>Mesures de l\'hôte illisibles : ignorées.</comment>');
                $host = null;
            }
        }
        try {
            $summary = $this->collector->collect(null, (bool) $input->getOption('files'), $host);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $failed = false;
        foreach ($summary as $step => $result) {
            $failed = $failed || is_string($result);
            $output->writeln(sprintf('%-9s %s', $step, is_string($result) ? '<error>' . $result . '</error>' : $result . ' ligne(s)'));
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
