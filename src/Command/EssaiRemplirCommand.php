<?php

namespace App\Command;

use App\Services\Platform\Essai\TestSiteDataSeeder;
use App\Services\Platform\TestSites;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\BoutiqueDemoUseCase\SeedBoutiqueDemoUseCase;
use App\Gestion\UseCase\SeedGestionDemoUseCase;
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
    public function __construct(
        private readonly TestSites $testSites,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly SeedBoutiqueDemoUseCase $boutique,
        private readonly SeedGestionDemoUseCase $gestion,
        private readonly TestSiteDataSeeder $seeder,
    ) {
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
        $code = trim((string) $input->getOption('tenant'));
        if ($code === '' || !$this->testSites->allowed($code)) {
            $io->error(sprintf('Site « %s » refusé : seulement demo ou un site d\'une agence de test. Rien n\'est écrit.', $code));

            return Command::FAILURE;
        }
        $statement = $this->tenants->getPdoMaster()->prepare('SELECT dbname FROM tenants WHERE code = :code');
        $statement->execute(['code' => $code]);
        $dbname = $statement->fetchColumn();
        if (!is_string($dbname) || $dbname === '') {
            $io->error(sprintf('Site « %s » absent de la table tenants.', $code));

            return Command::FAILURE;
        }
        $dry = (bool) $input->getOption('dry-run');
        if (!$dry) {
            $io->section('Boutique');
            $io->listing($this->boutique->execute($code, 'client.demo@example.invalid')['report'] ?: ['rien à ajouter']);
            $io->section('Gestion');
            $io->listing($this->gestion->execute($code) ?: ['rien à ajouter']);
        }
        $this->emProvider->switchTenant($dbname, $code);
        $io->section('Clients, commandes, locations, pertes');
        $io->listing($this->seeder->seed($dry));
        $io->success($dry ? 'Comptage fait, rien n\'est écrit.' : 'Site d\'essai rempli.');

        return Command::SUCCESS;
    }
}
