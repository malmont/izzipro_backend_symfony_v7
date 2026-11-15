<?php

namespace App\Services\Notification;

use App\Services\CaisseService\StoreClock;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\Site\AdminSpaceUrl;
use App\Services\Site\SiteName;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sortie des courriels de suivi retenus (lot NOTIF-1, `docs/notifications-courriels.md`) : un passage, à partir de 8 h du magasin, réunit
 * par destinataire et par sujet ce qui est « dû » en UN courriel, puis marque ces lignes `sent`. Appelé par le passage horaire des
 * notifications de la gestion (`app:gestion:notifications:envoyer`).
 *
 * Le courriel de sortie respecte les mêmes règles que les autres : plafond du jour, et un seul par sujet et par jour au niveau « Normal ».
 * Ce sont elles qui disent quand un retenu sort : retenu avant 8 h ou derrière des retenus plus anciens, il sort au premier passage ;
 * retenu parce que le sujet avait eu son courriel ou que le plafond était atteint, il attend le lendemain. Les groupes sortent du plus
 * ancien au plus récent. Limite connue : trois courriels urgents avant le premier passage du jour épuisent le plafond de ce jour-là.
 * Les lignes parties, gardées ou en échec depuis plus de `RETENTION_DAYS` jours sont effacées au même passage.
 *
 * Niveau « Essentiel » : rien ne sort ici, les retenus attendent le résumé de la semaine (lot NOTIF-2). Niveau « Aucune », ou type coupé
 * dans « Avancé » depuis la retenue : la ligne passe à `kept` (gardée, jamais envoyée, lisible dans l'application). Rien n'est supprimé,
 * rien ne part deux fois : les lignes sont verrouillées le temps de l'envoi, et un second passage ne les retrouve plus `held`.
 */
class NotificationSummaryService
{
    /** Lignes écrites dans le courriel ; au-delà, « et N autres » */
    public const MAX_LINES = 20;
    /** Le journal des courriels garde des adresses et des titres : au-delà, les lignes qui n'attendent plus rien sont effacées */
    public const RETENTION_DAYS = 90;
    private const TEMPLATE = 'emails/notification_summary.html.twig';
    /** Sujet → écran de l'espace d'administration du web (`AdminSpaceLinks`) ; sans écran connu, le courriel part sans lien */
    private const SCREENS = [
        NotificationCatalog::TOPIC_LOSS => ['pertes.constats', ['isNew' => true]],
        NotificationCatalog::TOPIC_SUPPORT => ['assistance', []],
    ];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly NotificationGate $gate,
        private readonly NotificationLevelResolver $levels,
        private readonly StoreClock $clock,
        private readonly EmailSenderService $mailer,
        private readonly EmailLocale $locale,
        private readonly TranslatorInterface $translator,
        private readonly AdminSpaceUrl $adminSpace,
        private readonly SiteName $siteName,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantPublicHostResolver $hosts,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{ran: bool, emails: int, released: int, kept: int}
     */
    public function releaseDue(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $out = ['ran' => false, 'emails' => 0, 'released' => 0, 'kept' => 0];
        if ((int) $now->setTimezone($this->clock->timezone())->format('G') < $this->gate->fromHour()) {
            return $out;
        }
        $out['ran'] = true;
        $day = $this->clock->day($now);
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $conn->executeStatement("DELETE FROM notification_log WHERE channel = 'email' AND status <> 'held' AND sent_at < NOW() - INTERVAL '" . self::RETENTION_DAYS . " days'");
        // le plus ancien retenu d'abord : quand le plafond du jour arrête la sortie, ce n'est jamais toujours le même sujet qui attend
        $groups = $conn->fetchAllAssociative("SELECT recipient, topic, MIN(id) AS first FROM notification_log WHERE channel = 'email' AND status = 'held' AND recipient IS NOT NULL AND topic IS NOT NULL GROUP BY recipient, topic ORDER BY first");
        foreach ($groups as $group) {
            $recipient = (string) $group['recipient'];
            $topic = (string) $group['topic'];
            $who = $this->levels->forAddress($recipient);
            if ($who['level'] === NotificationLevel::ESSENTIAL) {
                continue; // résumé de la semaine
            }
            $conn->beginTransaction();
            try {
                $rows = $conn->fetchAllAssociative(
                    "SELECT id, kind, title, store_day, subject FROM notification_log WHERE channel = 'email' AND status = 'held' AND recipient = ? AND topic = ? ORDER BY id FOR UPDATE SKIP LOCKED",
                    [$recipient, $topic]
                );
                $keep = [];
                $send = [];
                foreach ($rows as $row) {
                    $off = $who['level'] === NotificationLevel::NONE || ($who['userId'] !== null && !$this->gate->kindEnabled($who['userId'], (string) $row['kind']));
                    $off ? $keep[] = (int) $row['id'] : $send[] = $row;
                }
                if ($keep !== []) {
                    $out['kept'] += (int) $conn->executeStatement("UPDATE notification_log SET status = 'kept', released_at = NOW() WHERE status = 'held' AND id IN (" . implode(',', $keep) . ')');
                }
                if ($send !== [] && $this->allowed($recipient, $topic, $who['level'], $day)
                    && $this->send($recipient, $topic, array_column($send, 'title'))) {
                    $ids = array_map('intval', array_column($send, 'id'));
                    $out['released'] += (int) $conn->executeStatement("UPDATE notification_log SET status = 'sent', released_at = NOW() WHERE status = 'held' AND id IN (" . implode(',', $ids) . ')');
                    $this->gate->record(NotificationCatalog::FOLLOWUP_SUMMARY, $recipient, $who['userId'], NotificationGate::STATUS_SENT, $this->subject($topic, count($send)), [], $day, null, $topic);
                    ++$out['emails'];
                }
                $conn->commit();
            } catch (\Throwable $e) {
                $conn->rollBack();
                $this->logger->error('Notifications : sortie des courriels retenus en échec (' . $e::class . ').', ['exception' => $e]);
            }
        }

        return $out;
    }

    /** Le courriel de sortie compte comme les autres : plafond du jour, et un seul par sujet et par jour au niveau « Normal » */
    private function allowed(string $recipient, string $topic, string $level, string $day): bool
    {
        if ($this->gate->sentToday($recipient, $day) >= $this->gate->dailyCap()) {
            return false;
        }

        return $level !== NotificationLevel::NORMAL || $this->gate->sentToday($recipient, $day, $topic) === 0;
    }

    /** @param list<string> $titles */
    private function send(string $recipient, string $topic, array $titles): bool
    {
        $language = $this->locale->language(null);
        $code = (string) $this->tenants->getCurrentTenantCode();
        $host = $code !== '' ? (string) ($this->hosts->describe($code)['host'] ?? '') : '';
        $t = fn (string $key, array $params = []) => $this->translator->trans('notification_summary.' . $key, $params, 'emails', $language);
        [$screen, $params] = self::SCREENS[$topic] ?? [null, []];
        $more = max(0, count($titles) - self::MAX_LINES);
        try {
            return $this->mailer->sendTemplatedEmail($recipient, $this->subject($topic, count($titles)), self::TEMPLATE, [
                'title' => $t('title'), 'intro' => $t('intro'), 'lines' => array_slice($titles, 0, self::MAX_LINES), 'more' => $more, 'moreLine' => $t('more', ['%count%' => $more]),
                'link' => $screen !== null ? $this->adminSpace->to($screen, $params) : null, 'button' => $t('button'), 'why' => $t('why'), 'siteLine' => $this->siteName->name(),
            ], $language, $host);
        } catch (\Throwable $e) {
            $this->logger->error('Notifications : courriel des retenus en échec (' . $e::class . ').');

            return false;
        }
    }

    private function subject(string $topic, int $count): string
    {
        return $this->translator->trans('notification_summary.subject.' . $topic, ['%site%' => $this->siteName->name(), '%count%' => $count], 'emails', $this->locale->language(null));
    }
}
