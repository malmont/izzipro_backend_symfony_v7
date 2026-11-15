<?php

namespace App\Command;

use App\Services\Notification\NotificationWeeklyService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Résumé de la semaine, lancé à la main (lot NOTIF-2, 15/11/2026). L'envoi ordinaire se fait au passage horaire de
 * `app:gestion:notifications:envoyer`, le lundi à partir de 8 h du magasin (`NotificationWeeklyService`) : cette commande sert à voir
 * (`--dry-run`) ou à forcer (`--force`) le résumé d'un site. Sans `--force` elle suit la même règle : hors du lundi matin du magasin,
 * rien ne part. Un destinataire qui a déjà eu son résumé cette semaine n'en reçoit jamais un second.
 *
 * L'unité `ops/systemd/site-weekly-digest.timer` (lundi 07:00 UTC) ne sert plus : à cette heure aucun magasin d'Amérique n'a passé 8 h.
 */
#[AsCommand(name: 'app:site:weekly-digest', description: 'Résumé de la semaine de chaque site (ou --tenant) : point de la semaine et courriels de suivi retenus')]
final class SiteWeeklyDigestCommand extends Command
{
    public function __construct(
        private readonly TenantConnectionManager $tenants,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly NotificationWeeklyService $weekly,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_OPTIONAL, 'Un seul site (code)')
            ->addOption('always', null, InputOption::VALUE_NONE, 'Envoyer même quand il n\'y a rien à dire')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Envoyer hors du lundi matin du magasin')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Afficher sans envoyer ni écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $only = $input->getOption('tenant');
        $dryRun = (bool) $input->getOption('dry-run');
        $rows = $this->tenants->getPdoMaster()->query('SELECT code, dbname FROM tenants ORDER BY code')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $sent = 0;
        foreach ($rows as $t) {
            if ($t['code'] === 'gmasuite' || ($only !== null && $t['code'] !== $only)) {
                continue;
            }
            try {
                $this->emProvider->switchTenant((string) $t['dbname'], (string) $t['code']);
                $result = $this->weekly->sendIfDue(null, (bool) $input->getOption('force') || $dryRun, (bool) $input->getOption('always'), $dryRun);
                if (!$result['ran']) {
                    $output->writeln(sprintf('%s : pas l\'heure du résumé (lundi, à partir de 8 h du magasin)', $t['code']));
                    continue;
                }
                $output->writeln(sprintf('%s : %s, %d courriel(s) retenu(s), %d destinataire(s)%s', $t['code'], $result['hasSomething'] ? 'des points à signaler' : 'rien à signaler',
                    $result['held'], count($result['recipients']), $dryRun ? ' (essai : rien n\'est parti)' : sprintf(', %d envoi(s)', $result['emails'])));
                $sent += $result['emails'];
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf('[Résumé de la semaine] %s : %s', $t['code'], $e->getMessage()));
                $output->writeln(sprintf('<comment>%s : %s</comment>', $t['code'], $e::class));
            }
        }
        $output->writeln(sprintf('%d courriel(s) envoyé(s).', $sent));

        return Command::SUCCESS;
    }
}
