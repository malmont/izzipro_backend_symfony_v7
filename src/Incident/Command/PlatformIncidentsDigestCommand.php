<?php

namespace App\Incident\Command;

use App\Incident\Services\PlatformIncidentService;
use App\Services\EmailConfigurationService\EmailSenderService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Courriel du jour des incidents de la plateforme (lot INC-1a, 18/11/2026, `docs/incidents-et-signalements.md`) : incidents nouveaux des
 * dernières 24 heures et incidents revenus après correction, à l'adresse de la plateforme (variable `POS_ALERT_PLATFORM_EMAIL`, posée
 * hors dépôt ; sans elle, rien ne part). Aucun courriel quand il n'y a rien. Purge ensuite (occurrences de plus de 90 jours, incidents
 * corrigés ou ignorés depuis plus d'un an). Minuteur : `ops/systemd/platform-incidents-digest.timer` (07:00 UTC).
 */
#[AsCommand(name: 'app:platform:incidents-digest', description: 'Courriel du jour des incidents de la plateforme, puis purge')]
class PlatformIncidentsDigestCommand extends Command
{
    public function __construct(private readonly PlatformIncidentService $incidents, private readonly EmailSenderService $mailer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compter sans envoyer ni purger');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $digest = $this->incidents->digest();
        $total = count($digest['new']) + count($digest['reopened']);
        $dry = (bool) $input->getOption('dry-run');
        if ($total === 0) {
            $output->writeln('Aucun incident nouveau ni revenu : aucun courriel.');
        } elseif (($to = self::recipient()) === null) {
            $output->writeln('<comment>Adresse de la plateforme absente (POS_ALERT_PLATFORM_EMAIL) : aucun courriel.</comment>');
        } elseif (!$dry) {
            $subject = sprintf('Incidents de la plateforme : %d nouveaux, %d revenus', count($digest['new']), count($digest['reopened']));
            $sent = $this->mailer->sendPlatformEmail($to, $subject, 'emails/platform_incidents_digest.html.twig', ['digest' => $digest]);
            $output->writeln($sent ? sprintf('Courriel envoyé : %d nouveaux, %d revenus.', count($digest['new']), count($digest['reopened'])) : '<error>Courriel non envoyé.</error>');
        } else {
            $output->writeln(sprintf('À envoyer : %d nouveaux, %d revenus.', count($digest['new']), count($digest['reopened'])));
        }
        if (!$dry) {
            [$events, $incidents] = $this->incidents->purge();
            $output->writeln(sprintf('Purge : %d occurrences, %d incidents.', $events, $incidents));
        }

        return Command::SUCCESS;
    }

    /** L'adresse de la plateforme, la même que celle des alertes de la caisse (`PosHealthService::platformRecipient`) */
    private static function recipient(): ?string
    {
        $email = $_ENV['POS_ALERT_PLATFORM_EMAIL'] ?? $_SERVER['POS_ALERT_PLATFORM_EMAIL'] ?? getenv('POS_ALERT_PLATFORM_EMAIL');

        return is_string($email) && filter_var(trim($email), FILTER_VALIDATE_EMAIL) ? trim($email) : null;
    }
}
