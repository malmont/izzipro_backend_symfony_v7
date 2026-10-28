<?php

namespace App\Command;

use App\Services\FormSubmissionService\FormSubmissionService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Conservation des demandes reçues (formulaires à étapes, 28/10/2026) : supprime, sur chaque site, les demandes plus
 * anciennes que sa durée de conservation (Entreprise.formSubmissionsRetentionMonths, 12 mois par défaut). Lancée chaque
 * jour par ops/systemd/form-submissions-purge.timer ; la même purge a lieu à chaque envoi.
 */
#[AsCommand(name: 'app:form-submissions:purge', description: 'Supprime les demandes reçues au-delà de la durée de conservation de chaque site')]
class PurgeFormSubmissionsCommand extends Command
{
    public function __construct(
        private readonly TenantConnectionManager $connectionManager,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly FormSubmissionService $submissions
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('db', null, InputOption::VALUE_REQUIRED, 'Une seule base (ex. db_demo)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = $this->connectionManager->getPdoMaster()
            ->query('SELECT dbname, MIN(code) AS code FROM tenants GROUP BY dbname ORDER BY dbname')->fetchAll(\PDO::FETCH_ASSOC);
        $only = $input->getOption('db');
        $total = 0;
        $failed = 0;
        foreach ($rows as $row) {
            if ($only !== null && $row['dbname'] !== $only) {
                continue;
            }
            try {
                $this->emProvider->switchTenant($row['dbname'], $row['code']);
                $deleted = $this->submissions->purgeExpired();
                $total += $deleted;
                $io->writeln(sprintf(' ✓ %s : %d demande(s) supprimée(s)', $row['dbname'], $deleted));
            } catch (\Throwable $e) {
                $failed++;
                $io->writeln(sprintf(' <error>✗ %s</error> : %s', $row['dbname'], $e::class));
            }
        }
        $io->success(sprintf('%d demande(s) supprimée(s)%s.', $total, $failed ? sprintf(', %d base(s) en échec', $failed) : ''));

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
