<?php

namespace App\Command;

use App\Services\Platform\Essai\EssaiRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `app:essai:remplir --tenant=<code>` (lot ESSAI-DONNÉES, 17/11/2026) : remplit un SITE D'ESSAI de données fictives, en enchaînant le jeu
 * d'exemple de la boutique, celui de la gestion, puis clients, commandes, expéditions, une annulation, des locations et un exercice des
 * pertes (`TestSiteDataSeeder`). Verrou relu dans la base maître : `demo` ou un site d'une agence de test (`TestSites`) ; tout autre
 * site est refusé et rien n'est écrit. `--dry-run` compte sans écrire. Rejouable, n'efface rien.
 */
#[AsCommand(name: 'app:essai:remplir', description: 'Remplit un site d\'essai (demo, ou site d\'une agence de test) de données fictives par les chemins normaux (rejouable, n\'efface rien)')]
class EssaiRemplirCommand extends Command
{
    public function __construct(private readonly EssaiRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Code du site d\'essai')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compter sans écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry-run');
        try {
            $report = $this->runner->run(trim((string) $input->getOption('tenant')), $dry);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->listing($report);
        $io->success($dry ? 'Comptage fait, rien n\'est écrit.' : 'Site d\'essai rempli.');

        return Command::SUCCESS;
    }
}
