<?php
// src/Command/MigrateTenantCommand.php
namespace App\Command;

use App\Services\TenantConnectionManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessFailedException;

class MigrateTenantCommand extends Command
{
    protected static $defaultName = 'app:tenant:migrate-db';
    private TenantConnectionManager $manager;

    public function __construct(TenantConnectionManager $manager)
    {
        parent::__construct();
        $this->manager = $manager;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Migre un seul tenant (nom de la base).')
            ->addArgument('dbname', InputArgument::REQUIRED, 'Nom de la base tenant (lettres, chiffres, underscore)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dbname = $input->getArgument('dbname');

        // Validation rapide du format
        if (!preg_match('/^[a-z0-9_]+$/i', $dbname)) {
            $io->error('Le nom de la base doit contenir uniquement des lettres, chiffres ou underscore.');
            return Command::FAILURE;
        }

        $io->title("Migration du tenant « {$dbname} »");
        $io->newLine();

        try {
            $this->manager->migrateTenant($dbname);
            $io->success("✅ Migrations appliquées avec succès pour « {$dbname} ».");
            return Command::SUCCESS;

        } catch (\InvalidArgumentException $e) {
            $io->error('Base introuvable ou invalide : ' . $e->getMessage());
            return Command::FAILURE;

        } catch (ProcessFailedException $e) {
            $io->error('Échec lors de l’exécution des migrations : ' . $e->getMessage());
            return Command::FAILURE;

        } catch (\Throwable $e) {
            $io->error('Une erreur est survenue : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
