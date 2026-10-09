<?php

namespace App\Command;

use App\Entity\Entreprise;
use App\Services\StripeService\StripeConnectSetupService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:boutique:stripe-setup',
    description: 'Prépare le compte Stripe connecté d\'un site (Express) : portail client et Stripe Tax (siège, inscriptions), par l\'API'
)]
class StripeConnectSetupCommand extends Command
{
    public function __construct(
        private readonly TenantConnectionManager $connectionManager,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly StripeConnectSetupService $setup
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Code du site (table tenants)')
            ->addOption('tax', null, InputOption::VALUE_NONE, 'Configure aussi Stripe Tax (siège = adresse de la fiche entreprise, sauf options ci-dessous)')
            ->addOption('registrations', null, InputOption::VALUE_REQUIRED, 'Inscriptions fiscales, séparées par des virgules (CA, CA-QC, US-NY, FR…)', implode(',', StripeConnectSetupService::DEFAULT_REGISTRATIONS_CA))
            ->addOption('line1', null, InputOption::VALUE_REQUIRED, 'Siège : rue')
            ->addOption('city', null, InputOption::VALUE_REQUIRED, 'Siège : ville')
            ->addOption('province', null, InputOption::VALUE_REQUIRED, 'Siège : province ou État (code)')
            ->addOption('postal-code', null, InputOption::VALUE_REQUIRED, 'Siège : code postal')
            ->addOption('country', null, InputOption::VALUE_REQUIRED, 'Siège : pays ISO', 'CA');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $code = (string) $input->getOption('tenant');
        $tenant = $code !== '' ? $this->connectionManager->findTenantByCode($code) : null;
        if (!$tenant || empty($tenant['dbname'])) {
            $io->error('Site introuvable : --tenant=<code> (table tenants).');

            return Command::INVALID;
        }
        $this->emProvider->switchTenant($tenant['dbname'], $code);
        if ($this->setup->accountId() === null) {
            $io->error('Aucun compte Stripe connecté actif sur ce site.');

            return Command::FAILURE;
        }
        $siteName = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([])?->getName() ?? $code;

        try {
            $configuration = $this->setup->ensurePortalConfiguration($siteName);
            $io->writeln(sprintf(' ✓ Portail client : configuration <info>%s</info> (carte, factures, adresse, résiliation à la fin de la période)', $configuration));

            if ($input->getOption('tax')) {
                $headOffice = $input->getOption('line1') ? [
                    'line1' => (string) $input->getOption('line1'), 'city' => (string) $input->getOption('city'), 'state' => $input->getOption('province') ?: null,
                    'postal_code' => (string) $input->getOption('postal-code'), 'country' => strtoupper((string) $input->getOption('country')),
                ] : null;
                $registrations = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('registrations')))));
                $result = $this->setup->setupTax($headOffice, $registrations);
                $io->writeln(sprintf(' ✓ Stripe Tax : statut <info>%s</info>, siège %s, inscriptions : %s', $result['status'],
                    $result['headOffice'] ? json_encode($result['headOffice'], JSON_UNESCAPED_UNICODE) : 'non défini (fiche entreprise sans adresse : passez --line1 --city --postal-code)', implode(', ', $result['registrations'])));
            }
        } catch (\Throwable $e) {
            $io->error('Stripe : ' . $e->getMessage());

            return Command::FAILURE;
        }
        $io->success('Compte Stripe connecté prêt.');

        return Command::SUCCESS;
    }
}
