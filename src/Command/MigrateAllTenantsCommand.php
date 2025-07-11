<?php
// src/Command/MigrateAllTenantsCommand.php
namespace App\Command;

use App\Services\TenantConnectionManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:tenant:migrate-all',
    description: 'Applique les migrations sur un ou tous les tenants existants.'
)]
class MigrateAllTenantsCommand extends Command
{
    private TenantConnectionManager $manager;

    // Le constructeur a été simplifié et ne demande plus $projectDir
    public function __construct(TenantConnectionManager $manager)
    {
        parent::__construct();
        $this->manager = $manager;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Applique les migrations Doctrine sur une base tenant spécifique ou sur toutes.')
            ->addArgument('dbname', InputArgument::OPTIONAL, 'Le nom de la base d\'un tenant. Si omis, s\'applique à tous.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $specificDbName = $input->getArgument('dbname');
        $tenantDbNames = [];

        if ($specificDbName) {
            $tenantDbNames[] = $specificDbName;
            $io->title(sprintf('Application des migrations pour le tenant « %s »', $specificDbName));
        } else {
            $io->title('Application des migrations pour TOUS les tenants');
            $tenantDbNames = $this->manager->getAllTenantDbNames(); 
        }

        if (empty($tenantDbNames)) {
            $io->warning('Aucun tenant trouvé à migrer.');
            return Command::SUCCESS;
        }

        if (!$io->confirm(sprintf('Confirmez-vous l\'application des migrations sur %d base(s) de données ?', count($tenantDbNames)), true)) {
            $io->warning('Opération annulée.');
            return Command::INVALID;
        }
        
        $masterParams = $this->manager->getConnection()->getParams();
        
        $io->progressStart(count($tenantDbNames));
        $errorCount = 0;

        foreach ($tenantDbNames as $dbname) {
            $io->newLine(2);
            $io->section("Tenant : $dbname");

            try {
                $tenantDatabaseUrl = sprintf(
                    'postgresql://%s:%s@%s:%s/%s',
                    $masterParams['user'],
                    $masterParams['password'],
                    $masterParams['host'],
                    $masterParams['port'],
                    $dbname
                );

                $process = new Process([
                    'php',
                    'bin/console',
                    'doctrine:migrations:migrate',
                    '--no-interaction'
                ]);

                $process->setEnv(['DATABASE_URL' => $tenantDatabaseUrl]);
                $process->setTimeout(3600);

                $process->mustRun();
                
                $io->text($process->getOutput());
                $io->success("Migrations pour \"$dbname\" terminées.");

            } catch (\Throwable $e) {
                $io->error(sprintf("Échec pour \"%s\": %s", $dbname, $e->getMessage()));
                if (isset($process)) {
                    $io->error($process->getErrorOutput());
                }
                $errorCount++;
            }

            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->newLine();

        if ($errorCount === 0) {
            $io->success('Toutes les migrations ont été appliquées avec succès.');
            return Command::SUCCESS;
        }

        $io->warning(sprintf('%d tenant(s) ont rencontré des erreurs durant la migration.', $errorCount));
        return Command::FAILURE;
    }
}