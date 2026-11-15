<?php

namespace App\Services\Notification;

use App\Entity\Entreprise;
use App\Services\CaisseService\StoreClock;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\OrderService\OrderDeskService;
use App\Services\Platform\SiteControlsService;
use App\Services\Platform\SiteReadinessService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Lot NOTIF-2 (15/11/2026, `docs/notifications-courriels.md`) : le résumé de la semaine du site courant. Il réunit le point de la
 * semaine (étapes indispensables restantes, commandes à traiter, contrôles en défaut, crédits IA, alertes de la caisse) et les
 * courriels de suivi retenus au niveau « Essentiel ».
 *
 * Quand : le lundi du magasin, à partir de 8 h et pendant `WINDOW_HOURS` heures, au passage horaire de
 * `app:gestion:notifications:envoyer`. Un destinataire qui a eu son résumé cette semaine n'en reçoit pas un second ; un envoi en
 * échec est retenté au passage suivant de la fenêtre.
 *
 * À qui : chaque administrateur du site (compte vérifié), sauf au niveau « Aucune » et sauf s'il a coupé le type « résumé de la
 * semaine » dans « Avancé ». L'adresse de contact du site ne le reçoit que si elle est celle d'un administrateur.
 *
 * Les retenus : toute ligne retenue dont le destinataire est au niveau « Essentiel ». Dès qu'un administrateur a reçu le résumé, elles
 * passent à `sent` ; si aucun administrateur ne peut le recevoir (tous au niveau « Aucune » ou type coupé), elles passent à `kept`
 * (gardées, lisibles dans l'application). Si l'envoi échoue, elles restent retenues. Rien ne part s'il n'y a rien à dire. Un
 * administrateur ne lit pas les titres d'un type qu'il a coupé. Le compte du super administrateur de la plateforme n'est pas un
 * administrateur du site : il ne reçoit rien.
 */
class NotificationWeeklyService
{
    /** Après 8 h du magasin, nombre de passages horaires pendant lesquels le résumé peut encore partir */
    public const WINDOW_HOURS = 4;
    public const MAX_LINES = 20;
    private const TEMPLATE = 'emails/weekly_digest.html.twig';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly NotificationGate $gate,
        private readonly NotificationLevelResolver $levels,
        private readonly StoreClock $clock,
        private readonly SiteReadinessService $readiness,
        private readonly OrderDeskService $orders,
        private readonly SiteControlsService $controls,
        private readonly LandingAiQuotaService $quota,
        private readonly EmailSenderService $mailer,
        private readonly EmailLocale $locale,
        private readonly TranslatorInterface $translator,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantPublicHostResolver $hosts,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Le résumé est-il dû à cet instant ? Le lundi du magasin, de 8 h à 8 h + `WINDOW_HOURS` */
    public function isDue(\DateTimeImmutable $now): bool
    {
        $local = $now->setTimezone($this->clock->timezone());
        $hour = (int) $local->format('G');

        return (int) $local->format('N') === 1 && $hour >= $this->gate->fromHour() && $hour < $this->gate->fromHour() + self::WINDOW_HOURS;
    }

    /**
     * @param bool $force  hors du lundi matin (lancement à la main) ; un destinataire déjà servi cette semaine ne l'est pas deux fois
     * @param bool $always envoyer même quand il n'y a rien à dire
     * @param bool $dryRun ne rien envoyer ni écrire
     * @return array{ran: bool, emails: int, released: int, kept: int, recipients: list<string>, hasSomething: bool, held: int} `recipients` : ceux qui ont reçu le résumé (à blanc : ceux qui le recevraient)
     */
    public function sendIfDue(?\DateTimeImmutable $now = null, bool $force = false, bool $always = false, bool $dryRun = false): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $out = ['ran' => false, 'emails' => 0, 'released' => 0, 'kept' => 0, 'recipients' => [], 'hasSomething' => false, 'held' => 0];
        if (!$force && !$this->isDue($now)) {
            return $out;
        }
        $out['ran'] = true;
        $day = $this->clock->day($now);
        $monday = $now->setTimezone($this->clock->timezone())->modify('monday this week')->format('Y-m-d');
        $conn = $this->emProvider->getEntityManager()->getConnection();

        // Administrateurs du site qui acceptent le résumé. Le compte du super administrateur de la plateforme, présent sur chaque
        // base, n'est pas un administrateur du site : il ne reçoit pas le résumé de chaque site
        $eligible = [];
        foreach ($conn->fetchAllAssociative('SELECT id, email FROM "user" WHERE roles::text LIKE \'%"ROLE_ADMIN"%\' AND roles::text NOT LIKE \'%"ROLE_SUPER_ADMIN"%\' AND deleted_at IS NULL AND is_verified = true ORDER BY id') as $user) {
            $email = trim((string) $user['email']);
            if ($email === '' || !str_contains($email, '@')) {
                continue;
            }
            $id = (int) $user['id'];
            if ($this->levels->forUser($id)['value'] === NotificationLevel::NONE || !$this->gate->kindEnabled($id, NotificationCatalog::WEEKLY_DIGEST)) {
                continue;
            }
            $eligible[$id] = $email;
        }
        $pending = array_filter($eligible, fn (string $email): bool => !$this->alreadyThisWeek($conn, $email, $monday));
        $held = $this->heldAtEssential($conn);
        $out['held'] = count($held);
        if ($dryRun) {
            $out['hasSomething'] = (bool) $this->digest()['hasSomething'];
            $out['recipients'] = array_values($pending);

            return $out;
        }

        // L'envoi et sa ligne au journal se font hors de toute transaction : la ligne `weekly_digest` est la preuve du « jamais deux
        // fois la même semaine », elle ne doit pas être annulée par une erreur qui suivrait
        $delivered = false;
        if ($pending !== []) {
            $digest = $this->digest();
            $out['hasSomething'] = (bool) $digest['hasSomething'];
            foreach ($pending as $id => $email) {
                // un administrateur ne lit pas, dans le résumé, les titres d'un type qu'il a coupé dans « Avancé »
                $titles = [];
                foreach ($held as $row) {
                    if ($this->gate->kindEnabled($id, $row['kind'])) {
                        $titles[$row['title']] = true;
                    }
                }
                $titles = array_keys($titles);
                if (!$digest['hasSomething'] && $titles === [] && !$always) {
                    continue;
                }
                if ($this->send($email, $digest, $titles)) {
                    try {
                        $this->gate->record(NotificationCatalog::WEEKLY_DIGEST, $email, $id, NotificationGate::STATUS_SENT, $this->subject($digest), [], $day, null);
                    } catch (\Throwable $e) {
                        $this->logger->error('Notifications : résumé de la semaine parti mais non écrit au journal (' . $e::class . ').');
                    }
                    $delivered = true;
                    ++$out['emails'];
                    $out['recipients'][] = $email;
                }
            }
        }
        if ($held === []) {
            return $out;
        }
        // Les retenus : partis dès qu'un administrateur a reçu le résumé ; gardés si personne ne peut le recevoir ; sinon ils attendent
        $ids = implode(',', array_map('intval', array_column($held, 'id')));
        try {
            if ($delivered) {
                $out['released'] = (int) $conn->executeStatement("UPDATE notification_log SET status = 'sent', released_at = NOW() WHERE status = 'held' AND id IN ($ids)");
            } elseif ($eligible === []) {
                $out['kept'] = (int) $conn->executeStatement("UPDATE notification_log SET status = 'kept', released_at = NOW() WHERE status = 'held' AND id IN ($ids)");
            }
        } catch (\Throwable $e) {
            $this->logger->error('Notifications : retenus du résumé de la semaine non mis à jour (' . $e::class . ').', ['exception' => $e]);
        }

        return $out;
    }

    /**
     * Le point de la semaine du site courant (sans destinataire).
     *
     * @return array<string, mixed>
     */
    public function digest(): array
    {
        $em = $this->emProvider->getEntityManager();
        $code = (string) $this->tenants->getCurrentTenantCode();
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC']);
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
            'siteCode' => $code, 'siteName' => (string) ($entreprise?->getName() ?: $code), 'firstname' => null,
            'blocking' => array_map(fn ($s) => $s['title'], array_slice($blocking, 0, 8)), 'blockingTodo' => count($blocking), 'improvements' => count($improvements), 'percent' => $readiness['percent'],
            'ordersTodo' => (int) $todo['counts']['total'], 'orders' => $todo['counts'], 'problems' => count($problems), 'problemList' => array_map(fn ($c) => sprintf('%s (%d)', $c['title'], $c['count']), array_slice($problems, 0, 6)),
            'creditsRemaining' => (int) $credits['remaining'], 'creditsMonthly' => (int) $credits['monthly'], 'lowCredits' => $lowCredits, 'posAlerts' => $alerts,
            'hasSomething' => $blocking !== [] || (int) $todo['counts']['total'] > 0 || $problems !== [] || $lowCredits || $alerts > 0,
            'adminUrl' => 'https://backend-strapi.online/admin',
        ];
    }

    /**
     * Les lignes retenues dont le destinataire est au niveau « Essentiel » : celles que le passage horaire ne sort jamais.
     *
     * @return list<array{id: int, kind: string, title: string}>
     */
    private function heldAtEssential(\Doctrine\DBAL\Connection $conn): array
    {
        $rows = $conn->fetchAllAssociative("SELECT id, recipient, kind, title FROM notification_log WHERE channel = 'email' AND status = 'held' AND recipient IS NOT NULL ORDER BY id");
        $levels = [];
        $held = [];
        foreach ($rows as $row) {
            $recipient = (string) $row['recipient'];
            $levels[$recipient] ??= $this->levels->forAddress($recipient)['level'];
            if ($levels[$recipient] === NotificationLevel::ESSENTIAL) {
                $held[] = ['id' => (int) $row['id'], 'kind' => (string) $row['kind'], 'title' => (string) $row['title']];
            }
        }

        return $held;
    }

    private function alreadyThisWeek(\Doctrine\DBAL\Connection $conn, string $email, string $monday): bool
    {
        return $conn->fetchOne(
            "SELECT 1 FROM notification_log WHERE channel = 'email' AND kind = ? AND recipient = ? AND status = 'sent' AND store_day >= ? LIMIT 1",
            [NotificationCatalog::WEEKLY_DIGEST, mb_strtolower(trim($email)), $monday]
        ) !== false;
    }

    /**
     * @param array<string, mixed> $digest
     * @param list<string> $titles
     */
    private function send(string $email, array $digest, array $titles): bool
    {
        $language = $this->locale->language(null);
        $t = fn (string $key, array $params = []) => $this->translator->trans('weekly_digest.' . $key, $params, 'emails', $language);
        $more = max(0, count($titles) - self::MAX_LINES);
        $context = $digest + [
            'to' => $email, 'heldLines' => array_slice($titles, 0, self::MAX_LINES), 'heldMore' => $more,
            'heldTitle' => $t('held_title'), 'heldMoreLine' => $t('held_more', ['%count%' => $more]), 'heldWhy' => $t('held_why'),
        ];
        $code = (string) $digest['siteCode'];
        try {
            $host = $code !== '' ? (string) ($this->hosts->describe($code)['host'] ?? '') : '';
        } catch (\Throwable) {
            $host = '';
        }
        try {
            // par la plateforme (MAILER_DSN) ; sinon par l'expéditeur du site lui-même
            return $this->mailer->sendPlatformEmail($email, $this->subject($digest), self::TEMPLATE, $context)
                || $this->mailer->sendTemplatedEmail($email, $this->subject($digest), self::TEMPLATE, $context, 'fr', $host !== '' ? $host : 'backend-strapi.online');
        } catch (\Throwable $e) {
            $this->logger->error('Notifications : résumé de la semaine non envoyé (' . $e::class . ').');

            return false;
        }
    }

    /** @param array<string, mixed> $digest */
    private function subject(array $digest): string
    {
        return sprintf('%s : votre point de la semaine', $digest['siteName']);
    }
}
