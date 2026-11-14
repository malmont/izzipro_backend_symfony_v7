<?php

namespace App\Command;

use App\Services\AppMembershipService\AppMembershipException;
use App\UseCase\AppMembershipUseCase\AppMembershipModeUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mode du contrôle d'appartenance d'un site (lot APPS-MEMBRES M3, `docs/apps-membres.md` § 6).
 *   php bin/console app:members:mode demo            lit le mode
 *   php bin/console app:members:mode demo require    une connexion à une famille dont le compte n'est pas membre passe par « Rejoindre »
 *   php bin/console app:members:mode demo observe    rien n'est refusé (retour toujours possible, tout de suite)
 * « require » est refusé tant que la reprise de l'historique du site n'est pas faite. Chaque changement est au journal du site.
 */
#[AsCommand(name: 'app:members:mode', description: 'Lit ou règle le mode du contrôle d\'appartenance aux familles d\'applications d\'un site : observe ou require')]
class AppMembershipModeCommand extends Command
{
    public function __construct(private readonly AppMembershipModeUseCase $useCase)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::REQUIRED, 'Code du site (table tenants)')
            ->addArgument('mode', InputArgument::OPTIONAL, 'observe ou require ; sans lui, le mode est seulement lu');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $result = $this->useCase->execute((string) $input->getArgument('site'), $input->getArgument('mode'));
        } catch (\InvalidArgumentException | AppMembershipException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->writeln(sprintf('Site « %s » : mode « %s »%s ; reprise de l\'historique %s.', $result['site'], $result['mode'], $result['changed'] ? ' (changé, au journal)' : '', $result['backfillDone'] ? 'faite' : 'pas faite'));

        return Command::SUCCESS;
    }
}
