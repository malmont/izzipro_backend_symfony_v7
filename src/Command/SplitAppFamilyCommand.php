<?php

namespace App\Command;

use App\UseCase\AppMembershipUseCase\SplitAppFamilyUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Opération unique du 14/11/2026 (`docs/apps-membres.md` § 2) : la famille `memoires` n'existe plus.
 *   php bin/console app:members:family-split demo                          simulation : aucune écriture, un rapport chiffré
 *   php bin/console app:members:family-split demo --apply --confirm=demo   reclasse les appartenances et promeut les propriétaires de livres web
 * Les appartenances fondées sur l'application passent à `livingmemory` ; un compte client propriétaire d'un livre web devient
 * biographe, affecté à ses livres, sans appartenance. Rejouable. Circuit : simulation, rapport, application site par site sur accord.
 */
#[AsCommand(name: 'app:members:family-split', description: 'Reclasse les appartenances de l\'ancienne famille memoires (vers livingmemory) et promeut en biographes les comptes clients propriétaires d\'un livre web : simulation par défaut')]
class SplitAppFamilyCommand extends Command
{
    public function __construct(private readonly SplitAppFamilyUseCase $useCase)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::REQUIRED, 'Code du site (table tenants)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Écrire (sans cette option : simulation)')
            ->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'Avec --apply : le code du site, redit')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Rendre le rapport en JSON (des nombres, et le chemin du fichier de détail)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $site = (string) $input->getArgument('site');
        $apply = (bool) $input->getOption('apply');
        if ($apply && $input->getOption('confirm') !== $site) {
            $io->error(sprintf('--apply écrit sur le site « %s » : redites son code avec --confirm=%s. Rien n\'a été écrit.', $site, $site));

            return Command::FAILURE;
        }
        try {
            $result = $this->useCase->execute($site, $apply);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }
        $r = $result['report'];
        $io->title(sprintf('Ancienne famille memoires : site « %s », %s', $site, $apply ? 'APPLICATION' : 'simulation (aucune écriture)'));
        $io->writeln(sprintf('Appartenances memoires : %d ; vers livingmemory : %d ; comptes clients propriétaires d\'un livre web à promouvoir biographes : %d (%d livre(s) web à leur affecter) ; appartenances à retirer sans autre suite : %d',
            $r['members'], $r['toLivingMemory'], $r['toBiographer'], $r['webBooksOfNewBiographers'], $r['revoked']));
        $io->writeln(sprintf('Laissés tels quels, à décider : %d compte(s) avec un livre web ET des traces de l\'application ; %d compte(s) sans mot de passe choisi propriétaire(s) d\'un livre web', $r['mixed'], $r['withoutPassword']));
        $io->writeln(sprintf('Membres de livingmemory avant l\'opération : %d', $r['livingMemoryMembers']));
        if ($apply) {
            $d = $result['done'];
            $io->writeln(sprintf('Fait : %d appartenance(s) passée(s) à livingmemory, %d compte(s) promu(s) biographe(s), %d livre(s) affecté(s), %d appartenance(s) retirée(s)', $d['moved'], $d['promoted'], $d['booksAssigned'], $d['revoked']));
        }
        $io->writeln('Détail par compte (identifiants seulement, hors dépôt) : ' . $result['detail']);
        $apply ? $io->success('Opération appliquée.') : $io->note('Simulation : rien n\'a été écrit. Pour appliquer : --apply --confirm=' . $site);

        return Command::SUCCESS;
    }
}
