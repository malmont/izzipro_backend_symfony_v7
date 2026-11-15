<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\Entity\LossBox;
use App\LossPrevention\Entity\LossBoxEvent;
use App\LossPrevention\Entity\LossDevice;
use App\LossPrevention\Entity\LossPieceEvent;
use App\LossPrevention\Repository\LossBoxRepository;
use App\LossPrevention\Repository\LossPieceRepository;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;

/**
 * Les boîtiers vus par l'administrateur (lot L3-a, 15/11/2026, `docs/prevention-pertes.md` § 16) : les créer, les nommer, remettre
 * leur clé à zéro, les révoquer, voir s'ils donnent signe de vie et ce que leurs appareils disent.
 *
 * - **La clé n'est montrée qu'une fois**, à la création et à la remise à zéro ; la base n'en garde que l'empreinte. Aucune lecture
 *   ne la rend, ni elle ni l'empreinte de la machine.
 * - **Une clé = un boîtier = une machine.** Remettre la clé à zéro détache la machine (boîtier remplacé) et périme ses jetons ;
 *   révoquer est définitif.
 * - **« En ligne » se calcule** : le dernier signe de vie a moins de 5 minutes. Rien n'est écrit pour le dire.
 * - `$exercise` : un boîtier simulé, fabriqué par le serveur (aucune machine, aucune clé), qui joue des scénarios écrits dans les
 *   tables du module, marqués exercice ; ouvert sans le droit du module, plafonné, effacé d'un geste, jamais dans un chiffre réel.
 */
