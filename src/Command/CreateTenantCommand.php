<?php
// src/Command/CreateTenantCommand.php

namespace App\Command;

use App\Services\TenantConnectionManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:tenant:create',
    description: 'Crée un nouveau tenant (DB + Enregistrement).'
)]
class CreateTenantCommand extends Command
{
    public function __construct(
        private TenantConnectionManager $manager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'Le code du tenant (sous-domaine)')
            ->addArgument('name', InputArgument::REQUIRED, 'Le nom public de la boutique')
            ->addArgument('dbname', InputArgument::OPTIONAL, 'Le nom de la base de données (défaut: db_<code>)')
            
            // Options supplémentaires pour matcher ton Manager
            ->addOption('internal', null, InputOption::VALUE_NONE, 'Marquer comme boutique interne (V2V)')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Token GEM-SUITE (optionnel)')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Domaine personnalisé (ex: boutique.com)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $code = $input->getArgument('code');
        $name = $input->getArgument('name');
        $dbname = $input->getArgument('dbname') ?? 'db_' . $code;
        
        // Récupération des options
        $isInternal = $input->getOption('internal'); // bool
        $token = $input->getOption('token');         // string|null
        $customDomain = $input->getOption('domain'); // string|null

        // 1. Validation basique
        if (!preg_match('/^[a-z0-9_]+$/i', $code)) {
            $io->error('Le code ne doit contenir que des lettres, chiffres et underscores.');
            return Command::FAILURE;
        }
        if (!preg_match('/^[a-z0-9_]+$/i', $dbname)) {
            $io->error('Le nom de la DB ne doit contenir que des lettres, chiffres et underscores.');
            return Command::FAILURE;
        }

        // 2. Résumé avant action
        $io->title("Création du Tenant : $name");
        $io->table(
            ['Paramètre', 'Valeur'],
            [
                ['Code', $code],
                ['Base de données', $dbname],
                ['Interne', $isInternal ? 'OUI' : 'NON'],
                ['Token', $token ?? 'Aucun'],
                ['Domaine Perso', $customDomain ?? 'Aucun'],
            ]
        );

        try {
            $io->section('Initialisation...');

            // 3. Appel au Manager (avec les 6 arguments)
            $this->manager->createTenant(
                $code,
                $name,
                $dbname,
                $token,         // 4e arg : Token (nullable)
                $isInternal,    // 5e arg : Booléen
                $customDomain   // 6e arg : Custom Domain (nullable)
            );

            $io->success("✅ Tenant '{$code}' créé avec succès !");
            $io->text("Base de données : {$dbname}");
            
            if ($customDomain) {
                $io->note("N'oubliez pas de configurer le DNS pour : {$customDomain}");
            }

            return Command::SUCCESS;

        } catch (\InvalidArgumentException $e) {
            $io->error("Erreur de validation : " . $e->getMessage());
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error("Erreur critique lors de la création : " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}