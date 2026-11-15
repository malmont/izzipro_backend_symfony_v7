<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\Entity\LossBox;
use App\LossPrevention\Entity\LossJournalEntry;
use App\LossPrevention\Entity\LossSetting;
use App\Services\TenantEntityManagerProvider;

/**
 * Tableau de santé du matériel (lot L3-a, demande du propriétaire du 15/11/2026, `docs/prevention-pertes.md` § 16.13) : une
 * pastille par appareil, relue par l'écran toutes les quelques secondes. La réponse reste légère : deux lectures, aucune écriture.
 *
 * États : `ok` (un signe de vie depuis moins que le délai de silence), `pending` (branché mais pas encore vérifié : un boîtier qui
 * attend sa machine), `down` (silencieux : depuis quand, et quoi essayer), `absent` (pas encore installé). En L3-a le tableau ne
 * connaît que le BOÎTIER ; le tapis (L3-b), l'imprimante (L3-c), la caméra (L3-d) et les antennes (L4) y ajouteront leurs lignes.
 *
 * Le délai de silence (5 minutes par défaut) se règle par site. C'est le même délai partout : la pastille, « en ligne » dans la
 * fiche d'un boîtier, l'étape de l'assistant d'installation, et le fait `BOX_BACK` écrit au retour d'un silence.
 */
class LossHealthService
{
    public const DEFAULT_SILENT_AFTER = 300;
    /** Bornes du délai réglable : le boîtier donne signe de vie chaque minute ; au-delà d'une heure une panne passerait inaperçue */
    public const SILENT_AFTER_MIN = 120;
    public const SILENT_AFTER_MAX = 3600;
    public const STATES = ['ok', 'pending', 'down', 'absent'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly LossTexts $texts)
    {
    }

    /** Délai (secondes) sans signe de vie au-delà duquel un appareil est dit silencieux ; lire ne crée pas la ligne des réglages */
    public function silentAfter(): int
    {
        $stored = $this->emProvider->getEntityManager()->getRepository(LossSetting::class)->row()['silent_after_seconds'] ?? null;

        return $stored === null ? self::DEFAULT_SILENT_AFTER : max(self::SILENT_AFTER_MIN, min(self::SILENT_AFTER_MAX, (int) $stored));
    }

    /**
     * Le tableau : une ligne par boîtier qui n'est pas révoqué ; sans aucun boîtier, une ligne « pas installé ».
     *
     * @return array<string, mixed> { devices, summary, thresholdSeconds, exercise, screen, checkedAt }
     */
    public function board(string $locale, bool $exercise): array
    {
        $threshold = $this->silentAfter();
        $now = time();
        $devices = [];
        foreach ($this->emProvider->getEntityManager()->getRepository(LossBox::class)->liveBoxes($exercise) as $box) {
            $devices[] = $this->boxLine($box, $threshold, $now, $locale, $exercise);
        }
        if ($devices === []) {
            $devices[] = ['code' => 'box', 'kind' => 'box', 'label' => $this->texts->t('health.kind.box', $locale), 'zone' => null, 'cashRegisterId' => null, 'state' => 'absent',
                'stateLabel' => $this->texts->t('health.state.absent', $locale), 'lastSeenAt' => null, 'silentSince' => null, 'silentSeconds' => null,
                'hint' => $this->texts->t('health.hint.box.absent', $locale)];
        }
        $counts = array_fill_keys(self::STATES, 0);
        foreach ($devices as $device) {
            ++$counts[$device['state']];
        }
        $allOk = $counts['ok'] === count($devices);
        $toCheck = count($devices) - $counts['ok'];

        return ['devices' => $devices,
            'summary' => $counts + ['allOk' => $allOk, 'label' => $allOk ? $this->texts->t('health.summary.all_ok', $locale) : $this->texts->t('health.summary.' . ($toCheck > 1 ? 'to_check_many' : 'to_check_one'), $locale, ['count' => $toCheck])],
            'thresholdSeconds' => $threshold, 'exercise' => $exercise, 'screen' => LossBoxService::SCREEN, 'checkedAt' => gmdate('Y-m-d\TH:i:s\+00:00', $now)];
    }

