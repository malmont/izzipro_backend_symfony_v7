<?php

namespace App\Command;

use App\Entity\Collections;
use App\Services\GestionService\GestionAdviceService;
use App\Services\GestionService\PeriodCalendar;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Guide de la gestion (lot G1a, 11/11/2026, `docs/guide-ia-gestion.md`) : calcule les règles du jour d'un site (sans IA) et affiche la réponse de
 * GET /api/gestion/conseils, pour un contrôle ou un exemple. Écrit les conseils du jour comme le ferait le premier GET ; à lancer sur demo.
 */
#[AsCommand(name: 'app:gestion:conseils', description: 'Conseils du guide de la gestion d\'un site (règles du jour, sans IA), en JSON')]
class GestionAdviceCommand extends Command
{
    public function __construct(
        private readonly TenantConnectionManager $tenants,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly GestionAdviceService $advices,
        private readonly PeriodCalendar $calendar,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Code du site')
            ->addOption('period', null, InputOption::VALUE_REQUIRED, 'Identifiant de la période (défaut : en cours)')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Langue', 'fr');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $code = (string) $input->getOption('tenant');
        $tenant = $this->tenants->findTenantByCode($code);
        if ($tenant === null || empty($tenant['dbname'])) {
            $output->writeln('<error>Site introuvable</error>');

            return Command::FAILURE;
        }
        $this->emProvider->switchTenant((string) $tenant['dbname'], $code);
        $pid = $input->getOption('period');
        $period = $pid === null ? $this->calendar->current() : $this->emProvider->getEntityManager()->find(Collections::class, (int) $pid);
        if ($period === null) {
            $output->writeln('<error>Période introuvable</error>');

            return Command::FAILURE;
        }
        $this->advices->ensureToday($period, false);
        $output->writeln((string) json_encode($this->advices->listWithoutAi($period, 'new', (string) $input->getOption('locale')), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
