<?php

namespace App\Gestion\Aid;

use App\Services\CaisseService\StoreClock;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\Notification\NotificationCatalog;
use App\Services\Notification\NotificationGate;
use App\Services\Notification\NotificationLevel;
use App\Services\Site\AdminSpaceUrl;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Rappel des aides (lot FIS-2, 21/11/2026, `docs/aides-gestion.md` § 6) : une condition encore « à faire » arrivée à échéance, ou une aide
 * reçue sans justificatif. Un courriel de SUIVI (sujet « gestion ») aux administrateurs du site, qui suit leur niveau et le plafond du jour
 * (NotificationGate, lot NOTIF) ; jamais avant 8 h du magasin, au plus un par passage. Chaque élément n'est annoncé qu'UNE fois : il est
 * réservé (`gestion_aid_reminder`) avant l'envoi, et rendu si rien n'est parti ni n'a été retenu (on réessaiera au passage suivant).
 * Lancé par le passage horaire des notifications de la gestion, pour un site dont le module de gestion est activé.
 */
class AidReminderService
{
    public const TEMPLATE = 'emails/gestion_aid_reminder.html.twig';
    /** Au plus ce nombre d'éléments écrits dans le courriel ; les autres sont comptés (« et N autres ») */
    public const MAX_ROWS = 10;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly GestionAidService $aids,
        private readonly NotificationGate $gate,
        private readonly StoreClock $clock,
        private readonly EmailSenderService $mailer,
        private readonly EmailLocale $locale,
        private readonly TranslatorInterface $translator,
        private readonly AdminSpaceUrl $links,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantPublicHostResolver $hosts,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param bool $anyHour sans attendre 8 h du magasin (lancement à la main)
     * @return array{status: string, sent: int, held: int} status : sent | held | nothing_due | too_early | no_recipient | already_sent | not_delivered
     */
    public function sendIfDue(?\DateTimeImmutable $now = null, bool $anyHour = false): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $day = $this->clock->day($now);
        if (!$anyHour && (int) $now->setTimezone($this->clock->timezone())->format('G') < NotificationLevel::FROM_HOUR) {
            return ['status' => 'too_early', 'sent' => 0, 'held' => 0];
        }
        $items = $this->aids->dueReminders($day);
        if ($items === []) {
            return ['status' => 'nothing_due', 'sent' => 0, 'held' => 0];
        }
        $verdicts = [];
        foreach ($this->recipients() as $to) {
            $verdict = $this->gate->decide(NotificationCatalog::AID_REMINDER, $to, $now, $anyHour);
            if ($verdict['decision'] !== NotificationGate::DROP) {
                $verdicts[$to] = $verdict;
            }
        }
        if ($verdicts === []) {
            return ['status' => 'no_recipient', 'sent' => 0, 'held' => 0];
        }
        // tout ce qui peut échouer est préparé AVANT la réservation : une exception ici ne réserve rien
        $language = $this->locale->language(null);
        $t = fn (string $key, array $params = []) => $this->translator->trans('aid_reminder.' . $key, $params, 'emails', $language);
        $siteName = (string) ($this->conn()->fetchOne('SELECT name FROM entreprise ORDER BY id LIMIT 1') ?: '');
        $host = $this->host();
        // Réserver AVANT d'envoyer : de deux passages qui se chevauchent, un seul annonce chaque élément
        $claimed = $this->aids->claimReminders($items, $day);
        if ($claimed === []) {
            return ['status' => 'already_sent', 'sent' => 0, 'held' => 0];
        }
        $keys = array_flip(array_map(static fn (array $i): string => $i['aidId'] . '|' . $i['item'], $claimed));
        $mine = array_values(array_filter($items, static fn (array $i): bool => isset($keys[$i['aidId'] . '|' . $i['item']])));
        $rows = [];
        foreach (array_slice($mine, 0, self::MAX_ROWS) as $i) {
            $rows[] = $i['kind'] === 'condition'
                ? $t('row_condition', ['%aid%' => $i['label'], '%condition%' => (string) $i['condition'], '%date%' => (string) $i['dueOn']])
                : $t('row_documents', ['%aid%' => $i['label']]);
        }
        $params = ['%count%' => count($mine), '%site%' => $siteName];
        $subject = $this->mailer->subject('aid_reminder.subject', $params, $language);
        $action = count(array_unique(array_column($mine, 'aidId'))) === 1 ? ['screen' => 'aides', 'params' => ['id' => $mine[0]['aidId']]] : ['screen' => 'aides'];
        $context = [
            'title' => $t('title'), 'greeting' => $t('greeting'), 'message' => $t('message', $params), 'rows' => $rows,
            'more' => count($mine) > self::MAX_ROWS ? $t('more', ['%count%' => count($mine) - self::MAX_ROWS]) : null,
            'nextStep' => $t('next'), 'notice' => $t('notice'), 'siteLine' => $siteName,
            'linkUrl' => $this->links->to('aides', $action['params'] ?? []), 'linkLabel' => $t('link'),
            'locale' => $language, 'intlLocale' => $language === 'en' ? 'en_CA' : 'fr_CA',
        ];
        $sent = $held = 0;
        $failed = [];
        try {
            foreach ($verdicts as $to => $verdict) {
                try {
                    if ($verdict['decision'] === NotificationGate::HOLD) {
                        $this->gate->record(NotificationCatalog::AID_REMINDER, $to, $verdict['userId'], NotificationGate::STATUS_HELD, $subject, $action, $verdict['day'], $verdict['reason']);
                        ++$held;

                        continue;
                    }
                    if ($this->mailer->sendTemplatedEmail($to, $subject, self::TEMPLATE, $context, $language, $host)) {
                        ++$sent;
                        $this->record($to, $verdict, NotificationGate::STATUS_SENT, $subject, $action, null);
                    } else {
                        $failed[$to] = $verdict;
                    }
                } catch (\Exception $e) {
                    // jamais le message de l'exception : il peut porter l'adresse du destinataire
                    $this->logger->error('Aides de la gestion : courriel de rappel, un envoi en échec (' . $e::class . ').');
                    $failed[$to] = $verdict;
                }
            }
            // un destinataire servi ou retenu : l'élément reste annoncé ; celui dont l'envoi a échoué sortira avec les retenus
            if ($sent + $held > 0) {
                foreach ($failed as $to => $verdict) {
                    $this->record($to, $verdict, NotificationGate::STATUS_HELD, $subject, $action, NotificationGate::HELD_SEND_FAILED);
                }
            }
        } finally {
            if ($sent === 0 && $held === 0) {
                $this->aids->releaseReminders($claimed);
            }
        }
        if ($sent + $held === 0) {
            return ['status' => 'not_delivered', 'sent' => 0, 'held' => 0];
        }

