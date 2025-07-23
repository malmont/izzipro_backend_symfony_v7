<?php

namespace App\Command;

use App\Services\TenantConnectionManager;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:tenant:clean-migrations',
    description: 'Nettoie l\'historique des migrations fantômes pour tous les tenants.'
)]
class CleanMigrationHistoryCommand extends Command
{
    private TenantConnectionManager $manager;
    private string $projectDir;

    public function __construct(TenantConnectionManager $manager, string $projectDir)
    {
        parent::__construct();
        $this->manager = $manager;
        $this->projectDir = $projectDir;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Nettoyage de l\'historique des migrations fantômes');

        $tenantDbNames = $this->manager->getAllTenantDbNames();
        if (empty($tenantDbNames)) {
            $io->warning('Aucun tenant trouvé.');
            return Command::SUCCESS;
        }

        foreach ($tenantDbNames as $dbname) {
            $io->section("Tenant : $dbname");

            try {
                $connection = $this->manager->getConnection();
                $params = $connection->getParams();
                $params['dbname'] = $dbname;
                if ($connection->isConnected()) $connection->close();
                
                $tenantConnection = \Doctrine\DBAL\DriverManager::getConnection($params);
                $schemaManager = $tenantConnection->createSchemaManager();
                if (!$schemaManager->tablesExist(['doctrine_migration_versions'])) {
                    $io->writeln('La table de migration n\'existe pas. Aucune action requise.');
                    continue;
                }

                $configuration = new ConfigurationArray([
                    'migrations_paths' => ['DoctrineMigrations' => $this->projectDir . '/migrations'],
                    'table_storage' => ['table_name' => 'doctrine_migration_versions'],
                ]);
                $dependencyFactory = DependencyFactory::fromConnection($configuration, new ExistingConnection($tenantConnection));

                $availableMigrations = $dependencyFactory->getMigrationRepository()->getAvailableVersions();
                $executedMigrations = $dependencyFactory->getMigratedVersionsManager()->getMigratedVersions();

                $ghostMigrations = array_diff(array_keys($executedMigrations), $availableMigrations);

                if (empty($ghostMigrations)) {
                    $io->writeln('✅ Historique propre. Aucune migration fantôme trouvée.');
                    continue;
                }

                $io->warning(sprintf('Trouvé %d migration(s) fantôme(s) :', count($ghostMigrations)));
                $io->listing($ghostMigrations);

                if ($io->confirm('Voulez-vous supprimer ces entrées de l\'historique ?', true)) {
                    foreach ($ghostMigrations as $version) {
                        $tenantConnection->executeQuery('DELETE FROM doctrine_migration_versions WHERE version = :version', ['version' => $version]);
                    }
                    $io->success('Nettoyage terminé.');
                } else {
                    $io->info('Opération annulée.');
                }

            } catch (\Throwable $e) {
                $io->error(sprintf("Échec du nettoyage pour \"%s\": %s", $dbname, $e->getMessage()));
            }
        }

        $io->newLine();
        $io->success('Processus de nettoyage terminé pour tous les tenants.');

        return Command::SUCCESS;
    }
}
