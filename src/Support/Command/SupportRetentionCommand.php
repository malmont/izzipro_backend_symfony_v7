<?php

namespace App\Support\Command;

use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Support\Services\SupportRetentionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Assistance, lot 1 : anonymise les demandes fermées depuis plus de 3 ans, sur tous les sites (ou --tenant) ; simulation par défaut, --apply */
#[AsCommand(name: 'app:support:retention', description: 'Anonymise les demandes d\'assistance fermées depuis plus de 3 ans (simulation par défaut, --apply pour écrire)')]
class SupportRetentionCommand extends Command
{
    public function __construct(private readonly TenantConnectionManager $tenants, private readonly TenantEntityManagerProvider $emProvider, private readonly SupportRetentionService $retention)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Écrire (sinon simulation)')->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Code d\'un seul site');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $errors = 0;
        foreach ($this->tenants->getPdoMaster()->query('SELECT code, dbname FROM tenants ORDER BY code')->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            if ($input->getOption('tenant') !== null && $row['code'] !== $input->getOption('tenant')) {
                continue;
            }
            try {
                $this->emProvider->switchTenant((string) $row['dbname'], (string) $row['code']);
                $refs = $this->retention->run($apply);
                if ($refs !== []) {
                    $io->text(sprintf('%s : %d demande(s) %s (%s)', $row['code'], count($refs), $apply ? 'anonymisée(s)' : 'à anonymiser', implode(', ', $refs)));
                }
            } catch (\Throwable $e) {
                ++$errors;
                $io->warning(sprintf('%s : non traité (%s)', $row['code'], $e->getMessage()));
            }
        }
        $io->success($apply ? 'Anonymisation faite.' : 'Simulation (rien d\'écrit).');

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
