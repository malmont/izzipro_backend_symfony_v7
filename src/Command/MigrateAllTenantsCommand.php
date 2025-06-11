<?php
// src/Command/MigrateAllTenantsCommand.php
namespace App\Command;

use App\Services\TenantConnectionManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessFailedException;

class MigrateAllTenantsCommand extends Command
{
    protected static $defaultName = 'app:tenant:migrate-all';
    private TenantConnectionManager $manager;

    public function __construct(TenantConnectionManager $manager)
    {
        parent::__construct();
        $this->manager = $manager;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Migre toutes les bases tenants via Doctrine Migrations.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Migration de tous les tenants');
        $io->text('Cette commande applique toutes les migrations Doctrine sur chaque base tenant.');
        $io->newLine();

        try {
            $this->manager->migrateAllTenants();
            $io->success('✅ Toutes les migrations ont été appliquées avec succès.');
            return Command::SUCCESS;

        } catch (ProcessFailedException $e) {
            $io->error('Échec lors de l’exécution des migrations : ' . $e->getMessage());
            return Command::FAILURE;

        } catch (\Throwable $e) {
            $io->error('Une erreur inattendue est survenue : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
