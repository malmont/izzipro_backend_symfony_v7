<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionNotificationService;
use App\LossPrevention\Entity\LossAlert;
use App\LossPrevention\Entity\LossJournalEntry;
use App\LossPrevention\Entity\LossPiece;
use App\LossPrevention\Entity\LossSaleUnit;
use App\LossPrevention\Message\CheckLossExitMessage;
use App\LossPrevention\Repository\LossAlertRepository;
use App\LossPrevention\Repository\LossSaleUnitRepository;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * L'alerte de sortie (lot L5-d, 17/11/2026, `docs/prevention-pertes.md` § 18.2 « A » et § 18.11) : une PRÉ-ALERTE, pas le jugement du
 * constat (« sortie sans vente » reste jugé après le rattrapage, sans changement).
 *
 * - À l'arrivée d'une lecture `exit` ou `back_door` d'une pièce en service, non redite, un contrôle part `DELAY_SECONDS` plus tard sur la
 *   file `email` (servie par un worker léger : jamais la file de l'IA, qu'un appel au modèle encombrerait ; chef de projet).
 * - Le contrôle est LÉGER, lu dans les commandes elles-mêmes (l'analyse n'est peut-être pas encore passée) : la pièce n'est ni vendue ni
 *   retirée ; aucune vente de sa variante n'attend de pièce (caisse dans la fenêtre de rattrapage, web dans sa fenêtre) ; aucune
 *   location du jour ne l'explique. Sinon, la pièce rejoint l'alerte de sa zone et de sa minute.
 * - Une alerte NOUVELLE part sur le téléphone des administrateurs du site (type `loss_exit`, urgent, plafond propre de
 *   `GestionNotificationService::OWN_DAILY_CAP` par jour et par gérant) ; hors des heures d'ouverture, seulement si le gérant n'a pas
 *   coupé la nuit. En exercice : au seul administrateur qui l'a lancé, hors plafond.
 * - **Jamais nominative** : l'article, la zone, l'heure ; rien d'une personne, ni dans l'alerte, ni dans la notification, ni au journal.
 */
