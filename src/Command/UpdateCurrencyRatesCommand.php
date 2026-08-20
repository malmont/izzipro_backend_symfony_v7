<?php

namespace App\Command;

use App\Services\CurrencyService\CurrencyService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:currency:update-rates',
    description: 'Met à jour les taux de change pour tous les tenants via l\'API externe.'
)]
class UpdateCurrencyRatesCommand extends Command
{
    public function __construct(
        private TenantConnectionManager $connectionManager,
        private TenantEntityManagerProvider $emProvider,
        private CurrencyService $currencyService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Mise à jour des taux de change pour tous les tenants');

        $tenantDbNames = $this->connectionManager->getAllTenantDbNames();

        if (empty($tenantDbNames)) {
            $io->warning('Aucun tenant trouvé.');
            return Command::SUCCESS;
        }

        $io->progressStart(count($tenantDbNames));

        foreach ($tenantDbNames as $dbname) {
            $io->newLine(); // Pour que le texte ne se colle pas à la barre
            $io->section("Tenant : $dbname");

            try {
                // Changement de contexte (connexion BDD)
                $this->emProvider->switchTenant($dbname);
                
                // Appel du service rénové (qui va chercher son repo dynamiquement)
                $this->currencyService->refreshRates();
                
                $io->success("Taux mis à jour pour $dbname");

            } catch (\Exception $e) {
                $io->error("Erreur pour $dbname : " . $e->getMessage());
            }

            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->success('Opération terminée.');

        return Command::SUCCESS;
    }
}
