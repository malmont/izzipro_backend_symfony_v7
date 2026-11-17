<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\Entity\LossJournalEntry;
use App\LossPrevention\Entity\LossSetting;
use App\LossPrevention\Entity\LossZonePresence;
use App\Services\TenantEntityManagerProvider;

/**
 * Réglages du module et interrupteurs par pays et région (`docs/prevention-pertes.md` § 7) : ce que le pays permet (`allowed`, calculé par
 * `LossCountryPolicy`) et ce que le site a choisi (`enabled`). Notice aux salariés : confirmée par le gérant, exigée avant toute analyse en
 * France et au Québec. Conservation : durées enregistrées seulement, AUCUNE purge (décision du propriétaire du 14/11/2026).
 */
class LossSettingsService
{
    private const MONTHS = ['min' => 1, 'max' => 120];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly LossSite $site, private readonly LossTexts $texts)
    {
    }

    public const MODULE_NOT_GRANTED = 'MODULE_NOT_GRANTED';
    public const STAFF_NOTICE_REQUIRED = 'STAFF_NOTICE_REQUIRED';

    /**
     * Ce qui empêche l'analyse de tourner, ou null :
     * - `MODULE_NOT_GRANTED` : le droit `module.loss_prevention` n'est pas accordé au site. Même en mode « demo » de la plateforme, où les
     *   écrans restent visibles, rien n'est analysé sur un site qui n'a pas le module (choix B du 14/11/2026) ;
     * - `STAFF_NOTICE_REQUIRED` : la notice aux salariés, exigée par le pays, n'est pas confirmée.
     */
    public function blockedReason(): ?string
    {
        if (!$this->moduleGranted()) {
            return self::MODULE_NOT_GRANTED;
        }
        $place = $this->site->place();

        return LossCountryPolicy::staffNoticeRequired($place['country'], $place['region']) && $this->row()['staff_notice_confirmed_at'] === null ? self::STAFF_NOTICE_REQUIRED : null;
    }

    public function analysisAllowed(): bool
    {
        return $this->blockedReason() === null;
    }

    /** Le droit du module est-il accordé au site ? Lu en base à chaque fois (pas le cache des indicateurs de modules) */
    public function moduleGranted(): bool
    {
        return (bool) $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT is_loss_prevention_active FROM entreprise ORDER BY id LIMIT 1');
    }

    /** Les règles par personne sont-elles en service sur ce site (permises par le pays ET allumées) ? */
    public function personPatternsOn(): bool
    {
        $place = $this->site->place();
        if (!LossCountryPolicy::personPatternsAllowed($place['country'], $place['region'])) {
            return false;
        }
        $chosen = $this->row()['person_patterns_enabled'];

        return $chosen === null ? LossCountryPolicy::personPatternsDefault($place['country'], $place['region']) : (bool) $chosen;
    }

    /** Lot L5-a : la caisse montre-t-elle les articles suivis non lus avant le paiement ? (permis partout ; éteint par défaut) */
    public function cashierAlertsOn(): bool
    {
        return (bool) $this->row()['cashier_alerts_enabled'];
    }

    /**
     * Bloc `staffNotice`. Synthèse (`$full` faux) : exigée ou non, et la date de la confirmation, SANS le nom (la synthèse ne nomme personne).
     * Réglages (`$full` vrai) : en plus, qui a confirmé et le texte modèle de la notice.
     *
     * @return array<string, mixed>
     */
    public function staffNotice(string $locale, bool $full = false): array
    {
        $place = $this->site->place();
        $row = $this->row();
        $out = ['required' => LossCountryPolicy::staffNoticeRequired($place['country'], $place['region']), 'confirmedAt' => self::iso($row['staff_notice_confirmed_at'])];
        if (!$full) {
            return $out;
        }
        $by = $row['staff_notice_confirmed_by'] !== null ? (int) $row['staff_notice_confirmed_by'] : null;

        return $out + ['confirmedBy' => $by !== null ? ['id' => $by, 'name' => $this->userName($by, $locale)] : null,
            'template' => ['title' => $this->texts->t('notice.title', $locale), 'text' => $this->texts->t('notice.text', $locale)]];
    }

    /** @return array<string, mixed> réponse de GET et de PUT /reglages */
    public function view(string $locale): array
    {
        $place = $this->site->place();
        $row = $this->row();
        $allowed = LossCountryPolicy::personPatternsAllowed($place['country'], $place['region']);

        return [
            'country' => $place['country'],
            'region' => $place['region'],
            // noms à afficher, dans la langue demandée ; null quand le code est absent ou inconnu (l'écran garde alors le code)
            'countryLabel' => LossPlaceLabels::country($place['country'], $locale),
            'regionLabel' => LossPlaceLabels::region($place['country'], $place['region'], $locale),
            'staffNotice' => $this->staffNotice($locale, true),
            'personPatterns' => ['allowed' => $allowed, 'enabled' => $this->personPatternsOn(), 'default' => LossCountryPolicy::personPatternsDefault($place['country'], $place['region']),
                'note' => $this->texts->t($allowed ? 'settings.personPatterns.allowed' : 'settings.personPatterns.notAllowed', $locale)],
            // lot L5-a : la caisse montre les articles suivis non lus avant le paiement ; permis partout (l'alerte parle d'un article,
            // jamais d'une personne), éteint par défaut
            'cashierAlerts' => ['allowed' => true, 'enabled' => $this->cashierAlertsOn(), 'availableFrom' => null, 'note' => $this->texts->t('settings.cashierAlerts.note', $locale)],
            // réservé aux lots suivants : rendu pour que l'écran le montre, non réglable dans ce lot
            'video' => ['allowed' => false, 'enabled' => false, 'clipDays' => 30, 'clipDaysMax' => 30, 'availableFrom' => 'L3'],
            'retention' => ['findingsMonths' => (int) $row['retention_findings_months'], 'confirmedMonths' => (int) $row['retention_confirmed_months'], 'purge' => 'none',
                'bounds' => self::MONTHS, 'defaults' => ['findingsMonths' => LossSetting::DEFAULT_FINDINGS_MONTHS, 'confirmedMonths' => LossSetting::DEFAULT_CONFIRMED_MONTHS]],
            // lot L3-b2a : fenêtres de la vente reliée aux pièces, conservation des présences sur le tapis
            'pieces' => $this->piecesView($row, $locale),
            // lot L4-c : heures d'ouverture confirmées par le gérant, et un point de départ tiré des réservations, à vérifier
            'openingHours' => $this->hoursView($locale),
            'never' => array_map(fn (string $code) => ['code' => $code, 'label' => $this->texts->t('never.' . $code, $locale)], LossCountryPolicy::NEVER),
            'legalNotice' => $this->texts->t('settings.legalNotice', $locale),
        ];
    }

    /**
     * Lot L4-c : les heures d'ouverture. `days` : les heures CONFIRMÉES par le gérant (une plage par jour ISO, 1 = lundi, ou null pour
     * fermé), ou null tant qu'il n'en a pas confirmé (la règle « hors des heures » est alors muette) ; `suggestion` : un point de départ
     * tiré des réservations (leur plage unique, la même chaque jour), jamais appliqué de lui-même.
     *
     * @return array<string, mixed>
     */
    private function hoursView(string $locale): array
    {
        $hours = LossDepotService::hours($this->emProvider->getEntityManager()->getConnection());
        $format = static fn (?array $range): ?array => $range === null ? null : ['open' => self::clock($range['open']), 'close' => self::clock($range['close'])];
        $suggestion = null;
        // la plage la plus large des réservations, comparée en MINUTES (« 9:00 » vient avant « 10:00 », ce que le texte ne sait pas)
        [$open, $close] = [null, null];
        foreach ($this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative('SELECT opening_start, opening_end FROM booking_configuration') as $range) {
            $from = self::minutes(substr(trim((string) $range['opening_start']), 0, 5));
            $to = self::minutes(substr(trim((string) $range['opening_end']), 0, 5));
            if ($from !== null && $to !== null && $from < $to) {
                [$open, $close] = [$open === null ? $from : min($open, $from), $close === null ? $to : max($close, $to)];
            }
        }
        if ($open !== null && $close !== null) {
            $suggestion = ['source' => 'bookings', 'note' => $this->texts->t('settings.openingHours.suggestion', $locale),
                'days' => array_fill_keys(['1', '2', '3', '4', '5', '6', '7'], ['open' => self::clock($open), 'close' => self::clock($close)])];
        }

        return [
            'confirmed' => $hours !== null,
            'days' => $hours !== null ? array_combine(array_map('strval', array_keys($hours['days'])), array_map($format, $hours['days'])) : null,
            'marginMinutes' => $hours['margin'] ?? (int) ($this->row()['hours_margin_minutes'] ?? LossDepotService::MARGIN_MINUTES),
            'bounds' => ['marginMinutes' => LossDepotService::MARGIN_BOUNDS], 'defaults' => ['marginMinutes' => LossDepotService::MARGIN_MINUTES],
            'suggestion' => $suggestion,
            'note' => $this->texts->t($hours !== null ? 'settings.openingHours.confirmed' : 'settings.openingHours.none', $locale),
        ];
    }

    /**
     * Lot L4-c : `openingHours` du corps de PUT /reglages. `null` : plus d'heures (règle muette). Sinon un objet : `days` (les sept jours,
     * chacun `{open, close}` en « HH:MM » ou null pour fermé ; tous exigés : le gérant confirme jour par jour) et/ou `marginMinutes`.
     *
     * @param array<string, string> $errors
     * @param array<string, mixed>  $fields
     */
    private function hoursUpdate(mixed $value, array &$errors, array &$fields): void
    {
        if ($value === null) {
            $fields['opening_hours'] = null;

            return;
        }
        if (!is_array($value)) {
            $errors['openingHours'] = 'MUST_BE_OBJECT';

            return;
        }
        if (array_key_exists('marginMinutes', $value)) {
            $margin = $value['marginMinutes'];
            if ($margin !== null && !is_int($margin)) {
                $errors['openingHours.marginMinutes'] = 'MUST_BE_INTEGER';
            } elseif ($margin !== null && ($margin < LossDepotService::MARGIN_BOUNDS['min'] || $margin > LossDepotService::MARGIN_BOUNDS['max'])) {
                $errors['openingHours.marginMinutes'] = 'PARAM_OUT_OF_RANGE';
            } else {
                $fields['hours_margin_minutes'] = $margin;
            }
        }
        if (!array_key_exists('days', $value)) {
            return;
        }
        if (!is_array($value['days'])) {
            $errors['openingHours.days'] = 'MUST_BE_OBJECT';

            return;
        }
        foreach (array_keys($value['days']) as $key) {
            if (!in_array((string) $key, ['1', '2', '3', '4', '5', '6', '7'], true)) {
                $errors['openingHours.days'] = 'INVALID_DAY'; // un jour se dit de 1 (lundi) à 7 (dimanche)
            }
        }
        $days = [];
        for ($d = 1; $d <= 7; ++$d) {
            if (!array_key_exists((string) $d, $value['days']) && !array_key_exists($d, $value['days'])) {
                $errors["openingHours.days.$d"] = 'REQUIRED';
                continue;
            }
            $range = $value['days'][(string) $d] ?? $value['days'][$d] ?? null;
            if ($range === null) {
                $days[(string) $d] = null; // fermé
                continue;
            }
            $open = is_array($range) && is_string($range['open'] ?? null) ? self::minutes($range['open']) : null;
            $close = is_array($range) && is_string($range['close'] ?? null) ? self::minutes($range['close']) : null;
            if ($open === null || $close === null || $open >= $close) {
                $errors["openingHours.days.$d"] = 'INVALID_RANGE';
                continue;
            }
            $days[(string) $d] = ['open' => $open, 'close' => $close];
        }
        if (count($days) === 7) {
            $fields['opening_hours'] = json_encode($days, JSON_THROW_ON_ERROR);
        }
    }

    /** « HH:MM » (de 00:00 à 24:00) en minutes depuis minuit, ou null */
    private static function minutes(string $value): ?int
    {
        if (!preg_match('/^([01]?\d|2[0-4]):([0-5]\d)\z/', $value, $m)) {
            return null;
        }
        $minutes = (int) $m[1] * 60 + (int) $m[2];

        return $minutes <= 1440 ? $minutes : null;
    }

    private static function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** Réglages de la vente reliée aux pièces : `clé de l'API => [colonne, valeur par défaut, bornes, secondes par unité]` */
    private const PIECES = [
        'counterWindowMinutes' => ['counter_window_seconds', LossSaleLinkService::COUNTER_WINDOW, LossSaleLinkService::COUNTER_BOUNDS, 60],
        'catchUpMinutes' => ['catch_up_seconds', LossSaleLinkService::CATCH_UP_WINDOW, LossSaleLinkService::CATCH_UP_BOUNDS, 60],
        'presenceRetentionDays' => ['presence_retention_days', LossSaleLinkService::PRESENCE_DAYS, LossSaleLinkService::PRESENCE_BOUNDS, 1],
        // lot L4-a : ce que le boîtier attend avant de dire qu'une pièce est arrivée dans une zone, ou qu'elle en est partie (secondes)
        'zoneArriveSeconds' => ['zone_arrive_seconds', LossZonePresence::ARRIVE_SECONDS, LossZonePresence::ARRIVE_BOUNDS, 1],
        'zoneLeaveSeconds' => ['zone_leave_seconds', LossZonePresence::LEAVE_SECONDS, LossZonePresence::LEAVE_BOUNDS, 1],
        // lot L4-b2 : fenêtre d'une commande web, retirée ou expédiée (jours)
        'webWindowDays' => ['web_window_days', LossSaleLinkService::WEB_DAYS, LossSaleLinkService::WEB_BOUNDS, 1],
    ];

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed> les trois réglages dans l'unité de l'écran (minutes, jours), leurs bornes, leurs valeurs par défaut, et la règle de conservation en clair
     */
    private function piecesView(array $row, string $locale): array
    {
        $out = ['bounds' => [], 'defaults' => []];
        foreach (self::PIECES as $key => [$column, $default, $bounds, $unit]) {
            $out[$key] = intdiv((int) ($row[$column] ?? $default), $unit);
            $out['bounds'][$key] = ['min' => intdiv($bounds['min'], $unit), 'max' => intdiv($bounds['max'], $unit)];
            $out['defaults'][$key] = intdiv($default, $unit);
        }

        return $out + ['note' => $this->texts->t('settings.pieces.retention', $locale, ['days' => $out['presenceRetentionDays']])];
    }

    /**
     * `{ staffNoticeConfirmed?: bool, personPatterns?: { enabled: bool }, retention?: { findingsMonths?, confirmedMonths? },
     * pieces?: { counterWindowMinutes?, catchUpMinutes?, presenceRetentionDays?, zoneArriveSeconds?, zoneLeaveSeconds?, webWindowDays? } }` (une valeur nulle remet la valeur par défaut) : seules les clés
     * envoyées changent.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(array $body, int $userId, string $locale): array
    {
        $place = $this->site->place();
        $fields = [];
        $errors = [];
        $journal = [];
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        if (array_key_exists('staffNoticeConfirmed', $body)) {
            if (!is_bool($body['staffNoticeConfirmed'])) {
                $errors['staffNoticeConfirmed'] = 'MUST_BE_BOOLEAN';
            } else {
                $confirmed = $this->row()['staff_notice_confirmed_at'] !== null;
                if ($body['staffNoticeConfirmed'] !== $confirmed) {
                    $fields['staff_notice_confirmed_at'] = $body['staffNoticeConfirmed'] ? $now : null;
                    $fields['staff_notice_confirmed_by'] = $body['staffNoticeConfirmed'] ? $userId : null;
                    $data = [];
                    if ($body['staffNoticeConfirmed'] && LossCountryPolicy::staffNoticeRequired($place['country'], $place['region'])) {
                        // Choix A : là où la notice est exigée, rien de ce qui la précède n'est analysé. Le plancher et le curseur sont posés sur
                        // la dernière entrée du journal de caisse : ni reprise des jours d'avant, ni relecture d'une période sans notice
                        // (notice retirée puis reconfirmée : le plancher remonte encore).
                        $floor = (int) $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT COALESCE(MAX(sequence), 0) FROM pos_audit_log');
                        $fields['analysis_floor_sequence'] = $fields['cursor_sequence'] = $fields['backfill_until_sequence'] = $floor;
                        // lot L4-b : les lectures de la sortie d'avant la notice ne sont pas jugées non plus (le plancher du journal de caisse
                        // ne les borne pas : elles peuvent suivre sa dernière entrée, ou précéder toute vente)
                        $fields['exit_cursor'] = LossExitService::lastExitRead($this->emProvider->getEntityManager()->getConnection());
                        // lot L4-c : ni les mouvements de zone d'avant la notice
                        $fields += LossDepotService::lastReads($this->emProvider->getEntityManager()->getConnection());
                        $data = ['floor' => $floor];
                    }
                    $journal[] = [$body['staffNoticeConfirmed'] ? LossJournalEntry::STAFF_NOTICE_CONFIRMED : LossJournalEntry::STAFF_NOTICE_WITHDRAWN, $data];
                }
            }
        }
        if (array_key_exists('personPatterns', $body)) {
            $enabled = is_array($body['personPatterns']) ? ($body['personPatterns']['enabled'] ?? null) : null;
            if (!is_bool($enabled)) {
                $errors['personPatterns.enabled'] = 'MUST_BE_BOOLEAN';
            } elseif ($enabled && !LossCountryPolicy::personPatternsAllowed($place['country'], $place['region'])) {
                $errors['personPatterns.enabled'] = 'NOT_ALLOWED_IN_COUNTRY';
            } else {
                $fields['person_patterns_enabled'] = $enabled;
            }
        }
        if (array_key_exists('cashierAlerts', $body)) {
            $enabled = is_array($body['cashierAlerts']) ? ($body['cashierAlerts']['enabled'] ?? null) : null;
            if (!is_bool($enabled)) {
                $errors['cashierAlerts.enabled'] = 'MUST_BE_BOOLEAN';
            } else {
                $fields['cashier_alerts_enabled'] = $enabled;
            }
        }
        foreach (['findingsMonths' => 'retention_findings_months', 'confirmedMonths' => 'retention_confirmed_months'] as $key => $column) {
            if (is_array($body['retention'] ?? null) && array_key_exists($key, $body['retention'])) {
                $value = $body['retention'][$key];
                if (!is_int($value)) {
                    $errors["retention.$key"] = 'MUST_BE_INTEGER';
                } elseif ($value < self::MONTHS['min'] || $value > self::MONTHS['max']) {
                    $errors["retention.$key"] = 'PARAM_OUT_OF_RANGE';
                } else {
                    $fields[$column] = $value;
                }
            }
        }
        foreach (self::PIECES as $key => [$column, , $bounds, $unit]) {
            if (is_array($body['pieces'] ?? null) && array_key_exists($key, $body['pieces'])) {
                $value = $body['pieces'][$key];
                if ($value !== null && !is_int($value)) {
                    $errors["pieces.$key"] = 'MUST_BE_INTEGER';
                } elseif ($value !== null && ($value * $unit < $bounds['min'] || $value * $unit > $bounds['max'])) {
                    $errors["pieces.$key"] = 'PARAM_OUT_OF_RANGE';
                } else {
                    $fields[$column] = $value !== null ? $value * $unit : null;
                }
            }
        }
        if (array_key_exists('pieces', $body) && !is_array($body['pieces'])) {
            $errors['pieces'] = 'MUST_BE_OBJECT';
        }
        if (array_key_exists('openingHours', $body)) {
            $this->hoursUpdate($body['openingHours'], $errors, $fields);
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        if ($fields !== []) {
            $em = $this->emProvider->getEntityManager();
            $em->getRepository(LossSetting::class)->update($fields + ['updated_at' => $now, 'updated_by' => $userId]);
            $journalRepo = $em->getRepository(LossJournalEntry::class);
            foreach ($journal as [$type, $data]) {
                $journalRepo->append($type, $userId, null, $data);
            }
            $changed = array_values(array_diff(array_keys($fields), ['staff_notice_confirmed_at', 'staff_notice_confirmed_by', 'analysis_floor_sequence', 'cursor_sequence', 'backfill_until_sequence', 'exit_cursor', 'depot_cursor', 'back_door_cursor']));
            if ($changed !== []) {
                $journalRepo->append(LossJournalEntry::SETTINGS_CHANGED, $userId, null, ['fields' => $changed]);
            }
        }

        return $this->view($locale);
    }

    /** Nom affichable d'un compte (jamais son courriel) */
    public function userName(int $id, string $locale): string
    {
        $row = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative('SELECT firstname, lastname FROM "user" WHERE id = ?', [$id]) ?: [];
        $name = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));

        return $name !== '' ? $name : $this->texts->t('user.unnamed', $locale, ['id' => $id]);
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return $this->emProvider->getEntityManager()->getRepository(LossSetting::class)->row();
    }

    private static function iso(mixed $utc): ?string
    {
        return $utc === null ? null : (new \DateTimeImmutable((string) $utc, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }
}
