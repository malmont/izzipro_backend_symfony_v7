<?php

namespace App\Services\Notification;

use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;

/**
 * Le seul point de passage des courriels urgents et de suivi (lot NOTIF-1, `docs/notifications-courriels.md`) : il décide « partir,
 * retenir ou ne pas partir » d'après le niveau du destinataire, et tient le journal (`notification_log`, `channel = 'email'`).
 *
 * | Niveau    | Urgent              | Suivi                                                                                   |
 * |-----------|---------------------|-----------------------------------------------------------------------------------------|
 * | none      | ne part pas         | ne part pas                                                                             |
 * | essential | part tout de suite  | retenu pour le résumé de la semaine                                                     |
 * | normal    | part tout de suite  | le premier du jour et du sujet part (jamais avant 8 h du magasin), les suivants retenus |
 * | all       | part tout de suite  | chaque événement part (jamais avant 8 h du magasin)                                     |
 *
 * Plafond : `NotificationLevel::DAILY_CAP` courriels de notification par jour du magasin et par destinataire ; au-delà, un courriel de
 * suivi est retenu ; un urgent compte dans le plafond mais n'est jamais retenu. Un type coupé dans « Avancé » ne part à aucun niveau.
 * Un courriel indispensable ou interne ne passe pas par ici (`deliver` l'envoie tel quel, sans rien compter ni écrire).
 *
 * Un courriel retenu n'est jamais perdu : il est écrit au journal avec l'état `held`, lisible dans l'application, et sort dans le
 * courriel suivant du même sujet (`NotificationSummaryService`).
 */
class NotificationGate
{
    public const SEND = 'send';
    public const HOLD = 'hold';
    public const DROP = 'drop';

    public const STATUS_SENT = 'sent';
    public const STATUS_HELD = 'held';
    public const STATUS_KEPT = 'kept';
    public const STATUS_FAILED = 'failed';

