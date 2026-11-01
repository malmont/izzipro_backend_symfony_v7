<?php

namespace App\Command;

use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\CaisseService\DenominationDefaults;
use App\Services\CaisseService\DenominationService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Coupures par défaut de la devise de chaque site (F18 partie 1, 01/11/2026) : créées seulement pour un site qui n'a aucune
 * ligne type_cash dans sa devise (idempotent : rien n'est modifié ni supprimé). Même liste que la fiche Entreprise et le bouton
 * EasyAdmin « Créer les coupures de la devise » ; journal DENOMINATIONS_CREATED (source « command »).
 */
#[AsCommand(name: 'app:pos:denominations-create', description: 'Caisse : crée les coupures par défaut de la devise des sites qui n\'en ont aucune')]
class PosDenominationsCreateCommand extends Command
{
    public function __construct(private readonly TenantConnectionManager $tenants, private readonly TenantEntityManagerProvider $emProvider, private readonly TenantCurrencyProvider $currency, private readonly DenominationService $denominations)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Un seul site (code)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Montre ce qui serait créé sans rien écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $only = $input->getOption('tenant');
        $dry = (bool) $input->getOption('dry-run');
        $visited = 0;
        foreach ($this->tenants->getAllTenants() as $tenant) {
            if ($only !== null && $tenant['code'] !== $only) {
                continue;
            }
            ++$visited;
            try {
                $this->emProvider->switchTenant($tenant['dbname'], $tenant['code']);
                $this->emProvider->getEntityManager()->clear();
                $currency = $this->currency->code(true);
                if ($this->denominations->hasAny($currency)) {
                    $output->writeln(sprintf('%s : %s, coupures déjà présentes, rien à faire', $tenant['code'], $currency));
                    continue;
                }
                if (!DenominationDefaults::has($currency)) {
                    $output->writeln(sprintf('<comment>%s : %s, aucune liste par défaut pour cette devise (à saisir dans EasyAdmin)</comment>', $tenant['code'], $currency));
                    continue;
                }
                if ($dry) {
                    $output->writeln(sprintf('%s : %s, %d coupures seraient créées', $tenant['code'], $currency, count(DenominationDefaults::for($currency))));
                    continue;
                }
                $created = $this->denominations->createDefaults($currency, 'command');
                $output->writeln(sprintf('<info>%s : %s, %d coupures créées</info>', $tenant['code'], $currency, $created));
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>%s : %s</error>', $tenant['code'], $e->getMessage()));
            }
        }
        if ($visited === 0) {
            $output->writeln('<error>Aucun site parcouru' . ($only !== null ? sprintf(' (code %s inconnu)', $only) : '') . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