        return ['status' => $sent > 0 ? 'sent' : 'held', 'sent' => $sent, 'held' => $held];
    }

    /** @return list<string> les administrateurs du site (pas le compte de la plateforme), vérifiés et actifs */
    private function recipients(): array
    {
        $out = [];
        foreach ($this->conn()->fetchAllAssociative('SELECT email FROM "user" WHERE roles::text LIKE \'%"ROLE_ADMIN"%\' AND roles::text NOT LIKE \'%"ROLE_SUPER_ADMIN"%\' AND deleted_at IS NULL AND is_verified = true ORDER BY id') as $u) {
            $email = trim((string) $u['email']);
            if ($email !== '' && filter_var($email, \FILTER_VALIDATE_EMAIL) !== false) {
                $out[] = $email;
            }
        }

        return $out;
    }

    private function record(string $to, array $verdict, string $status, string $subject, array $action, ?string $reason): void
    {
        try {
            $this->gate->record(NotificationCatalog::AID_REMINDER, $to, $verdict['userId'], $status, $subject, $action, $verdict['day'], $reason);
        } catch (\Throwable $e) {
            $this->logger->error('Aides de la gestion : rappel non écrit au journal des notifications (' . $e::class . ').');
        }
    }

    /** Hôte public du site : l'envoi commun en fait l'adresse du logo */
    private function host(): string
    {
        $code = (string) $this->tenants->getCurrentTenantCode();

        return $code !== '' ? (string) ($this->hosts->describe($code)['host'] ?? $code) : 'localhost';
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
