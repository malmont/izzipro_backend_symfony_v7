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
 * attend sa machine), `down` (silencieux : depuis quand, et quoi essayer), `absent` (pas encore installé). Le tableau connaît le
 * BOÎTIER (lot L3-a) et le TAPIS de comptoir (lot L3-b1) ; l'imprimante (L3-c), la caméra (L3-d) et les antennes (L4) y ajouteront
 * leurs lignes. Trois lectures (les boîtiers, les tapis, le délai), aucune écriture.
 *
 * Le délai de silence (5 minutes par défaut) se règle par site. C'est le même délai partout : la pastille, « en ligne » dans la
 * fiche d'un boîtier, l'étape de l'assistant d'installation, et le fait `BOX_BACK` écrit au retour d'un silence.
 */
class LossHealthService
{
    public const DEFAULT_SILENT_AFTER = 300;
    /** Bornes du délai réglable : le boîtier donne signe de vie chaque minute (deux appels perdus ne font pas un silence) ; au-delà d'une heure une panne passerait inaperçue */
    public const SILENT_AFTER_MIN = 180;
    public const SILENT_AFTER_MAX = 3600;
    public const STATES = ['ok', 'pending', 'down', 'absent'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly LossTexts $texts,
        private readonly ?\App\Repository\TenantAppKeyRepository $appKeys = null, private readonly ?\App\Services\TenantConnectionProvider $tenant = null,
        private readonly ?LossPrintService $prints = null)
    {
    }

    /**
     * Les postes de caisse qui ont une tablette de caisse rattachée (clé d'activation `pos` en service). Sur un site à plusieurs
     * postes, un caissier n'a le contrôle avant paiement que depuis une tablette rattachée à SON poste (lot L3-b1) : un tapis dont
     * le poste n'en a aucune est donc sans effet pour les caissiers, et le tableau le dit (lot L3-b2a).
     *
     * @return array<int, true>|null null : la question ne se pose pas (un seul poste, ou rien pour le savoir)
     */
    private function boundRegisters(): ?array
    {
        if ($this->emProvider->getEntityManager()->getRepository(LossBox::class)->activeRegisterCount() < 2) {
            return null;
        }

        return $this->boundSet();
    }

    /** @return array<int, true>|null les postes qui ont une tablette de caisse rattachée, en service ; null : rien pour le savoir */
    private function boundSet(): ?array
    {
        $site = $this->tenant?->getTenantCode();
        if ($this->appKeys === null || !is_string($site) || $site === '') {
            return null;
        }
        $bound = [];
        $now = gmdate('Y-m-d H:i:s');
        foreach ($this->appKeys->listForTenant($site) as $key) {
            if ($key['app'] === 'pos' && $key['revoked_at'] === null && $key['cash_register_id'] !== null && ($key['expires_at'] === null || (string) $key['expires_at'] > $now)) {
                $bound[(int) $key['cash_register_id']] = true;
            }
        }

        return $bound;
    }

    /**
     * Les postes de caisse du site, pour relier un tapis à son poste (lot L3-b2a, `docs/prevention-pertes.md` § 16.18) : une lecture
     * pour l'ADMINISTRATEUR, sous la gestion, donc hors de la garde de la tablette (la route de la caisse `GET /api/cash-registers`
     * lui répond `TERMINAL_REQUIRED` sur un site qui exige le terminal). Aucun nom de personne.
     *
     * @return array{items: list<array{id: int, name: string, active: bool, matDeviceId: ?int, terminalBound: bool}>, terminalNeeded: bool}
     */
    public function registers(): array
    {
        $repository = $this->emProvider->getEntityManager()->getRepository(LossBox::class);
        $bound = $this->boundSet() ?? [];
        $mats = $repository->matsByRegister();
        $items = [];
        $active = 0;
        foreach ($repository->cashRegisters() as $row) {
            $id = (int) $row['id'];
            $active += (bool) $row['is_active'] ? 1 : 0;
            $items[] = ['id' => $id, 'name' => (string) $row['name'], 'active' => (bool) $row['is_active'], 'matDeviceId' => $mats[$id] ?? null, 'terminalBound' => isset($bound[$id])];
        }

        // à partir de deux postes actifs, un caissier n'a le contrôle avant paiement que depuis une tablette rattachée à son poste
        return ['items' => $items, 'terminalNeeded' => $active > 1];
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
        $repository = $this->emProvider->getEntityManager()->getRepository(LossBox::class);
        foreach ($repository->liveBoxes($exercise) as $box) {
            $devices[] = $this->boxLine($box, $threshold, $now, $locale, $exercise);
        }
        $bound = $exercise ? null : $this->boundRegisters();
        $mats = array_map(fn (array $mat): array => $this->matLine($mat, $threshold, $now, $locale, $exercise, $bound), $repository->liveMats($exercise));
        if ($devices === []) {
            $devices[] = ['code' => 'box', 'kind' => 'box', 'label' => $this->texts->t('health.kind.box', $locale), 'zone' => null, 'cashRegisterId' => null, 'state' => 'absent',
                'stateLabel' => $this->texts->t('health.state.absent', $locale), 'lastSeenAt' => null, 'silentSince' => null, 'silentSeconds' => null,
                'hint' => $this->texts->t('health.hint.box.' . ($exercise ? 'absent_exercise' : 'absent'), $locale)];
        }
        // les tapis de comptoir (lot L3-b1) et les imprimantes à puce (lot L3-c) : facultatifs, aucune ligne tant qu'aucun n'est branché
        $keyProblem = $exercise ? null : $this->prints?->keyProblem(); // un exercice n'emploie aucun mot de passe : il n'a besoin d'aucune clé
        $printers = array_map(fn (array $printer): array => $this->printerLine($printer, $threshold, $now, $locale, $exercise, $keyProblem), $repository->livePrinters($exercise));
        $devices = [...$devices, ...$mats, ...$printers];
        $counts = array_fill_keys(self::STATES, 0);
        foreach ($devices as $device) {
            ++$counts[$device['state']];
        }
        // « à vérifier » : ce qui est branché et ne va pas. Un appareil pas encore installé n'est pas une panne (une boutique peut
        // suivre ses pièces sans boîtier) : sans rien d'installé, le bandeau le dit, sans alarme
        $toCheck = $counts['pending'] + $counts['down'];
        $installed = $counts['ok'] + $toCheck;
        $allOk = $installed > 0 && $toCheck === 0;
        $label = $installed === 0 ? 'none' : ($allOk ? 'all_ok' : ($toCheck > 1 ? 'to_check_many' : 'to_check_one'));

        return ['devices' => $devices,
            'summary' => $counts + ['allOk' => $allOk, 'label' => $this->texts->t('health.summary.' . $label, $locale, ['count' => $toCheck])],
            'thresholdSeconds' => $threshold, 'exercise' => $exercise, 'screen' => LossBoxService::SCREEN, 'checkedAt' => gmdate('Y-m-d\TH:i:s\+00:00', $now)];
    }

