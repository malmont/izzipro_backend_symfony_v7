<?php

namespace App\Command;

use App\UseCase\BoutiqueDemoUseCase\SeedBoutiqueDemoUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:boutique:seed-demo',
    description: 'Remplit la boutique du site de test (demo) : catalogue couvrant tous les cas, transporteurs, client de démonstration (rejouable, n\'efface rien)'
)]
class SeedBoutiqueDemoCommand extends Command
{
    /** Domaine réservé (RFC 2606) : aucun courrier réel ne part ; passer une vraie adresse pour tester le code de connexion */
    public const DEFAULT_CUSTOMER = 'client.demo@example.com';

    public function __construct(private readonly SeedBoutiqueDemoUseCase $useCase)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Site de test à remplir', 'demo')
            ->addOption('customer-email', null, InputOption::VALUE_REQUIRED, 'Adresse du client de démonstration', self::DEFAULT_CUSTOMER)
            ->addOption('reset-password', null, InputOption::VALUE_NONE, 'Tire un nouveau mot de passe pour le client de démonstration')
            ->addOption('otp', null, InputOption::VALUE_NONE, 'Active le code de connexion par e-mail pour le client (l\'adresse doit recevoir le courrier)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $result = $this->useCase->execute((string) $input->getOption('tenant'), (string) $input->getOption('customer-email'),
                (bool) $input->getOption('reset-password'), (bool) $input->getOption('otp'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $result['report'] ? $io->listing($result['report']) : $io->writeln('Rien à ajouter : tout est déjà en place.');
        if ($result['customerPassword'] !== null) {
            // Affiché une seule fois, jamais journalisé : le noter à part
            $io->note(sprintf('Mot de passe du client %s : %s', $input->getOption('customer-email'), $result['customerPassword']));
        }
        $io->success('Boutique de démonstration prête.');

        return Command::SUCCESS;
    }
}
