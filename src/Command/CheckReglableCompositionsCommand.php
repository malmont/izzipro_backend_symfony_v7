<?php

namespace App\Command;

use App\Services\LandingPageSettingsService\ReglableCompositionScanner;
use App\Services\LandingPageSettingsService\ReglableCompositionValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:landingpage:check-reglable',
    description: 'Vérifie les compositions réglables enregistrées de chaque site avec le schéma actuel (lecture seule)'
)]
class CheckReglableCompositionsCommand extends Command
{
    public function __construct(
        private readonly ReglableCompositionScanner $scanner,
        private readonly ReglableCompositionValidator $validator
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('export', null, InputOption::VALUE_REQUIRED, 'Écrit les compositions trouvées dans ce fichier JSON (jeu de test)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $total = $invalid = 0;
        $export = [];
        foreach ($this->scanner->scan() as $database) {
            $dbName = $database['database'];
            if ($database['error'] !== null) {
                $io->writeln(sprintf(' <comment>%s</comment> : ignorée (%s)', $dbName, $database['error']));
                continue;
            }

            $compositions = $database['compositions'];
            $siteErrors = 0;
            foreach ($compositions as $path => $composition) {
                $total++;
                $export["$dbName $path"] = $composition;
                $errors = $this->validator->validateComposition($composition, $path);
                if ($errors) {
                    $invalid++;
                    $siteErrors++;
                    foreach ($errors as $error) {
                        $io->writeln(sprintf('   <error>%s</error> %s : %s', $dbName, $error['path'], $error['message']));
                    }
                }
            }
            $io->writeln(sprintf(' %s <info>%s</info> : %d composition(s), %d refusée(s)', $siteErrors ? '✗' : '✓', $dbName, count($compositions), $siteErrors));
        }

        if ($file = $input->getOption('export')) {
            file_put_contents($file, json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
            $io->writeln("Compositions exportées dans $file");
        }

        if ($invalid) {
            $io->error(sprintf('%d composition(s) sur %d seraient refusées à l\'enregistrement.', $invalid, $total));
            return Command::FAILURE;
        }
        $io->success(sprintf('%d composition(s) valides.', $total));

        return Command::SUCCESS;
    }
}
