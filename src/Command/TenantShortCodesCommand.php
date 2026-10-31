<?php

namespace App\Command;

use App\Services\TenantConnectionManager;
use App\Services\TenantShortCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Codes courts des sites (31/10/2026) : attribue un code à chaque site qui n'en a pas (idempotent), ou régénère
 * celui d'un site (--regenerate=<code> : l'ancien code ne marche plus aussitôt). Colonne ajoutée par
 * scripts/migrate_master_v2_short_code.sh ; les nouveaux sites reçoivent leur code à la création.
 */
#[AsCommand(name: 'app:tenant:short-codes', description: 'Attribue les codes courts manquants des sites (idempotent), ou régénère celui d\'un site')]
class TenantShortCodesCommand extends Command
{
    public function __construct(private readonly TenantConnectionManager $manager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('regenerate', null, InputOption::VALUE_REQUIRED, 'Code du site dont le code court est remplacé');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $site = $input->getOption('regenerate');
        if (\is_string($site) && $site !== '') {
            $old = $this->manager->shortCodeOf($site);
            $new = $this->manager->assignShortCode($site, true);
            $io->success(sprintf('Site %s : code court %s (ancien : %s)', $site, TenantShortCode::format($new), TenantShortCode::format($old) ?? 'aucun'));

            return Command::SUCCESS;
        }
        $result = $this->manager->assignMissingShortCodes();
        $io->writeln(sprintf('Sites : %d ; codes attribués : %d ; sites encore sans code : %d', $result['before'], $result['assigned'], $result['missing']));
        foreach ($this->manager->getAllTenants() as $tenant) {
            $io->writeln(sprintf('  %-20s %s', $tenant['code'], TenantShortCode::format($this->manager->shortCodeOf($tenant['code'])) ?? '(aucun)'));
        }

        return $result['missing'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
