<?php

namespace App\Gestion\Command;

use App\Gestion\UseCase\SeedGestionDemoUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:gestion:seed-demo',
    description: 'Jeu de données de l\'application de gestion sur le site de test (demo) : fournisseurs, transporteurs, collections, commandes avec les produits existants, frais de port, notes de frais (rejouable, n\'efface rien)'
)]
class SeedGestionDemoCommand extends Command
{
    public function __construct(private readonly SeedGestionDemoUseCase $useCase)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Site de test à remplir', 'demo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $report = $this->useCase->execute((string) $input->getOption('tenant'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $report ? $io->listing($report) : $io->writeln('Rien à ajouter : tout est déjà en place.');
        $io->success('Jeu de données de gestion prêt (docs/gestion-mise-a-niveau.md § 8).');

        return Command::SUCCESS;
    }
}
