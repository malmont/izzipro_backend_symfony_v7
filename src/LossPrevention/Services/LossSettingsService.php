<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\Entity\LossJournalEntry;
use App\LossPrevention\Entity\LossSetting;
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
            'staffNotice' => $this->staffNotice($locale, true),
            'personPatterns' => ['allowed' => $allowed, 'enabled' => $this->personPatternsOn(), 'default' => LossCountryPolicy::personPatternsDefault($place['country'], $place['region']),
                'note' => $this->texts->t($allowed ? 'settings.personPatterns.allowed' : 'settings.personPatterns.notAllowed', $locale)],
            // réservés aux lots suivants : rendus pour que l'écran les montre, non réglables dans ce lot
            'cashierAlerts' => ['allowed' => false, 'enabled' => false, 'availableFrom' => 'L5'],
            'video' => ['allowed' => false, 'enabled' => false, 'clipDays' => 30, 'clipDaysMax' => 30, 'availableFrom' => 'L3'],
            'retention' => ['findingsMonths' => (int) $row['retention_findings_months'], 'confirmedMonths' => (int) $row['retention_confirmed_months'], 'purge' => 'none',
                'bounds' => self::MONTHS, 'defaults' => ['findingsMonths' => LossSetting::DEFAULT_FINDINGS_MONTHS, 'confirmedMonths' => LossSetting::DEFAULT_CONFIRMED_MONTHS]],
            'never' => array_map(fn (string $code) => ['code' => $code, 'label' => $this->texts->t('never.' . $code, $locale)], LossCountryPolicy::NEVER),
            'legalNotice' => $this->texts->t('settings.legalNotice', $locale),
        ];
    }

    /**
     * `{ staffNoticeConfirmed?: bool, personPatterns?: { enabled: bool }, retention?: { findingsMonths?, confirmedMonths? } }` : seules les clés
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
            $changed = array_values(array_diff(array_keys($fields), ['staff_notice_confirmed_at', 'staff_notice_confirmed_by', 'analysis_floor_sequence', 'cursor_sequence', 'backfill_until_sequence']));
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