class LossBoxService
{
    public const SCREEN = 'pertes.boitiers';
    /** Boîtiers non révoqués par site, au plus */
    public const BOX_LIMIT = 20;
    public const EXERCISE_BOX_LIMIT = 3;
    public const EVENTS_SHOWN = 50;
    /** Scénarios du boîtier simulé */
    public const SCENARIOS = ['heartbeat', 'network_cut', 'device_down', 'device_up', 'spot_check', 'zone_exit'];
    private const SIM_EXIT_READER = 'SIM-LECTEUR-SORTIE';
    private const SIM_HAND_READER = 'SIM-LECTEUR-MAIN';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenant,
        private readonly TenantPublicHostResolver $hosts,
        private readonly LossSite $site,
        private readonly LossTexts $texts,
    ) {
    }

    /** @return array<string, mixed> { items, total, limit, exercise, screen } */
    public function list(string $locale, bool $exercise): array
    {
        $repository = $this->repository();
        $boxes = $repository->boxes($exercise);
        $devices = $repository->devicesOf(array_map(static fn (array $b): int => (int) $b['id'], $boxes));
        $items = array_map(fn (array $box): array => $this->view($box, $devices[(int) $box['id']], $locale), $boxes);

        return ['items' => $items, 'total' => count($items), 'limit' => $exercise ? self::EXERCISE_BOX_LIMIT : self::BOX_LIMIT, 'exercise' => $exercise, 'screen' => self::SCREEN];
    }

    /** @return array<string, mixed> la fiche d'un boîtier : ses appareils, ses derniers faits, le nombre de faits de pièces reçus */
    public function sheet(int $id, string $locale, bool $exercise): array
    {
        $repository = $this->repository();
        $box = $this->find($id, $exercise, $locale);
        $devices = $repository->devicesOf([$id])[$id];
        $serials = [];
        foreach ($devices as $device) {
            $serials[(int) $device['id']] = (string) $device['serial'];
        }
        $events = [];
        foreach ($repository->eventsOf($id, self::EVENTS_SHOWN) as $row) {
            $at = new \DateTimeImmutable((string) $row['occurred_at'], new \DateTimeZone('UTC'));
            $data = json_decode((string) $row['data'], true) ?: [];
            $events[] = ['id' => (int) $row['id'], 'type' => (string) $row['type'], 'label' => $this->texts->t('boxes.event.' . $row['type'], $locale),
                'at' => LossAuditEntry::iso($at), 'atLabel' => LossTexts::dateTime($at, $this->site->clock()->timezone(), $locale),
                'device' => $row['device_id'] !== null ? ($serials[(int) $row['device_id']] ?? null) : null,
                'silentSeconds' => isset($data['silentSeconds']) ? (int) $data['silentSeconds'] : null, 'byUser' => $row['user_id'] !== null];
        }

        return $this->view($box, $devices, $locale) + ['events' => $events, 'factsReceived' => $repository->pieceFactCount($id)];
    }

    /**
     * Ajouter un boîtier `{ name, place? }` : il attend sa machine ; sa clé est rendue ICI, une seule fois.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> { box, key, enrolment { host, key, code, text } }
     */
    public function create(array $body, string $locale, int $userId): array
    {
        [$name, $place] = $this->names($body, true);

        return $this->transaction(function () use ($name, $place, $locale, $userId): array {
            $repository = $this->repository();
            // les créations d'un site ne se croisent pas : le plafond se compte sous verrou
            $this->emProvider->getEntityManager()->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(' . LossBoxRepository::CREATE_LOCK . ')');
            if ($repository->liveCount(false) >= self::BOX_LIMIT) {
                throw new GestionConflictException('BOX_LIMIT_REACHED', $this->texts->t('boxes.error.BOX_LIMIT_REACHED', $locale, ['limit' => self::BOX_LIMIT]), 409, ['limit' => self::BOX_LIMIT]);
            }
            $key = LossBoxKey::generate();
            $now = self::now();
            $id = $repository->create($name, $place, LossBoxKey::hash($key), LossBoxKey::hint($key), false, $userId, $now);
            $repository->event($id, null, LossBoxEvent::BOX_CREATED, $userId, false, [], $now);

            return ['box' => $this->sheet($id, $locale, false), 'key' => $key, 'enrolment' => $this->enrolment($key, $locale)];
        });
    }

    /**
     * Renommer un boîtier, dire où il est `{ name?, place? }`.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> la fiche
     */
    public function update(int $id, array $body, string $locale): array
    {
        $box = $this->find($id, false, $locale);
        [$name, $place] = $this->names($body, false);
        $this->repository()->rename($id, $name ?? (string) $box['name'], array_key_exists('place', $body) ? $place : $box['place'], self::now());

        return $this->sheet($id, $locale, false);
    }

    /**
     * Remettre la clé à zéro `{ confirm: true }` : une nouvelle clé (montrée une fois), la machine d'avant est détachée et ses
     * jetons ne valent plus rien. Pour un boîtier remplacé ou une clé perdue.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> { box, key, enrolment }
     */
    public function resetKey(int $id, array $body, string $locale, int $userId): array
    {
        $this->confirmed($body);

        return $this->transaction(function () use ($id, $locale, $userId): array {
            $repository = $this->repository();
            $box = $repository->box($id, true);
            if ($box === null || (bool) $box['exercise']) {
                throw new GestionConflictException('BOX_NOT_FOUND', $this->texts->t('boxes.error.BOX_NOT_FOUND', $locale), 404);
            }
            if ($box['status'] === LossBox::REVOKED) {
                throw new GestionConflictException('BOX_REVOKED', $this->texts->t('boxes.error.BOX_REVOKED', $locale), 409);
            }
            $key = LossBoxKey::generate();
            $now = self::now();
            $repository->resetKey($id, LossBoxKey::hash($key), LossBoxKey::hint($key), $now);
            $repository->event($id, null, LossBoxEvent::BOX_KEY_RESET, $userId, false, [], $now);

            return ['box' => $this->sheet($id, $locale, false), 'key' => $key, 'enrolment' => $this->enrolment($key, $locale)];
        });
    }

    /**
     * Révoquer un boîtier `{ confirm: true }` : définitif ; son jeton est refusé dès l'appel suivant. Révoquer deux fois ne fait rien.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> la fiche
     */
    public function revoke(int $id, array $body, string $locale, int $userId): array
    {
        $this->confirmed($body);

        return $this->transaction(function () use ($id, $locale, $userId): array {
            $repository = $this->repository();
            $box = $repository->box($id, true);
            if ($box === null || (bool) $box['exercise']) {
                throw new GestionConflictException('BOX_NOT_FOUND', $this->texts->t('boxes.error.BOX_NOT_FOUND', $locale), 404);
            }
            if ($box['status'] !== LossBox::REVOKED) {
                $now = self::now();
                $repository->revoke($id, $now);
                $repository->event($id, null, LossBoxEvent::BOX_REVOKED, $userId, false, [], $now);
            }

            return $this->sheet($id, $locale, false);
        });
    }

    // ----- Mode exercice : le boîtier simulé -----

    /**
     * Un boîtier simulé, déjà « inscrit », avec deux lecteurs simulés (la sortie, le lecteur à main).
     *
     * @return array<string, mixed> { box, scenarios }
     */
    public function createExercise(string $locale, int $userId): array
    {
        return $this->transaction(function () use ($locale, $userId): array {
            $this->exerciseLock();
            $repository = $this->repository();
            if ($repository->liveCount(true) >= self::EXERCISE_BOX_LIMIT) {
                throw new GestionConflictException('EXERCISE_LIMIT_REACHED', $this->texts->t('boxes.error.EXERCISE_LIMIT_REACHED', $locale, ['limit' => self::EXERCISE_BOX_LIMIT]), 409,
                    ['limit' => self::EXERCISE_BOX_LIMIT]);
            }
            $now = self::now();
            $id = $repository->create($this->texts->t('boxes.exercise.name', $locale), $this->texts->t('boxes.exercise.place', $locale), null, null, true, $userId, $now);
            $repository->event($id, null, LossBoxEvent::BOX_CREATED, $userId, true, [], $now);
            $repository->event($id, null, LossBoxEvent::BOX_ENROLLED, null, true, ['again' => false], $now);
            foreach ([[self::SIM_EXIT_READER, 'exit'], [self::SIM_HAND_READER, null]] as [$serial, $zone]) {
                $device = $repository->insertDevice($id, $serial, LossDevice::READER, null, LossDevice::OK, $zone, true, $now);
                $repository->event($id, $device, LossBoxEvent::DEVICE_SEEN, null, true, ['kind' => LossDevice::READER], $now);
            }

            return ['box' => $this->sheet($id, $locale, true), 'scenarios' => $this->scenarios($locale)];
        });
    }

    /**
     * Jouer un scénario sur un boîtier simulé `{ scenario }` : les mêmes faits qu'un vrai boîtier écrirait, marqués exercice.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> { scenario, written, box }
     */
    public function play(int $id, array $body, string $locale): array
    {
        $scenario = $body['scenario'] ?? null;
        if (!is_string($scenario) || !in_array($scenario, self::SCENARIOS, true)) {
            throw new GestionValidationException(['scenario' => array_key_exists('scenario', $body) ? 'INVALID_VALUE' : 'REQUIRED']);
        }

        return $this->transaction(function () use ($id, $scenario, $locale): array {
            $this->exerciseLock();
            $repository = $this->repository();
            $box = $repository->box($id, true);
            if ($box === null || !(bool) $box['exercise']) {
                throw new GestionConflictException('BOX_NOT_FOUND', $this->texts->t('boxes.error.BOX_NOT_FOUND', $locale), 404);
            }
            $devices = [];
            foreach ($repository->devicesOf([$id])[$id] as $row) {
                $devices[(string) $row['serial']] = $row;
            }
            $now = self::now();
            $written = 0;
            switch ($scenario) {
                case 'heartbeat':
                    $repository->seen($id, $now, null, 0);
                    break;
                case 'network_cut':
                    // deux heures sans réseau, puis le retour : le fait dit le trou
                    $repository->event($id, null, LossBoxEvent::BOX_BACK, null, true, ['silentSeconds' => 7200], $now);
                    $repository->seen($id, $now, null, 0);
                    $written = 1;
                    break;
                case 'device_down':
                case 'device_up':
                    $device = $devices[self::SIM_EXIT_READER] ?? null;
                    $target = $scenario === 'device_down' ? LossDevice::DOWN : LossDevice::OK;
                    if ($device !== null && $device['status'] !== $target) {
                        $repository->event($id, (int) $device['id'], $target === LossDevice::DOWN ? LossBoxEvent::SOURCE_DOWN : LossBoxEvent::SOURCE_UP, null, true, ['reason' => 'reported'], $now);
                        $repository->deviceStatus((int) $device['id'], $target, null, true, $now);
                        $written = 1;
                    }
                    break;
                default:
                    // lectures de puces : sur les pièces d'exercice qui en portent une
                    $spot = $scenario === 'spot_check';
                    $pieces = $repository->exercisePiecesWithTag($spot ? 3 : 1);
                    if ($pieces === []) {
                        throw new GestionConflictException('EXERCISE_NO_PIECE', $this->texts->t('boxes.error.EXERCISE_NO_PIECE', $locale), 409);
                    }
                    $device = $devices[$spot ? self::SIM_HAND_READER : self::SIM_EXIT_READER] ?? null;
                    foreach ($pieces as $piece) {
                        $repository->pieceFact($spot ? LossPieceEvent::SPOT_CHECK : LossPieceEvent::ZONE_SEEN, $now, (int) $piece['piece_id'], (int) $piece['tag_id'], $id,
                            $device !== null ? (int) $device['id'] : null, null, true, $spot ? [] : ['zone' => 'exit'], $now);
                        ++$written;
                    }
            }

            return ['scenario' => $scenario, 'written' => $written, 'box' => $this->sheet($id, $locale, true)];
        });
    }

    /** @return array<string, mixed> { items, total, limit, exercise, screen, scenarios } */
    public function exerciseList(string $locale): array
    {
        return $this->list($locale, true) + ['scenarios' => $this->scenarios($locale)];
    }

    /** @return array{deleted: int} tous les boîtiers simulés, leurs appareils et leurs faits */
    public function deleteExercise(): array
    {
        return $this->transaction(function (): array {
            $this->exerciseLock();

            return ['deleted' => $this->repository()->deleteExercise()];
        });
    }

    /** @return list<array{code: string, label: string, text: string}> */
    private function scenarios(string $locale): array
    {
        return array_map(fn (string $code): array => ['code' => $code, 'label' => $this->texts->t("boxes.scenario.$code.label", $locale), 'text' => $this->texts->t("boxes.scenario.$code.text", $locale)],
            self::SCENARIOS);
    }

    /**
     * @param array<string, mixed>       $box
     * @param list<array<string, mixed>> $devices
     *
     * @return array<string, mixed> ce que l'administrateur voit d'un boîtier ; jamais la clé, ni l'empreinte de la machine
     */
    private function view(array $box, array $devices, string $locale): array
    {
        $tz = $this->site->clock()->timezone();
        $stamp = static fn (mixed $stored): ?\DateTimeImmutable => $stored === null ? null : new \DateTimeImmutable((string) $stored, new \DateTimeZone('UTC'));
        $seen = $stamp($box['last_seen_at']);
        $silent = $seen !== null ? max(0, time() - $seen->getTimestamp()) : null;
        $active = $box['status'] === LossBox::ACTIVE;
        $online = $active && $silent !== null && $silent <= LossBoxChannelService::SILENT_AFTER;
        $offset = $box['clock_offset'] !== null ? (int) $box['clock_offset'] : null;

        return [
            'id' => (int) $box['id'], 'name' => (string) $box['name'], 'place' => $box['place'],
            'status' => (string) $box['status'], 'statusLabel' => $this->texts->t('boxes.status.' . $box['status'], $locale),
            'online' => $online, 'onlineLabel' => $this->texts->t('boxes.online.' . (!$active ? 'none' : ($online ? 'online' : 'silent')), $locale),
            'lastSeenAt' => $seen !== null ? LossAuditEntry::iso($seen) : null, 'lastSeenLabel' => $seen !== null ? LossTexts::dateTime($seen, $tz, $locale) : null,
            'silentSeconds' => $active && !$online ? $silent : null,
            'version' => $box['version'], 'arch' => $box['arch'],
            'enrolledAt' => ($enrolled = $stamp($box['enrolled_at'])) !== null ? LossAuditEntry::iso($enrolled) : null,
            'clockOffsetSeconds' => $offset, 'clockDoubt' => $active && $offset !== null && abs($offset) > LossBoxChannelService::CLOCK_TOLERANCE,
            'keyHint' => $box['key_hint'], 'exercise' => (bool) $box['exercise'],
            'devices' => array_map(function (array $device) use ($locale, $stamp): array {
                return ['id' => (int) $device['id'], 'serial' => (string) $device['serial'], 'kind' => (string) $device['kind'], 'kindLabel' => $this->texts->t('boxes.device.' . $device['kind'], $locale),
                    'model' => $device['model'], 'status' => (string) $device['status'], 'statusLabel' => $this->texts->t('boxes.deviceStatus.' . $device['status'], $locale),
                    'zone' => $device['zone'], 'zoneLabel' => $device['zone'] !== null ? $this->texts->t('boxes.zone.' . $device['zone'], $locale) : null,
                    'lastSeenAt' => LossAuditEntry::iso($stamp($device['last_seen_at']))];
            }, $devices),
            'createdAt' => LossAuditEntry::iso($stamp($box['created_at'])),
            'screen' => self::SCREEN,
        ];
    }

    /** @return array{host: ?string, key: string, code: ?string, text: string} ce qu'il faut donner à la machine pour l'inscrire */
    private function enrolment(string $key, string $locale): array
    {
        $code = $this->tenant->getTenantCode();
        $host = is_string($code) && $code !== '' ? ($this->hosts->describe($code)['host'] ?? null) : null;

        return ['host' => $host, 'key' => $key, 'code' => $host !== null ? $host . '#' . $key : null, 'text' => $this->texts->t('boxes.enrolment.text', $locale)];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: ?string, 1: ?string} nom et endroit, nettoyés ; `$required` : le nom est obligatoire
     */
    private function names(array $body, bool $required): array
    {
        $errors = [];
        $clean = static function (mixed $value): ?string {
            if (!is_string($value)) {
                return null;
            }
            $value = trim((string) preg_replace('/\s+/u', ' ', $value));

            return $value !== '' && mb_strlen($value) <= 80 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1 ? $value : null;
        };
        $name = null;
        if (array_key_exists('name', $body)) {
            $name = $clean($body['name']);
            if ($name === null) {
                $errors['name'] = is_string($body['name']) ? 'INVALID_VALUE' : 'MUST_BE_STRING';
            }
        } elseif ($required) {
            $errors['name'] = 'REQUIRED';
        }
        $place = null;
        if (($body['place'] ?? null) !== null) {
            $place = $clean($body['place']);
            if ($place === null && !(is_string($body['place']) && trim($body['place']) === '')) {
                $errors['place'] = is_string($body['place']) ? 'INVALID_VALUE' : 'MUST_BE_STRING';
            }
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }

        return [$name, $place];
    }

    /** @param array<string, mixed> $body un geste sans retour demande `confirm: true` */
    private function confirmed(array $body): void
    {
        if (($body['confirm'] ?? null) !== true) {
            throw new GestionValidationException(['confirm' => array_key_exists('confirm', $body) ? 'MUST_BE_TRUE' : 'REQUIRED']);
        }
    }

    /** @return array<string, mixed> le boîtier de ce genre, ou 404 */
    private function find(int $id, bool $exercise, string $locale): array
    {
        $box = $this->repository()->box($id);
        if ($box === null || (bool) $box['exercise'] !== $exercise) {
            throw new GestionConflictException('BOX_NOT_FOUND', $this->texts->t('boxes.error.BOX_NOT_FOUND', $locale), 404);
        }

        return $box;
    }

    /** Verrou des écritures d'exercice (le même que les pièces d'exercice) : créer, jouer et effacer ne se croisent pas */
    private function exerciseLock(): void
    {
        $this->emProvider->getEntityManager()->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(' . LossPieceRepository::EXERCISE_LOCK . ')');
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $conn->beginTransaction();
        try {
            $result = $work();
            $conn->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    private function repository(): LossBoxRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossBox::class);
    }
}