    /**
     * L'état du boîtier vu par l'assistant d'installation : le meilleur des boîtiers du site (un seul en ordre suffit), sinon celui
     * qui demande un geste.
     *
     * @return array{state: string, hint: ?string}
     */
    public function boxState(string $locale, bool $exercise): array
    {
        $best = null;
        foreach ($this->board($locale, $exercise)['devices'] as $device) {
            // ordre : en ordre, en panne (à réparer), en attente, pas installé
            $rank = array_search($device['state'], ['ok', 'down', 'pending', 'absent'], true);
            if ($best === null || $rank < $best[0]) {
                $best = [$rank, $device];
            }
        }

        return ['state' => $best[1]['state'], 'hint' => $best[1]['hint']];
    }

    /**
     * Régler le délai de silence `{ silentAfterSeconds }` (120 à 3600 ; null : revenir à 5 minutes).
     *
     * @param array<string, mixed> $body
     *
     * @return array{thresholdSeconds: int, default: int, min: int, max: int}
     */
    public function setThreshold(array $body, int $userId): array
    {
        if (!array_key_exists('silentAfterSeconds', $body)) {
            throw new GestionValidationException(['silentAfterSeconds' => 'REQUIRED']);
        }
        $value = $body['silentAfterSeconds'];
        if ($value !== null && !is_int($value)) {
            throw new GestionValidationException(['silentAfterSeconds' => 'MUST_BE_INTEGER']);
        }
        if ($value !== null && ($value < self::SILENT_AFTER_MIN || $value > self::SILENT_AFTER_MAX)) {
            throw new GestionValidationException(['silentAfterSeconds' => 'PARAM_OUT_OF_RANGE']);
        }
        $em = $this->emProvider->getEntityManager();
        $settings = $em->getRepository(LossSetting::class);
        $before = $settings->row()['silent_after_seconds'] ?? null;
        if (($before === null ? null : (int) $before) !== $value) {
            $settings->update(['silent_after_seconds' => $value, 'updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => $userId]);
            $em->getRepository(LossJournalEntry::class)->append(LossJournalEntry::SETTINGS_CHANGED, $userId, null, ['fields' => ['silent_after_seconds']]);
        }

        return $this->threshold();
    }

    /** @return array{thresholdSeconds: int, default: int, min: int, max: int} */
    public function threshold(): array
    {
        return ['thresholdSeconds' => $this->silentAfter(), 'default' => self::DEFAULT_SILENT_AFTER, 'min' => self::SILENT_AFTER_MIN, 'max' => self::SILENT_AFTER_MAX];
    }

    /**
     * @param array<string, mixed> $box
     *
     * @return array<string, mixed>
     */
    private function boxLine(array $box, int $threshold, int $now, string $locale, bool $exercise): array
    {
        $seen = $box['last_seen_at'] !== null ? (new \DateTimeImmutable((string) $box['last_seen_at'], new \DateTimeZone('UTC')))->getTimestamp() : null;
        $silent = $seen !== null ? max(0, $now - $seen) : null;
        if ($box['status'] !== LossBox::ACTIVE) {
            [$state, $hint] = ['pending', $this->texts->t('health.hint.box.pending', $locale)];
        } elseif ($silent !== null && $silent <= $threshold) {
            [$state, $hint] = ['ok', null];
        } else {
            [$state, $hint] = ['down', $this->texts->t('health.hint.box.' . ($exercise ? 'down_exercise' : 'down'), $locale, ['duration' => $this->duration((int) $silent, $locale)])];
        }
        $iso = $seen !== null ? gmdate('Y-m-d\TH:i:s\+00:00', $seen) : null;

        return ['code' => 'box-' . $box['id'], 'kind' => 'box', 'label' => (string) $box['name'], 'zone' => null, 'cashRegisterId' => null, 'state' => $state,
            'stateLabel' => $this->texts->t('health.state.' . $state, $locale), 'lastSeenAt' => $iso, 'silentSince' => $state === 'down' ? $iso : null,
            'silentSeconds' => $state === 'down' ? $silent : null, 'hint' => $hint];
    }

    /** « 12 minutes », « 3 heures », « 2 jours » : depuis combien de temps, arrondi vers le bas */
    private function duration(int $seconds, string $locale): string
    {
        [$unit, $count] = $seconds < 7200 ? ['minutes', max(1, intdiv($seconds, 60))] : ($seconds < 172800 ? ['hours', intdiv($seconds, 3600)] : ['days', intdiv($seconds, 86400)]);

        return $this->texts->t('health.duration.' . $unit, $locale, ['count' => $count]);
    }
}
