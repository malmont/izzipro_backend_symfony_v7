<?php

namespace App\Gestion\Services;

use App\Services\Notification\NotificationLevel;
use App\Gestion\Entity\GestionDocument;
use App\Entity\NotificationLog;
use App\Entity\PushDevice;
use App\Repository\NotificationLogRepository;
use App\Repository\PushDeviceRepository;
use App\Services\CaisseService\StoreClock;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\NotificationService\PushSenderInterface;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Notifications sur téléphone de l'application de gestion (lot N1, 11/11/2026, `docs/notifications-gestion.md`) : seulement l'urgent,
 * une par jour du magasin au plus et par gérant, chaque type coupable par gérant ; toucher la notification ouvre l'écran de la vignette
 * correspondante. Stock bas : passage quotidien à 10 h du magasin (`app:gestion:notifications:envoyer`, timer horaire), variantes à
 * moins de 7 jours de couverture, rien si toutes ont été notifiées dans les 7 derniers jours. Facture en double : à la fin de la
 * lecture, à celui qui l'a envoyée, entre 8 h et 20 h du magasin.
 */
class GestionNotificationService
{
    public const PLATFORM = 'admin';
    public const LOW_STOCK = 'low_stock';
    public const DUPLICATE_INVOICE = 'duplicate_invoice';
    /** type → disponible (cash_critical et trip_budget arrivent avec B1 ; coupables dès maintenant) */
    public const TRIP_BUDGET = 'trip_budget';
    public const CASH_CRITICAL = 'cash_critical';
    /** Notifications de téléphone urgentes : les seules qui partent au niveau « Essentiel » (lot NOTIF-2) */
    public const PUSH_URGENT = [self::CASH_CRITICAL];
    /** Prévention des pertes (lot L1-b) : courriel de résumé du jour, « N nouveaux constats, X en jeu ». Envoyé par le module, par COURRIEL :
     *  il n'entre pas dans la règle « une notification par jour » du téléphone. Depuis NOTIF-1 il suit le niveau des courriels de suivi
     *  et s'écrit au journal des notifications sur le canal `email` (`docs/notifications-courriels.md`). */
    public const LOSS_DIGEST = 'loss_digest';
    /** Point de la semaine (lot NOTIF) : courriel de suivi qui se coupe ici, indépendamment du niveau */
    public const WEEKLY_DIGEST = 'weekly_digest';
    public const KINDS = [self::LOW_STOCK => true, self::DUPLICATE_INVOICE => true, self::CASH_CRITICAL => true, self::TRIP_BUDGET => true, self::LOSS_DIGEST => true, self::WEEKLY_DIGEST => true]; // trip_budget : lot B1a ; cash_critical : lot B1b-1
    /** Canal d'un type : `push` (téléphone) par défaut ; `email` pour ceux listés ici */
    public const EMAIL_KINDS = [self::LOSS_DIGEST, self::WEEKLY_DIGEST];
    /** Lot NOTIF-1, type → [famille, sujet] : `urgent` part dès le niveau « Essentiel », `followup` (suivi) est regroupé par les niveaux */
    public const FAMILIES = [
        self::LOW_STOCK => ['followup', 'management'], self::DUPLICATE_INVOICE => ['followup', 'management'], self::CASH_CRITICAL => ['urgent', 'management'],
        self::TRIP_BUDGET => ['followup', 'management'], self::LOSS_DIGEST => ['followup', 'loss'], self::WEEKLY_DIGEST => ['followup', null],
    ];
    public const CASH_REPEAT_DAYS = 7;
    public const DAILY_HOUR = 10;
    public const DAY_FROM_HOUR = 8;
    public const DAY_TO_HOUR = 20; // exclu
    public const LOW_STOCK_COVERAGE_DAYS = 7;
    public const LOW_STOCK_REPEAT_DAYS = 7;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PushSenderInterface $sender,
        private readonly HomeTilesService $tiles,
        private readonly StoreClock $clock,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly ?EmailLocale $siteLocale = null,
        private readonly ?GestionTripService $trips = null,
        private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null,
        private readonly ?GestionCashService $cash = null,
        private readonly ?\App\Services\FeatureAccess\ModuleAccessService $modules = null, // lot L1-b : le type loss_digest n'est disponible que si le module est accordé
        private readonly ?\App\Services\Notification\NotificationLevelResolver $levels = null, // lot NOTIF-1 : niveau des courriels de suivi
    ) {
    }

    /**
     * Lot NOTIF-1 : en tête, `level` (le niveau des courriels de suivi qui s'applique à ce compte, son choix, celui du site, le plafond du
     * jour, le nombre de courriels retenus en attente, les quatre choix rédigés) ; chaque type gagne `family` et `subject`.
     *
     * @return array{level?: array<string, mixed>, kinds: list<array{kind: string, label: string, enabled: bool, available: bool, channel: string, family: string, subject: ?string}>}
     */
    public function preferences(int $userId, string $locale): array
    {
        $prefs = $this->log()->preferences($userId);
        $kinds = [];
        foreach (self::KINDS as $kind => $available) {
            if ($kind === self::LOSS_DIGEST) { // disponible seulement si le droit du module Prévention des pertes est accordé au site
                $available = $this->modules !== null && $this->lossPreventionGranted();
            }
            $kinds[] = ['kind' => $kind, 'label' => $this->translator->trans("notifications.kinds.$kind", [], 'gestion', $locale), 'enabled' => $prefs[$kind] ?? true, 'available' => $available,
                'channel' => in_array($kind, self::EMAIL_KINDS, true) ? 'email' : 'push', 'family' => self::FAMILIES[$kind][0], 'subject' => self::FAMILIES[$kind][1]];
        }

        return ($this->levels !== null ? ['level' => $this->level($userId, $locale)] : []) + ['kinds' => $kinds];
    }

    /** @return array<string, mixed> le bloc `level` de l'écran « Notifications » */
    private function level(int $userId, string $locale): array
    {
        $t = fn (string $key): string => $this->translator->trans("notifications.levels.$key", [], 'gestion', $locale);
        $level = $this->levels->forUser($userId);
        $site = $level['siteLevel'];
        $choices = [];
        foreach (\App\Services\Notification\NotificationLevel::LEVELS as $key) {
            $choices[] = ['key' => $key, 'label' => $t("$key.label"), 'description' => $t("$key.description")];
        }

        return [
            'value' => $level['value'], 'source' => $level['source'], 'userLevel' => $level['userLevel'],
            'siteLevel' => $site + ['label' => $t($site['value'] . '.label'), 'reasonLabel' => $t('reasons.' . ($site['reason'] ?? 'manual'))],
            'dailyCap' => \App\Services\Notification\NotificationLevel::DAILY_CAP, 'heldCount' => $this->log()->heldCount($userId),
            'siteChoiceLabel' => $t('site'), 'choices' => $choices,
        ];
    }

    /** Ce gérant a-t-il gardé ce type actif ? (actif par défaut) */
    public function isKindEnabled(int $userId, string $kind): bool
    {
        return $this->log()->preferences($userId)[$kind] ?? true;
    }

    /** Droit du module accordé au site, lu dans les indicateurs de modules (pas le mode « demo » de la plateforme : le droit lui-même) */
    private function lossPreventionGranted(): bool
    {
        return (bool) ($this->modules?->flags()[\App\Services\FeatureAccess\ModuleAccessService::LOSS_PREVENTION] ?? false);
    }

    public function updatePreferences(int $userId, array $body): void
    {
        // Lot NOTIF-1 : `level` (none | essential | normal | all, ou null : comme le site) et `kinds`, ensemble ou séparément
        $hasLevel = array_key_exists('level', $body);
        $kinds = $body['kinds'] ?? ($hasLevel ? [] : null);
        if (!is_array($kinds) || ($kinds !== [] && array_is_list($kinds))) {
            throw new GestionValidationException(['kinds' => 'MUST_BE_OBJECT']);
        }
        $fields = [];
        if ($hasLevel && $body['level'] !== null && !\App\Services\Notification\NotificationLevel::isValid($body['level'])) {
            $fields['level'] = 'INVALID_VALUE';
        }
        foreach ($kinds as $kind => $enabled) {
            if (!array_key_exists((string) $kind, self::KINDS)) {
                $fields["kinds.$kind"] = 'UNKNOWN_KIND';
            } elseif (!is_bool($enabled)) {
                $fields["kinds.$kind"] = 'MUST_BE_BOOLEAN';
            }
        }
        if ($fields !== []) {
            throw new GestionValidationException($fields);
        }
        foreach ($kinds as $kind => $enabled) {
            $this->log()->setPreference($userId, (string) $kind, $enabled);
        }
        if ($hasLevel) {
            $this->emProvider->getEntityManager()->getConnection()->executeStatement('UPDATE "user" SET notification_level = ? WHERE id = ?', [$body['level'], $userId]);
        }
    }

    /** @return list<array<string, mixed>> */
    public function journal(int $userId, int $limit, ?string $channel = null): array
    {
        return $this->log()->journal($userId, $limit, $channel);
    }

    /**
     * Passage quotidien du site courant : rien hors de l'heure prévue (10 h du magasin) sauf `$force`.
     *
     * @return array{ran: bool, notified: int, skipped: int}
     */
    public function runDaily(?\DateTimeImmutable $now = null, bool $force = false): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (!$force && (int) $now->setTimezone($this->clock->timezone())->format('G') !== self::DAILY_HOUR) {
            return ['ran' => false, 'notified' => 0, 'skipped' => 0];
        }
        $notified = 0;
        $skipped = 0;
        $users = $this->devices()->usersWithActiveDevice(self::PLATFORM);
        $localDay = \DateTimeImmutable::createFromInterface($now)->setTimezone($this->clock->timezone())->setTime(0, 0);
        // Trésorerie sous le seuil dans les 14 prochains jours (lot B1b-1) : la plus urgente, en premier ; pas de répétition de la même
        // alerte pendant 7 jours, sauf si le point bas avance ou se creuse
        $cash = $this->cash?->alert(new \DateTimeImmutable($localDay->format('Y-m-d')));
        if ($cash !== null && $cash['status'] !== 'ok') {
            $siteLocale = $this->siteLocale?->language(null) ?? 'fr';
            $subject = ['date' => $cash['low14']['date'], 'amount' => $cash['low14']['amount'], 'status' => $cash['status']];
            foreach ($users as $userId) {
                $repeat = false;
                foreach ($this->log()->subjectsSince($userId, self::CASH_CRITICAL, $now->modify('-' . self::CASH_REPEAT_DAYS . ' days')) as $previous) {
                    if (($previous['date'] ?? null) !== null && $subject['date'] >= $previous['date'] && $subject['amount'] >= (int) ($previous['amount'] ?? PHP_INT_MIN)) {
                        $repeat = true;
                    }
                }
                if ($repeat) {
                    ++$skipped;

                    continue;
                }
                $status = $this->notify($userId, self::CASH_CRITICAL, ['%amount%' => HomeTilesService::money($cash['low14']['amount'], $siteLocale, $this->currency?->code() ?? 'CAD'), '%date%' => HomeTilesService::day($cash['low14']['date'], $siteLocale)],
                    ['screen' => 'tresorerie', 'params' => []], $subject, $now);
                $status !== null ? ++$notified : ++$skipped;
            }
        }
        // Voyage en cours au-delà de son budget (lot B1a) : avant le stock bas, la limite d'une par jour laisse passer la plus urgente
        $trip = $this->trips?->current($localDay);
        $follow = $trip !== null ? $this->trips->follow($trip, $localDay) : null;
        if ($trip !== null && $follow['status'] === 'over') {
            $siteLocale = $this->siteLocale?->language(null) ?? 'fr';
            foreach ($users as $userId) {
                $status = $this->notify($userId, self::TRIP_BUDGET, ['%place%' => $trip->getPlace() ?? $trip->getName(), '%amount%' => HomeTilesService::money($follow['overBy'], $siteLocale, $this->currency?->code() ?? 'CAD')],
                    ['screen' => 'voyages.suivi', 'params' => ['tripId' => (int) $trip->getId()]], ['tripId' => (int) $trip->getId()], $now);
                $status !== null ? ++$notified : ++$skipped;
            }
        }
        $low = $this->tiles->lowStock(self::LOW_STOCK_COVERAGE_DAYS);
        if ($low['all'] === []) {
            return ['ran' => true, 'notified' => $notified, 'skipped' => $skipped];
        }
        foreach ($users as $userId) {
            $since = $now->modify('-' . self::LOW_STOCK_REPEAT_DAYS . ' days');
            $already = [];
            foreach ($this->log()->subjectsSince($userId, self::LOW_STOCK, $since) as $subject) {
                foreach ((array) ($subject['variantIds'] ?? []) as $v) {
                    $already[(int) $v] = true;
                }
            }
            if (array_diff($low['all'], array_keys($already)) === []) {
                ++$skipped; // déjà dit cette semaine : pas de rappel quotidien de la même chose

                continue;
            }
            $status = $this->notify($userId, self::LOW_STOCK, ['%count%' => count($low['all'])], ['screen' => 'stock.low', 'params' => ['variantIds' => $low['all']]], ['variantIds' => $low['all']], $now);
            $status !== null ? ++$notified : ++$skipped;
        }

        return ['ran' => true, 'notified' => $notified, 'skipped' => $skipped];
    }

    /** Facture reconnue comme déjà saisie, à la fin de sa lecture : à celui qui l'a envoyée, entre 8 h et 20 h du magasin */
    public function duplicateInvoice(GestionDocument $document, array $extraction, ?\DateTimeImmutable $now = null): ?string
    {
        if ($document->getType() !== GestionDocument::TYPE_FACTURE || empty($extraction['duplicate'])) {
            return null;
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $hour = (int) $now->setTimezone($this->clock->timezone())->format('G');
        if ($hour < self::DAY_FROM_HOUR || $hour >= self::DAY_TO_HOUR || $document->getCreatedBy() === null) {
            return null;
        }
        $userId = $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT id FROM "user" WHERE email = ?', [$document->getCreatedBy()]);
        if ($userId === false) {
            return null;
        }
        $supplier = trim((string) ($extraction['supplier']['name'] ?? ''));

        return $this->notify((int) $userId, self::DUPLICATE_INVOICE, ['%supplier%' => $supplier !== '' ? $supplier : '—'], ['screen' => 'documents.review', 'params' => ['documentId' => (int) $document->getId()]], ['documentId' => (int) $document->getId()], $now);
    }

    /**
     * Envoie (ou note) une notification à un utilisateur : rien si le type est coupé, si la limite du jour est atteinte ou s'il n'est plus
     * administrateur. Rend le statut journalisé, ou null si rien n'est parti.
     *
     * @param array<string, mixed> $action { screen, params }
     */
    public function notify(int $userId, string $kind, array $params, array $action, array $subject, ?\DateTimeImmutable $now = null): ?string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $day = $this->clock->day($now);
        if (!($this->log()->preferences($userId)[$kind] ?? true) || !$this->isAdmin($userId) || !$this->levelAllows($userId, $kind, $day)) {
            return null;
        }
        $devices = $this->devices()->activeFor($userId, self::PLATFORM);
        $siteLocale = $this->siteLocale?->language(null) ?? 'fr';
        $data = ['kind' => $kind, 'screen' => (string) $action['screen'], 'params' => json_encode($action['params'] ?: new \stdClass())];
        $statuses = [];
        $error = null;
        $title = $this->translator->trans("notifications.$kind.title", $params, 'gestion', $siteLocale);
        $body = $this->translator->trans("notifications.$kind.body", $params, 'gestion', $siteLocale);
        foreach ($devices as $i => $device) {
            $locale = in_array($device['locale'], ['fr', 'en'], true) ? $device['locale'] : $siteLocale;
            $t = $this->translator->trans("notifications.$kind.title", $params, 'gestion', $locale);
            $b = $this->translator->trans("notifications.$kind.body", $params, 'gestion', $locale);
            if ($i === 0) {
                [$title, $body] = [$t, $b];
            }
            try {
                $result = $this->sender->send($device['token'], $t, $b, $data);
            } catch (\Throwable $e) {
                $result = ['status' => PushSenderInterface::FAILED, 'error' => $e->getMessage()];
            }
            if ($result['status'] === PushSenderInterface::INVALID_TOKEN) {
                $this->devices()->disable($device['token'], 'invalid_token');
            }
            $statuses[] = $result['status'];
            $error ??= $result['error'];
        }
        $status = match (true) {
            $devices === [] => 'no_device',
            in_array(PushSenderInterface::SENT, $statuses, true) => 'sent',
            $statuses !== [] && array_unique($statuses) === [PushSenderInterface::NOT_CONFIGURED] => 'not_configured',
            default => 'failed',
        };
        $this->log()->add($userId, $kind, $title, $body, $action, $subject, $status, count($devices), $status === 'sent' ? null : $error, $day);
        if ($status === 'failed') {
            $this->logger->warning(sprintf('[Notifications] %s pour l\'utilisateur %d : échec (%s)', $kind, $userId, $error ?? 'jeton refusé'));
        }

        return $status;
    }

    /**
     * Lot NOTIF-2 : le niveau du compte (sinon du site) s'applique au téléphone. « Aucune » : rien. « Essentiel » : seulement l'urgent
     * (`PUSH_URGENT`), une par jour. « Normal » : une notification par jour, la règle d'avant les niveaux. « Tout » : chaque événement,
     * au plus `NotificationLevel::DAILY_CAP` par jour, l'urgent passant au-dessus du plafond. Ce compte est tenu à part de celui des
     * courriels.
     */
    private function levelAllows(int $userId, string $kind, string $day): bool
    {
        $level = $this->levels?->forUser($userId)['value'] ?? NotificationLevel::NORMAL;
        if ($level === NotificationLevel::NONE || ($level === NotificationLevel::ESSENTIAL && !in_array($kind, self::PUSH_URGENT, true))) {
            return false;
        }

        if ($level === NotificationLevel::ALL) {
            // comme pour les courriels : au niveau « Tout », une notification urgente part même quand le plafond du jour est atteint
            return in_array($kind, self::PUSH_URGENT, true) || $this->log()->countToday($userId, $day) < NotificationLevel::DAILY_CAP;
        }

        return $this->log()->countToday($userId, $day) < 1;
    }

    private function isAdmin(int $userId): bool
    {
        $roles = json_decode((string) $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT roles FROM "user" WHERE id = ?', [$userId]), true);

        return is_array($roles) && array_intersect($roles, ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']) !== [];
    }

    private function log(): NotificationLogRepository
    {
        /** @var NotificationLogRepository $repo */
        $repo = $this->emProvider->getEntityManager()->getRepository(NotificationLog::class);

        return $repo;
    }

    private function devices(): PushDeviceRepository
    {
        /** @var PushDeviceRepository $repo */
        $repo = $this->emProvider->getEntityManager()->getRepository(PushDevice::class);

        return $repo;
    }
}
