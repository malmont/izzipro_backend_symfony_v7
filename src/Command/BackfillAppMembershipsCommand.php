<?php

namespace App\Command;

use App\Repository\UserAppMembershipRepository;
use App\UseCase\AppMembershipUseCase\BackfillAppMembershipsUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reprise de l'historique des appartenances d'un site (lot APPS-MEMBRES M2, `docs/apps-membres.md` § 8).
 *   php bin/console app:members:backfill demo                          simulation : aucune écriture, un rapport chiffré
 *   php bin/console app:members:backfill demo --apply --confirm=demo   crée les appartenances et marque la reprise faite
 * Circuit : la simulation d'abord, son rapport lu par le chef de projet, puis l'application site par site sur son accord.
 */
#[AsCommand(name: 'app:members:backfill', description: 'Reprise de l\'historique des appartenances aux familles d\'applications d\'un site : simulation par défaut, --apply pour écrire')]
class BackfillAppMembershipsCommand extends Command
{
    public function __construct(private readonly BackfillAppMembershipsUseCase $useCase)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::REQUIRED, 'Code du site (table tenants)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Écrire les appartenances (sans cette option : simulation)')
            ->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'Avec --apply : le code du site, redit, pour ne jamais écrire sur un autre site que celui voulu')
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
        $report = $result['report'];
        $io->title(sprintf('Appartenances, reprise de l\'historique : site « %s », %s', $site, $apply ? 'APPLICATION' : 'simulation (aucune écriture)'));
        $a = $report['accounts'];
        $io->writeln(sprintf('Comptes : %d ; écartés : %d d\'équipe, %d effacés, %d sans mot de passe choisi ; comptes clients examinés : %d', $a['total'], $a['team'], $a['erased'], $a['withoutPassword'], $a['eligible']));
        foreach ($report['families'] as $family => $f) {
            $traces = implode(', ', array_map(fn (string $t) => $t . ' ' . $f['byTrace'][$t], UserAppMembershipRepository::traceNames()[$family]));
            $io->writeln(sprintf('%s : %d déjà membres ; %d %s%s ; par trace : %s%s', $family, $f['members'], $f['proposed'], 'proposées', $apply ? sprintf(', %d créées', $result['created'][$family]) : '', $traces,
                $f['proposed'] > 0 ? sprintf(' ; entrées du %s au %s', substr((string) $f['oldest'], 0, 10), substr((string) $f['newest'], 0, 10)) : ''));
        }
        $io->writeln(sprintf('Comptes clients sans trace ni appartenance (ils passeront par « Rejoindre ») : %d, dont %d avec une adresse pour seul signe', $report['withoutTrace'], $report['addressOnly']));
        $n = $report['anomalies'];
        $io->writeln(sprintf('À savoir : %d fiche(s) de caisse rattachée(s) sans consentement de rattachement ; %d compte(s) sans mot de passe choisi avec des commandes ; %d commande(s) de compte sans origine connue (non comptées)', $n['posLinkedWithoutConsent'], $n['withoutPasswordWithOrders'], $n['ordersWithoutSource']));
        $io->writeln(sprintf('Reprise du site : %s', $report['backfilledAt'] === null ? 'pas faite' : 'faite le ' . $report['backfilledAt']));
        $io->writeln('Détail par compte (identifiants seulement, hors dépôt) : ' . $result['detail']);
        $apply ? $io->success('Reprise appliquée.') : $io->note('Simulation : rien n\'a été écrit. Pour appliquer : --apply --confirm=' . $site);

        return Command::SUCCESS;
    }
}
