<?php

namespace App\Command;

use App\Entity\Entreprise;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\OrderService\OrderDeskService;
use App\Services\Platform\SiteControlsService;
use App\Services\Platform\SiteReadinessService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Courriel hebdomadaire à l'administrateur de chaque site (07/11/2026) : étapes indispensables restantes, commandes à traiter, contrôles en
 * défaut, crédits IA restants, alertes de la caisse ouvertes. Envoyé par la plateforme au courriel de la fiche Entreprise, seulement s'il y a
 * quelque chose à dire (option --always pour l'envoyer quand même). Unité systemd : ops/systemd/site-weekly-digest.timer (lundi 07:00 UTC).
 */
#[AsCommand(name: 'app:site:weekly-digest', description: 'Envoie à chaque site son point hebdomadaire : étapes restantes, commandes à traiter, contrôles, crédits IA, alertes caisse')]
final class SiteWeeklyDigestCommand extends Command
{
    public function __construct(
        private readonly TenantConnectionManager $tenants,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly SiteReadinessService $readiness,
        private readonly OrderDeskService $orders,
        private readonly SiteControlsService $controls,
        private readonly LandingAiQuotaService $quota,
        private readonly EmailSenderService $mailer,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_OPTIONAL, 'Un seul site (code)')
            ->addOption('always', null, InputOption::VALUE_NONE, 'Envoyer même quand tout est en ordre')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Afficher sans envoyer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $only = $input->getOption('tenant');
        $rows = $this->tenants->getPdoMaster()->query('SELECT code, dbname, name FROM tenants ORDER BY code')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $sent = 0;
        foreach ($rows as $t) {
            if ($t['code'] === 'gmasuite' || ($only !== null && $t['code'] !== $only)) {
                continue;
            }
            try {
                $digest = $this->digest((string) $t['code'], (string) $t['dbname']);
                if ($digest === null) {
                    $output->writeln(sprintf('<comment>%s : aucun courriel de contact</comment>', $t['code']));
                    continue;
                }
                if (!$digest['hasSomething'] && !$input->getOption('always')) {
                    $output->writeln(sprintf('%s : rien à signaler', $t['code']));
                    continue;
                }
                $output->writeln(sprintf('%s → %s : %d indispensable(s), %d commande(s) à traiter, %d problème(s), %d crédit(s) IA restant(s)', $t['code'], $digest['to'], $digest['blockingTodo'], $digest['ordersTodo'], $digest['problems'], $digest['creditsRemaining']));
                if ($input->getOption('dry-run')) {
                    continue;
                }
                $subject = sprintf('%s : votre point de la semaine', $digest['siteName']);
                // par la plateforme (MAILER_DSN) ; sinon par l'expéditeur du site lui-même
                if ($this->mailer->sendPlatformEmail($digest['to'], $subject, 'emails/weekly_digest.html.twig', $digest) || $this->mailer->sendTemplatedEmail($digest['to'], $subject, 'emails/weekly_digest.html.twig', $digest, 'fr', 'backend-strapi.online')) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf('[Point hebdomadaire] %s : %s', $t['code'], $e->getMessage()));
                $output->writeln(sprintf('<comment>%s : %s</comment>', $t['code'], $e->getMessage()));
            }
        }
        $output->writeln(sprintf('%d courriel(s) envoyé(s).', $sent));

        return Command::SUCCESS;
    }

    /** @return array<string, mixed>|null null sans courriel de contact */
    public function digest(string $code, string $dbname): ?array
    {
        $this->emProvider->switchTenant($dbname, $code);
        $em = $this->emProvider->getEntityManager();
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC']);
        $to = trim((string) $entreprise?->getEmail());
        if ($to === '' || !str_contains($to, '@')) {
            return null;
        }
        $readiness = $this->readiness->forSite($code);
        $blocking = array_values(array_filter($readiness['steps'], fn ($s) => $s['status'] === 'todo' && ($s['blocking'] ?? true)));
        $improvements = array_values(array_filter($readiness['steps'], fn ($s) => $s['status'] === 'todo' && !($s['blocking'] ?? true)));
        $todo = $this->orders->todo(5);
        $controls = $this->controls->overview();
        $problems = array_values(array_filter($controls['controls'], fn ($c) => $c['severity'] === 'problem'));
        $credits = $this->quota->credits();
        $alerts = 0;
        try {
            $alerts = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM pos_health_alert WHERE resolved_at IS NULL');
        } catch (\Throwable) {
        }
        $lowCredits = $credits['monthly'] > 0 && $credits['remaining'] * 5 <= $credits['monthly'];

        return [
            'to' => $to, 'siteCode' => $code, 'siteName' => (string) ($entreprise?->getName() ?: $code), 'firstname' => null,
            'blocking' => array_map(fn ($s) => $s['title'], array_slice($blocking, 0, 8)), 'blockingTodo' => count($blocking), 'improvements' => count($improvements), 'percent' => $readiness['percent'],
            'ordersTodo' => (int) $todo['counts']['total'], 'orders' => $todo['counts'], 'problems' => count($problems), 'problemList' => array_map(fn ($c) => sprintf('%s (%d)', $c['title'], $c['count']), $problems),
            'creditsRemaining' => (int) $credits['remaining'], 'creditsMonthly' => (int) $credits['monthly'], 'lowCredits' => $lowCredits, 'posAlerts' => $alerts,
            'hasSomething' => $blocking !== [] || (int) $todo['counts']['total'] > 0 || $problems !== [] || $lowCredits || $alerts > 0,
            'adminUrl' => 'https://backend-strapi.online/admin',
        ];
    }
}
