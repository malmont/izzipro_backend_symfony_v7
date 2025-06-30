<?php
// src/Command/ExecuteSingleTenantMigrationCommand.php

namespace App\Command;

use App\Services\TenantConnectionManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:tenant:execute-migration',
    description: 'Exécute UNE SEULE migration spécifique sur un tenant.',
)]
class ExecuteSingleTenantMigrationCommand extends Command
{
    private TenantConnectionManager $manager;
    private string $projectDir;

    public function __construct(TenantConnectionManager $manager, string $projectDir)
    {
        parent::__construct();
        $this->manager = $manager;
        $this->projectDir = $projectDir;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('dbname', InputArgument::REQUIRED, 'Nom de la base de données du tenant.')
            ->addArgument('version', InputArgument::REQUIRED, 'La version exacte de la migration à exécuter (ex: DoctrineMigrations\Version2025...)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dbname = $input->getArgument('dbname');
        $version = $input->getArgument('version');

        $io->title(sprintf('Exécution manuelle de la migration sur le tenant « %s »', $dbname));
        $io->text('Version à exécuter : ' . $version);
        $io->newLine();

        if (!$io->confirm('Êtes-vous sûr de vouloir forcer l\'exécution de cette unique migration ?', false)) {
            $io->warning('Opération annulée.');
            return Command::INVALID;
        }

        try {
            // On utilise la logique de votre service pour récupérer les paramètres de connexion
            $connection = $this->manager->getConnection();
            $params = $connection->getParams();
            $params['dbname'] = $dbname;
            if ($connection->isConnected()) {
                $connection->close();
            }
            $tenantConnection = \Doctrine\DBAL\DriverManager::getConnection($params);

            // Configuration de Doctrine Migrations
            $configuration = new ConfigurationArray([
                'migrations_paths' => ['DoctrineMigrations' => $this->projectDir . '/migrations'],
                'table_storage' => ['table_name' => 'doctrine_migration_versions'],
            ]);
            $dependencyFactory = DependencyFactory::fromConnection($configuration, new ExistingConnection($tenantConnection));

            // On vérifie que la migration demandée existe
            if (!$dependencyFactory->getMigrationRepository()->hasMigration($version)) {
                 $io->error(sprintf('La version de migration "%s" est introuvable.', $version));
                 return Command::FAILURE;
            }
            
            // On crée un plan contenant UNIQUEMENT la version demandée
            $plan = $dependencyFactory->getMigrationPlanCalculator()->getPlanForVersions([$version], Direction::UP);

            if ($plan->count() === 0) {
                 $io->warning('Cette migration a déjà été exécutée selon la base de données. Aucune action effectuée.');
                 return Command::SUCCESS;
            }

            // Exécution du plan
            $migrator = $dependencyFactory->getMigrator();
            $migratorConfiguration = (new MigratorConfiguration())->setAllOrNothing(true);
            $migrator->migrate($plan, $migratorConfiguration);

            $io->success(sprintf('✅ La migration "%s" a été appliquée avec succès pour « %s ».', $version, $dbname));
            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error('Une erreur est survenue : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}