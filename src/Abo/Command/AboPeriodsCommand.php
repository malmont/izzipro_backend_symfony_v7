<?php

namespace App\Abo\Command;

use App\Abo\Services\AboRightsService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Passage quotidien des droits d'abonnement (lot ABO-1, 19/11/2026, `docs/boutique.md`) : pour chaque site, les périodes payées non
 * traitées sont réémises, puis chaque droit échu est reporté une fois (dans la limite de la formule, si l'abonnement continue) ou perdu.
 * Planification : ops/systemd/abo-periodes.timer (chaque nuit). Sans danger à répéter.
 */
#[AsCommand(name: 'app:abo:periodes', description: 'Droits d\'abonnement : réémet les périodes payées non traitées, reporte ou perd les droits échus')]
final class AboPeriodsCommand extends Command
{
    public function __construct(private readonly TenantConnectionManager $tenants, private readonly TenantEntityManagerProvider $emProvider, private readonly AboRightsService $rights,
        private readonly ?\App\Services\SubscriptionService\SubscriptionFeeService $fees = null) // lot ABO-CONF C : rattrapage de la commission
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Un seul site (code)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $only = $input->getOption('site');
        foreach ($this->tenants->getAllTenants() as $tenant) {
            if (empty($tenant['dbname']) || ($only !== null && $tenant['code'] !== $only)) {
                continue;
            }
            try {
                $this->emProvider->switchTenant((string) $tenant['dbname'], (string) $tenant['code']);
                $r = $this->rights->dailyPass();
                // lot ABO-CONF C : la commission voulue posée sur les abonnements en cours, 50 par site et par passage (repris au suivant)
                $f = $this->fees?->catchUp() ?? ['updated' => 0, 'failed' => 0];
                if ($f['updated'] + $f['failed'] > 0) {
                    $output->writeln(sprintf('%s : commission posée sur %d abonnement(s), %d en échec (repris au passage suivant).', $tenant['code'], $f['updated'], $f['failed']));
                }
                if ($r['redispatched'] + $r['carried'] + $r['expired'] > 0) {
                    $output->writeln(sprintf('%s : %d période(s) réémise(s), %d droit(s) reporté(s), %d perdu(s).', $tenant['code'], $r['redispatched'], $r['carried'], $r['expired']));
                }
            } catch (\Throwable $e) {
                // un site sans les tables d'ABO (migration pas encore passée) ou en panne n'arrête pas les autres
                $output->writeln(sprintf('<comment>%s : passage non fait (%s).</comment>', $tenant['code'], $e::class));
            }
        }

        return Command::SUCCESS;
    }
}
