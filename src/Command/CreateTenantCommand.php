<?php
// src/Command/CreateTenantCommand.php
namespace App\Command;

use App\Services\TenantConnectionManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;

class CreateTenantCommand extends Command
{
    protected static $defaultName = 'app:tenant:create';
    private TenantConnectionManager $manager;

    public function __construct(TenantConnectionManager $manager)
    {
        parent::__construct();
        $this->manager = $manager;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Crée un tenant (DB + enregistrement + migrations).')
            ->addArgument('code',   InputArgument::REQUIRED, 'Code du tenant (lettres, chiffres, underscore)')
            ->addArgument('name',   InputArgument::REQUIRED, 'Nom du tenant (libre)')
            ->addArgument('dbname', InputArgument::REQUIRED, 'Nom de la base PostgreSQL (lettres, chiffres, underscore)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $code   = $input->getArgument('code');
        $name   = $input->getArgument('name');
        $dbname = $input->getArgument('dbname');

        // validation rapide
        if (!preg_match('/^[a-z0-9_]+$/i', $code)) {
            $io->error('Le code du tenant ne doit contenir que des lettres, chiffres ou underscore.');
            return Command::FAILURE;
        }
        if (!preg_match('/^[a-z0-9_]+$/i', $dbname)) {
            $io->error('Le nom de la base ne doit contenir que des lettres, chiffres ou underscore.');
            return Command::FAILURE;
        }

        $io->title("Création du tenant « {$code} »");
        $io->text("— Base PostgreSQL : {$dbname}");
        $io->newLine();

        try {
            $this->manager->createTenant($code, $name, $dbname);
            $io->success('✅ Tenant créé et migrations appliquées.');
            return Command::SUCCESS;

        } catch (\InvalidArgumentException $e) {
            $io->error('Paramètre invalide : ' . $e->getMessage());
            return Command::FAILURE;

        } catch (ProcessFailedException $e) {
            $io->error('Échec des migrations : ' . $e->getMessage());
            return Command::FAILURE;

        } catch (\Throwable $e) {
            $io->error('Une erreur est survenue : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
