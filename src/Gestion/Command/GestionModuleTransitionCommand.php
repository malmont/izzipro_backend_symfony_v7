<?php

namespace App\Gestion\Command;

use App\Gestion\Services\GestionModuleTransitionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lot MOD (11/11/2026) : accorde l'application de gestion aux sites qui s'en servent déjà, avant le passage de la plateforme en mode live.
 * Simulation par défaut (--dry-run) ; --apply écrit (droit manuel sans fin, note « transition MOD ») ; rejouable sans effet.
 */
#[AsCommand(name: 'app:gestion:module:transition', description: 'Accorde l\'application de gestion aux sites qui s\'en servent déjà (simulation par défaut, --apply pour écrire)')]
class GestionModuleTransitionCommand extends Command
{
    public function __construct(private readonly GestionModuleTransitionService $transition)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Écrire les droits (sinon simulation)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulation (défaut)')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Code d\'un seul site');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply') && !$input->getOption('dry-run');
        $rows = $this->transition->run($apply, $input->getOption('tenant'), 'transition MOD (commande)');
        $io->table(['Site', 'Raisons', 'Action'], array_map(fn ($r) => [$r['code'], implode(', ', $r['reasons']) ?: '-', $r['action'] . (isset($r['error']) ? ' : ' . $r['error'] : '')], $rows));
        $errors = count(array_filter($rows, fn ($r) => $r['action'] === 'error'));
        $io->success(sprintf('%s : %d à accorder ou accordé(s), %d déjà actif(s), %d sans usage, %d erreur(s).', $apply ? 'Écrit' : 'Simulation',
            count(array_filter($rows, fn ($r) => in_array($r['action'], ['to_grant', 'granted'], true))), count(array_filter($rows, fn ($r) => $r['action'] === 'already')),
            count(array_filter($rows, fn ($r) => $r['action'] === 'none')), $errors));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