    // Raison d'une retenue, gardée avec la ligne : elle dit quand le courriel pourra sortir
    public const HELD_WEEKLY = 'weekly';             // niveau « Essentiel » : résumé de la semaine
    public const HELD_BEFORE_HOURS = 'before_hours'; // avant 8 h du magasin : sort le jour même, à partir de 8 h
    public const HELD_TOPIC_DONE = 'topic_done';     // le sujet a déjà eu son courriel du jour : sort le lendemain
    public const HELD_DAILY_CAP = 'daily_cap';       // plafond du jour atteint : sort le lendemain
    public const HELD_WITH_BACKLOG = 'with_backlog'; // des retenus plus anciens attendent : tout sort ensemble, au prochain passage
    public const HELD_SEND_FAILED = 'send_failed';   // l'envoi a échoué alors que d'autres destinataires étaient servis : sort au prochain passage

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly NotificationLevelResolver $levels,
        private readonly StoreClock $clock,
        private readonly int $dailyCap = NotificationLevel::DAILY_CAP,
        private readonly int $fromHour = NotificationLevel::FROM_HOUR,
        private readonly ?\Psr\Log\LoggerInterface $logger = null,
    ) {
    }

    /** Plafond du jour et heure du magasin avant laquelle rien ne part : les valeurs de `NotificationLevel`, sauf dans les tests */
    public function dailyCap(): int
    {
        return $this->dailyCap;
    }

    public function fromHour(): int
    {
        return $this->fromHour;
    }

    /**
     * Décide du sort d'un courriel urgent ou de suivi pour ce destinataire, sans rien écrire. `$ignoreHours` : sans la règle des 8 h
     * (un lancement à la main, demandé par quelqu'un qui attend le courriel).
     *
     * @return array{decision: string, reason: ?string, level: string, userId: ?int, day: string}
     */
    public function decide(string $kind, string $email, ?\DateTimeImmutable $now = null, bool $ignoreHours = false): array
    {
        $family = NotificationCatalog::family($kind);
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $who = $this->levels->forAddress($email);
        $out = ['decision' => self::SEND, 'reason' => null, 'level' => $who['level'], 'userId' => $who['userId'], 'day' => $this->clock->day($now)];
        if (!in_array($family, [NotificationCatalog::URGENT, NotificationCatalog::FOLLOWUP], true)) {
            return $out;
        }
        if ($who['userId'] !== null && !$this->kindEnabled($who['userId'], $kind)) {
            return ['decision' => self::DROP, 'reason' => 'kind_off'] + $out;
        }
        if ($who['level'] === NotificationLevel::NONE) {
            return ['decision' => self::DROP, 'reason' => 'level_none'] + $out;
        }
        if ($family === NotificationCatalog::URGENT) {
            return $out;
        }
        if ($who['level'] === NotificationLevel::ESSENTIAL) {
            return ['decision' => self::HOLD, 'reason' => self::HELD_WEEKLY] + $out;
        }
        if (!$ignoreHours && (int) $now->setTimezone($this->clock->timezone())->format('G') < $this->fromHour) {
            return ['decision' => self::HOLD, 'reason' => self::HELD_BEFORE_HOURS] + $out;
        }
        $topic = NotificationCatalog::topic($kind);
        if ($this->sentToday($email, $out['day']) >= $this->dailyCap) {
            return ['decision' => self::HOLD, 'reason' => self::HELD_DAILY_CAP] + $out;
        }
        if ($topic !== null && $this->heldCount($email, $topic) > 0) {
            return ['decision' => self::HOLD, 'reason' => self::HELD_WITH_BACKLOG] + $out;
        }
        if ($who['level'] === NotificationLevel::NORMAL && $topic !== null && $this->sentToday($email, $out['day'], $topic) > 0) {
            return ['decision' => self::HOLD, 'reason' => self::HELD_TOPIC_DONE] + $out;
        }

        return $out;
    }

    /**
     * Envoie, retient ou écarte un courriel, et l'écrit au journal. `$send` fait l'envoi réel et rend vrai s'il est parti.
     * Un courriel indispensable ou interne est envoyé tel quel : ni compté, ni écrit.
     *
     * @param array{screen?: string, params?: array<string, mixed>} $action l'écran du web ou de l'application que ce courriel annonce
     * @return string `sent`, `held`, `failed`, ou `drop` quand rien ne part ni n'est gardé
     */
    public function deliver(string $kind, string $email, string $title, array $action, callable $send, ?\DateTimeImmutable $now = null): string
    {
        $family = NotificationCatalog::family($kind);
        if (!in_array($family, [NotificationCatalog::URGENT, NotificationCatalog::FOLLOWUP], true)) {
            return $send() ? self::STATUS_SENT : self::STATUS_FAILED;
        }
        try {
            $verdict = $this->decide($kind, $email, $now);
            if ($verdict['decision'] === self::DROP) {
                return self::DROP;
            }
            if ($verdict['decision'] === self::HOLD) {
                $this->record($kind, $email, $verdict['userId'], self::STATUS_HELD, $title, $action, $verdict['day'], $verdict['reason']);

                return self::STATUS_HELD;
            }
        } catch (\Throwable $e) {
            // La décision ou l'écriture de la retenue est en panne (base, colonne absente…) : le courriel PART, comme avant les niveaux.
            // Un courriel de trop vaut mieux qu'un courriel perdu ; seule la classe de l'erreur va au journal du serveur
            $this->logger?->error('Notifications : décision d\'envoi en panne (' . $e::class . '), le courriel part sans elle.');

            return $send() ? self::STATUS_SENT : self::STATUS_FAILED;
        }
        $status = $send() ? self::STATUS_SENT : self::STATUS_FAILED;
        try {
            $this->record($kind, $email, $verdict['userId'], $status, $title, $action, $verdict['day'], null);
        } catch (\Throwable $e) {
            $this->logger?->error('Notifications : courriel ' . $status . ' non écrit au journal (' . $e::class . ').');
        }

        return $status;
    }

    /** Écrit un courriel au journal des notifications ; rend son identifiant */
    public function record(string $kind, string $email, ?int $userId, string $status, string $title, array $action, string $day, ?string $reason, ?string $topic = null): int
    {
        return (int) $this->emProvider->getEntityManager()->getConnection()->fetchOne(
            "INSERT INTO notification_log (user_id, kind, title, body, action, subject, status, devices, error, store_day, sent_at, channel, recipient, topic)"
            . " VALUES (:u, :k, :t, '', :a, :s, :st, 0, NULL, :day, NOW(), 'email', :r, :topic) RETURNING id",
            ['u' => $userId ?? 0, 'k' => $kind, 't' => mb_substr($title, 0, 255), 'a' => json_encode($action ?: new \stdClass(), \JSON_UNESCAPED_UNICODE),
                's' => json_encode($reason !== null ? ['reason' => $reason] : new \stdClass()), 'st' => $status, 'day' => $day, 'r' => mb_strtolower(trim($email)), 'topic' => $topic ?? NotificationCatalog::topic($kind)]
        );
    }

    /**
     * Courriels de notification partis aujourd'hui à cette adresse (tous sujets, ou un seul) : ce que compte le plafond. Une ligne
     * retenue puis sortie dans un résumé (`released_at`) n'est pas un courriel de plus : c'est la ligne du résumé qui compte.
     */
    public function sentToday(string $email, string $day, ?string $topic = null): int
    {
        return (int) $this->emProvider->getEntityManager()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM notification_log WHERE channel = 'email' AND recipient = ? AND store_day = ? AND status = 'sent' AND released_at IS NULL" . ($topic !== null ? ' AND topic = ?' : ''),
            $topic !== null ? [mb_strtolower(trim($email)), $day, $topic] : [mb_strtolower(trim($email)), $day]
        );
    }

    /** Courriels retenus en attente pour cette adresse (tous sujets, ou un seul) */
    public function heldCount(string $email, ?string $topic = null): int
    {
        return (int) $this->emProvider->getEntityManager()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM notification_log WHERE channel = 'email' AND recipient = ? AND status = 'held'" . ($topic !== null ? ' AND topic = ?' : ''),
            $topic !== null ? [mb_strtolower(trim($email)), $topic] : [mb_strtolower(trim($email))]
        );
    }

    /** Ce compte a-t-il gardé ce type actif dans « Avancé » ? (actif sans réglage) */
    public function kindEnabled(int $userId, string $kind): bool
    {
        // `enabled::int` : sans ligne, fetchOne rend false ; un booléen « faux » lu tel quel se confondrait avec « pas de ligne »
        $enabled = $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT enabled::int FROM notification_preference WHERE user_id = ? AND kind = ?', [$userId, $kind]);

        return $enabled === false || (int) $enabled === 1;
    }
}
