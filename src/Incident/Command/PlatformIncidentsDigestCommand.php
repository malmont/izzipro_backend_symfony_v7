<?php

namespace App\Incident\Command;

use App\Incident\Services\PlatformBugReportService;
use App\Incident\Services\PlatformIncidentService;
use App\Services\EmailConfigurationService\EmailSenderService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Courriel du jour des incidents de la plateforme (lot INC-1a, 18/11/2026, `docs/incidents-et-signalements.md`) : incidents nouveaux des
 * dernières 24 heures et incidents revenus après correction, à l'adresse des alertes de la plateforme (`PlatformAlertRecipient` : réglage de la page Plateforme, sinon `POS_ALERT_PLATFORM_EMAIL`, posée
 * hors dépôt ; sans elle, rien ne part). Aucun courriel quand il n'y a rien. Purge ensuite (occurrences de plus de 90 jours, incidents
 * corrigés ou ignorés depuis plus d'un an). Minuteur : `ops/systemd/platform-incidents-digest.timer` (07:00 UTC).
 * Lot INC-3 : il porte aussi les signalements reçus sans réponse depuis 48 heures, et la purge efface les captures d'écran plus vieilles que
 * la durée réglée (`bug_report_screenshot_days`, 180 jours par défaut).
 */
#[AsCommand(name: 'app:platform:incidents-digest', description: 'Courriel du jour des incidents de la plateforme, puis purge')]
class PlatformIncidentsDigestCommand extends Command
{
    public function __construct(private readonly PlatformIncidentService $incidents, private readonly EmailSenderService $mailer, private readonly PlatformBugReportService $reports,
        private readonly \App\Services\Platform\PlatformAlertRecipient $recipient)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compter sans envoyer ni purger');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $digest = $this->incidents->digest() + ['unanswered' => $this->reports->unanswered()];
        $total = count($digest['new']) + count($digest['reopened']) + count($digest['unanswered']);
        $dry = (bool) $input->getOption('dry-run');
        if ($total === 0) {
            $output->writeln('Aucun incident nouveau ni revenu, aucun signalement en attente : aucun courriel.');
        } elseif (($to = $this->recipient->get()) === null) {
            $output->writeln('<comment>Adresse des alertes de la plateforme absente (page Plateforme, sinon POS_ALERT_PLATFORM_EMAIL) : aucun courriel.</comment>');
        } elseif (!$dry) {
            $subject = sprintf('Incidents de la plateforme : %d nouveaux, %d revenus, %d signalements en attente', count($digest['new']), count($digest['reopened']), count($digest['unanswered']));
            $sent = $this->mailer->sendPlatformEmail($to, $subject, 'emails/platform_incidents_digest.html.twig', ['digest' => $digest]);
            $output->writeln($sent ? sprintf('Courriel envoyé : %d nouveaux, %d revenus, %d signalements en attente.', count($digest['new']), count($digest['reopened']), count($digest['unanswered'])) : '<error>Courriel non envoyé.</error>');
        } else {
            $output->writeln(sprintf('À envoyer : %d nouveaux, %d revenus, %d signalements en attente.', count($digest['new']), count($digest['reopened']), count($digest['unanswered'])));
        }
        if (!$dry) {
            [$events, $incidents] = $this->incidents->purge();
            $output->writeln(sprintf('Purge : %d occurrences, %d incidents, %d captures.', $events, $incidents, $this->reports->purgeScreenshots()));
        }

        return Command::SUCCESS;
    }

}