    /**
     * L'état d'un genre d'appareil vu par l'assistant d'installation : le meilleur de ceux du site (un seul en ordre suffit), sinon
     * celui qui demande un geste. Sans aucun appareil de ce genre : pas installé, et quoi faire.
     *
     * @return array{state: string, hint: ?string}
     */
    public function kindState(string $kind, string $locale, bool $exercise): array
    {
        $best = null;
        foreach ($this->board($locale, $exercise)['devices'] as $device) {
            if ($device['kind'] !== $kind) {
                continue;
            }
            // ordre : en ordre, en panne (à réparer), en attente, pas installé
            $rank = array_search($device['state'], ['ok', 'down', 'pending', 'absent'], true);
            if ($best === null || $rank < $best[0]) {
                $best = [$rank, $device];
            }
        }

        if ($best === null) {
            return ['state' => 'absent', 'hint' => $this->texts->t("health.hint.$kind." . ($exercise ? 'absent_exercise' : 'absent'), $locale)];
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

    /**
     * Un tapis de comptoir : en panne si lui ou son boîtier ne répond pas ; en attente tant qu'il n'a lu aucune puce ou qu'il n'est
     * pas relié à un poste de caisse ; sinon en ordre.
     *
     * @param array<string, mixed> $mat
     *
     * @return array<string, mixed>
     */
    private function matLine(array $mat, int $threshold, int $now, string $locale, bool $exercise, ?array $bound = null): array
    {
        $boxSeen = $mat['box_seen'] !== null ? (new \DateTimeImmutable((string) $mat['box_seen'], new \DateTimeZone('UTC')))->getTimestamp() : null;
        $seen = (new \DateTimeImmutable((string) $mat['last_seen_at'], new \DateTimeZone('UTC')))->getTimestamp();
        $boxSilent = $mat['box_status'] !== LossBox::ACTIVE || $boxSeen === null || $now - $boxSeen > $threshold;
        $register = $mat['cash_register_id'] !== null ? (int) $mat['cash_register_id'] : null;
        // l'horloge du boîtier est déréglée : ses faits ne posent rien sur le tapis. Pas encore mesurée (un vrai boîtier tout juste
        // inscrit, avant son premier signe de vie) : rien n'est en panne, on attend
        $clockDoubt = $mat['box_clock'] !== null && abs((int) $mat['box_clock']) > LossBoxChannelService::CLOCK_TOLERANCE;
        $clockUnknown = $mat['box_clock'] === null && !$exercise;
        if ($mat['status'] === 'down' || $boxSilent) {
            [$state, $hint] = ['down', $this->texts->t('health.hint.mat.' . ($boxSilent ? 'box_silent' : 'down'), $locale)];
        } elseif ($clockDoubt) {
            [$state, $hint] = ['down', $this->texts->t('health.hint.mat.clock', $locale)];
        } elseif ($clockUnknown) {
            [$state, $hint] = ['pending', $this->texts->t('health.hint.mat.clock_pending', $locale)];
        } elseif (!(bool) $mat['has_read']) {
            [$state, $hint] = ['pending', $this->texts->t('health.hint.mat.' . ($exercise ? 'never_read_exercise' : 'never_read'), $locale)];
        } elseif ($register === null && !$exercise) {
            [$state, $hint] = ['pending', $this->texts->t('health.hint.mat.no_register', $locale)]; // un tapis simulé n'a pas de poste
        } elseif ($register !== null && $bound !== null && !isset($bound[$register])) {
            // plusieurs postes, et aucune tablette rattachée à celui-ci : le contrôle avant paiement est arrêté pour ses caissiers
            [$state, $hint] = ['pending', $this->texts->t('health.hint.mat.no_terminal', $locale, ['register' => (string) ($mat['register_name'] ?? $register)])];
        } else {
            [$state, $hint] = ['ok', null];
        }
        // depuis quand il se tait : le dernier signe de vie de son boîtier s'il s'est tu, sinon la dernière fois que le tapis a été
        // annoncé. Une horloge déréglée n'est pas un silence : le tapis parle, aucune durée n'est dite
        $since = $state === 'down' && ($mat['status'] === 'down' || $boxSilent) ? ($boxSilent && $boxSeen !== null ? min($boxSeen, $seen) : $seen) : null;
        $label = $this->texts->t('health.kind.mat', $locale) . ($mat['register_name'] !== null ? ' · ' . $mat['register_name'] : '');

        return ['code' => 'mat-' . $mat['id'], 'kind' => 'mat', 'label' => $label, 'zone' => 'counter', 'cashRegisterId' => $register, 'state' => $state,
            'stateLabel' => $this->texts->t('health.state.' . $state, $locale), 'lastSeenAt' => gmdate('Y-m-d\TH:i:s\+00:00', $seen),
            'silentSince' => $since !== null ? gmdate('Y-m-d\TH:i:s\+00:00', $since) : null, 'silentSeconds' => $since !== null ? max(0, $now - $since) : null, 'hint' => $hint];
    }

    /**
     * La ligne d'une imprimante à puce : en panne si elle ou son boîtier se tait ; en attente tant que la clé de chiffrement du mot de
     * passe des puces manque (aucun vrai travail ne peut se créer : le tableau le dit à l'administrateur), ou tant qu'elle n'a écrit
     * aucune puce relue conforme.
     *
     * @param array<string, mixed> $printer
     *
     * @return array<string, mixed>
     */
    private function printerLine(array $printer, int $threshold, int $now, string $locale, bool $exercise, ?string $keyProblem): array
    {
        $boxSeen = $printer['box_seen'] !== null ? (new \DateTimeImmutable((string) $printer['box_seen'], new \DateTimeZone('UTC')))->getTimestamp() : null;
        $seen = (new \DateTimeImmutable((string) $printer['last_seen_at'], new \DateTimeZone('UTC')))->getTimestamp();
        $boxSilent = $printer['box_status'] !== LossBox::ACTIVE || $boxSeen === null || $now - $boxSeen > $threshold;
        if ($printer['status'] === 'down' || $boxSilent) {
            [$state, $hint] = ['down', $this->texts->t('health.hint.printer.' . ($boxSilent ? 'box_silent' : 'down'), $locale)];
        } elseif ($keyProblem !== null) {
            [$state, $hint] = ['pending', $this->texts->t('prints.ready.' . $keyProblem, $locale, ['variable' => LossTagVault::VARIABLE])];
        } elseif (!(bool) $printer['has_written']) {
            [$state, $hint] = ['pending', $this->texts->t('health.hint.printer.' . ($exercise ? 'never_written_exercise' : 'never_written'), $locale)];
        } else {
            [$state, $hint] = ['ok', null];
        }
        $since = $state === 'down' ? ($boxSilent && $boxSeen !== null ? min($boxSeen, $seen) : $seen) : null;

        return ['code' => 'printer-' . $printer['id'], 'kind' => 'printer', 'label' => $this->texts->t('health.kind.printer', $locale), 'zone' => null, 'cashRegisterId' => null, 'state' => $state,
            'stateLabel' => $this->texts->t('health.state.' . $state, $locale), 'lastSeenAt' => gmdate('Y-m-d\TH:i:s\+00:00', $seen),
            'silentSince' => $since !== null ? gmdate('Y-m-d\TH:i:s\+00:00', $since) : null, 'silentSeconds' => $since !== null ? max(0, $now - $since) : null, 'hint' => $hint];
    }

    /** « 12 minutes », « 3 heures », « 2 jours » : depuis combien de temps, arrondi vers le bas */
    private function duration(int $seconds, string $locale): string
    {
        [$unit, $count] = $seconds < 7200 ? ['minutes', max(1, intdiv($seconds, 60))] : ($seconds < 172800 ? ['hours', intdiv($seconds, 3600)] : ['days', intdiv($seconds, 86400)]);

        return $this->texts->t('health.duration.' . $unit, $locale, ['count' => $count]);
    }
}