class LossExitAlertService
{
    public const DELAY_SECONDS = 120;
    /** Relecture du 17/11/2026 : une lecture plus vieille que cela au moment du contrôle n'alerte plus (boîtier revenu d'une coupure, worker
     *  en retard) ; le constat « sortie sans vente » la juge après le rattrapage */
    public const STALE_SECONDS = 900;
    public const ZONES = ['exit', 'back_door'];
    /** La file des notifications : un worker léger, déjà en marche */
    public const TRANSPORT = 'email';
    /** Le sujet d'une notification d'exercice : hors du plafond du jour (relecture du 17/11/2026) */
    public const EXERCISE_SUBJECT = 'loss_alert_exercise';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LossSettingsService $settings,
        private readonly LossSite $site,
        private readonly LossTexts $texts,
        private readonly LossSaleLinkService $sales,
        private readonly MessageBusInterface $bus,
        private readonly TenantConnectionManager $tenants,
        private readonly ?GestionNotificationService $notifications = null,
        private readonly ?\App\Services\EmailConfigurationService\EmailLocale $siteLocale = null,
    ) {
    }

    /** Une lecture à la sortie vient d'arriver : son contrôle part dans `DELAY_SECONDS` (`$requestedBy` : l'exercice d'un administrateur) */
    public function schedule(int $eventId, ?int $requestedBy = null): void
    {
        $this->bus->dispatch(new CheckLossExitMessage($eventId, (string) $this->tenants->getCurrentTenantCode(), $requestedBy, time() + self::DELAY_SECONDS),
            [new DelayStamp(self::DELAY_SECONDS * 1000), new TransportNamesStamp([self::TRANSPORT])]);
    }

    /**
     * Le contrôle léger d'une lecture à la sortie. Rend l'alerte rejointe ou ouverte, ou null (lecture expliquée, ou rien à faire).
     *
     * @return array{id: int, created: bool, pushed: int}|null
     */
    public function check(int $eventId, ?int $requestedBy = null, ?int $now = null): ?array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $read = $conn->fetchAssociative("SELECT id, piece_id, occurred_at, exercise, data FROM loss_piece_event WHERE id = ? AND type = 'ZONE_SEEN'", [$eventId]) ?: null;
        if ($read === null || $read['piece_id'] === null) {
            return null;
        }
        $data = json_decode((string) $read['data'], true) ?: [];
        $zone = (string) ($data['zone'] ?? '');
        $exercise = (bool) $read['exercise'];
        if (!in_array($zone, self::ZONES, true) || ($data['repeat'] ?? false) || ($data['clockDoubt'] ?? false) || ($data['tagRetired'] ?? false)) {
            return null; // une arrivée redite, à l'heure douteuse, ou par une puce retirée (jetée, remplacée) n'alerte pas
        }
        // un vrai constat ne s'ouvre que si l'analyse est permise (droit du module, notice) ; l'exercice, lui, est ouvert sans le droit
        if (!$exercise && (!$this->settings->moduleGranted() || !$this->settings->analysisAllowed())) {
            return null;
        }
        $piece = $conn->fetchAssociative('SELECT id, variant_id, status, exercise FROM loss_piece WHERE id = ?', [(int) $read['piece_id']]) ?: null;
        if ($piece === null || (bool) $piece['exercise'] !== $exercise || in_array($piece['status'], [LossPiece::SOLD, LossPiece::RETIRED], true)) {
            return null; // une pièce vendue sort sans alerte ; une pièce retirée n'est plus suivie
        }
        $readAt = new \DateTimeImmutable((string) $read['occurred_at'], new \DateTimeZone('UTC'));
        $now ??= time();
        if ($now - $readAt->getTimestamp() > self::STALE_SECONDS) {
            return null; // trop ancienne : une alerte des heures après n'aide personne, et le contexte (ventes, retours) a changé
        }
        $variant = (int) $piece['variant_id'];
        $units = $this->units();
        if (!$exercise) {
            $windows = $this->sales->windows();
            // un client qui revient rendre l'article : la pièce est revenue peu avant ou peu après la lecture
            $returned = $conn->fetchOne('SELECT 1 FROM loss_sale_unit WHERE piece_id = ? AND returned_at >= ? LIMIT 1',
                [(int) $piece['id'], gmdate('Y-m-d H:i:s', $readAt->getTimestamp() - $windows['catchUp'])]);
            if ($returned !== false) {
                return null;
            }
            // les fenêtres partent de la LECTURE, pas de l'heure du contrôle
            $waiting = $units->waitingSales(gmdate('Y-m-d H:i:s', $readAt->getTimestamp() - $windows['catchUp']),
                gmdate('Y-m-d H:i:s', $readAt->getTimestamp() - $windows['webDays'] * 86400), $variant);
            if (($waiting[$variant] ?? 0) > 0) {
                return null; // une vente de la variante attend sa pièce : c'est elle, probablement
            }
            $clock = $this->site->clock();
            [$dayStart, $dayEnd] = $clock->range($clock->day($readAt), $clock->day($readAt));
            $rented = $units->rentedBetween($dayStart->modify('-1 day')->format('Y-m-d H:i:s'), $dayEnd->modify('+1 day')->format('Y-m-d H:i:s'));
            if (($rented[$variant] ?? 0) > 0) {
                return null; // une location de la variante court ce jour-là
            }
        }
        $hours = LossDepotService::hours($conn);
        $outOfHours = $hours !== null && !LossDepotService::open($hours, $readAt, $this->site->clock()->timezone());
        $alert = $this->alerts()->record($exercise, $zone, $readAt->format('Y-m-d H:i:00'), $this->site->clock()->day($readAt), $outOfHours, $variant, (int) $piece['id'],
            $exercise ? $requestedBy : null);
        if ($alert === null) {
            return null;
        }
        $pushed = 0;
        if ($alert['created']) {
            $this->journal()->append(LossJournalEntry::ALERT_SENT, null, null, ['alert' => $alert['id'], 'zone' => $zone, 'outOfHours' => $outOfHours, 'exercise' => $exercise]);
            // la ligne ENTIÈRE : `fetchOne` rend `false` aussi pour un booléen faux (la nuit coupée passerait pour « pas de réglage »)
            $night = $this->settings->exitAlertsNight(); // sans ligne de réglages : la valeur par défaut, allumée
            if ($exercise || !$outOfHours || $night) {
                $pushed = $this->push($alert['id'], $zone, $readAt, $outOfHours, $exercise, $requestedBy);
            }
        }

        return $alert + ['pushed' => $pushed];
    }

    /**
     * Les alertes du jour du magasin, pour la liste en direct (relue toutes les 30 s) : article, zone, heure ; rien d'une personne.
     *
     * @return array<string, mixed>
     */
    public function today(?string $since, string $locale, bool $exercise, ?int $userId = null): array
    {
        $clock = $this->site->clock();
        $day = $clock->day(new \DateTimeImmutable());
        $sinceUtc = null;
        if (is_string($since) && $since !== '') {
            try {
                $sinceUtc = (new \DateTimeImmutable($since))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                throw new \App\Gestion\Services\GestionValidationException(['since' => 'INVALID_DATE']);
            }
        }
        $rows = $this->alerts()->ofDay($day, $exercise, $sinceUtc, $exercise ? (int) $userId : null); // en exercice, les siennes seulement (relecture 2)
        $variants = [];
        foreach ($rows as $row) {
            foreach (json_decode((string) $row['articles'], true) ?: [] as $a) {
                $variants[(int) $a['variantId']] = true;
            }
        }
        $catalogue = $variants !== [] ? $this->emProvider->getEntityManager()->getRepository(LossPiece::class)->variants(array_keys($variants)) : [];
        $tz = $clock->timezone();
        $alerts = [];
        foreach ($rows as $row) {
            $minute = new \DateTimeImmutable((string) $row['minute'], new \DateTimeZone('UTC'));
            $articles = [];
            foreach (json_decode((string) $row['articles'], true) ?: [] as $a) {
                $label = LossPieceService::catalogue($catalogue[(int) $a['variantId']] ?? null);
                $articles[] = ['variantId' => (int) $a['variantId'], 'name' => $label['name'], 'variantLabel' => $label['variantLabel'], 'count' => (int) $a['count']];
            }
            $alerts[] = ['id' => (int) $row['id'], 'at' => $minute->format(\DateTimeInterface::ATOM), 'atLabel' => LossTexts::dateTime($minute, $tz, $locale),
                'zone' => (string) $row['zone'], 'zoneLabel' => $this->texts->t('boxes.zone.' . $row['zone'], $locale), 'outOfHours' => (bool) $row['out_of_hours'], 'articles' => $articles];
        }
        $pushedToday = $userId !== null && !$exercise
            ? $this->emProvider->getEntityManager()->getRepository(\App\Entity\NotificationLog::class)->countKindToday($userId, $day, GestionNotificationService::LOSS_EXIT) : 0;

        return ['day' => $day, 'exercise' => $exercise, 'alerts' => $alerts, 'pushedToday' => $pushedToday, 'cap' => GestionNotificationService::OWN_DAILY_CAP[GestionNotificationService::LOSS_EXIT],
            'notice' => $this->texts->t('alerts.notice', $locale), 'serverTime' => gmdate(\DateTimeInterface::ATOM)];
    }

    /** La notification d'une alerte nouvelle ; rend le nombre de gérants servis */
    private function push(int $alertId, string $zone, \DateTimeImmutable $readAt, bool $outOfHours, bool $exercise, ?int $requestedBy): int
    {
        if ($this->notifications === null) {
            return 0;
        }
        $conn = $this->emProvider->getEntityManager()->getConnection();
        // les administrateurs du site (le rôle exact), jamais le compte de la plateforme ; en exercice, celui qui l'a lancé
        $users = $exercise ? ($requestedBy !== null ? [$requestedBy] : [])
            : array_map('intval', $conn->fetchFirstColumn('SELECT id FROM "user" WHERE roles::text LIKE \'%"ROLE_ADMIN"%\' AND roles::text NOT LIKE \'%"ROLE_SUPER_ADMIN"%\' AND deleted_at IS NULL AND is_verified = true ORDER BY id'));
        $zoneLabel = $this->texts->t('boxes.zone.' . $zone, $this->siteLocale?->language(null) ?? 'fr');
        $time = $readAt->setTimezone($this->site->clock()->timezone())->format('H:i');
        $served = 0;
        foreach ($users as $userId) {
            // l'exercice a son texte (« Exercice : … ») et son sujet, que le plafond du jour ne compte pas ; la nuit a le sien
            $status = $this->notifications->notify($userId, GestionNotificationService::LOSS_EXIT, ['%zone%' => $zoneLabel, '%time%' => $time],
                ['screen' => $exercise ? 'pertes.exercices' : 'pertes.alertes', 'params' => new \stdClass()],
                ['type' => $exercise ? self::EXERCISE_SUBJECT : 'loss_alert', 'id' => $alertId], null, $exercise, $exercise ? 'exercise' : ($outOfHours ? 'night' : ''));
            if ($status !== null) {
                ++$served;
            }
        }
        if ($served > 0) {
            $this->alerts()->markPushed($alertId, $served);
        }

        return $served;
    }

    private function alerts(): LossAlertRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossAlert::class);
    }

    private function units(): LossSaleUnitRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossSaleUnit::class);
    }

    private function journal(): \App\LossPrevention\Repository\LossJournalRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossJournalEntry::class);
    }
}
