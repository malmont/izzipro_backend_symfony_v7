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
 * - **La clé n'est montrée qu'une fois**, à la création et à la remise à zéro ; la base n'en garde qu'une empreinte liée au site et
 *   au secret de l'application. Aucune lecture ne la rend, ni elle ni l'empreinte de la machine.
 * - **Une clé = un boîtier = une machine.** Remettre la clé à zéro détache la machine (boîtier remplacé) et périme ses jetons ;
 *   révoquer est définitif.
 * - **« En ligne » se calcule** : le dernier signe de vie est plus récent que le délai de silence du site (5 minutes par défaut,
 *   `LossHealthService`). Rien n'est écrit pour le dire.
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
    public const SCENARIOS = ['heartbeat', 'network_cut', 'device_down', 'device_up', 'spot_check', 'zone_exit', 'counter_put', 'counter_take', ...LossPrintService::SCENARIOS, 'zone_move'];
    private const SIM_EXIT_READER = 'SIM-LECTEUR-SORTIE';
    private const SIM_HAND_READER = 'SIM-LECTEUR-MAIN';
    private const SIM_MAT = 'SIM-TAPIS';
    private const SIM_PRINTER = 'SIM-IMPRIMANTE';
    /** Lot L4-a : deux antennes de zone simulées, à la réserve et en rayon */
    private const SIM_STOCKROOM = 'SIM-ANTENNE-RESERVE';
    private const SIM_FLOOR = 'SIM-ANTENNE-RAYON';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenant,
        private readonly TenantPublicHostResolver $hosts,
        private readonly LossSite $site,
        private readonly LossTexts $texts,
        private readonly LossHealthService $health,
        private readonly LossBoxToken $tokens,
        private readonly ?LossPrintService $prints = null,
    ) {
    }

    /** @return array<string, mixed> { items, total, limit, exercise, screen } */
    public function list(string $locale, bool $exercise): array
    {
        $repository = $this->repository();
        $boxes = $repository->boxes($exercise);
        $devices = $repository->devicesOf(array_map(static fn (array $b): int => (int) $b['id'], $boxes), false);
        $silentAfter = $this->health->silentAfter();
        $extras = $this->deviceExtras(array_merge(...array_values($devices)));
        $items = array_map(fn (array $box): array => $this->view($box, $devices[(int) $box['id']], $locale, $silentAfter, $extras), $boxes);

        return ['items' => $items, 'total' => count($items), 'limit' => $exercise ? self::EXERCISE_BOX_LIMIT : self::BOX_LIMIT, 'exercise' => $exercise, 'screen' => self::SCREEN];
    }

    /** @return array<string, mixed> la fiche d'un boîtier : ses appareils, ses derniers faits, le nombre de faits de pièces reçus */
    public function sheet(int $id, string $locale, bool $exercise): array
    {
        $repository = $this->repository();
        $box = $this->find($id, $exercise, $locale);
        $all = $repository->devicesOf([$id])[$id];
        $serials = [];
        foreach ($all as $device) { // un fait peut désigner un appareil retiré depuis
            $serials[(int) $device['id']] = (string) $device['serial'];
        }
        $devices = array_values(array_filter($all, static fn (array $d): bool => $d['status'] !== LossDevice::RETIRED));
        $events = [];
        foreach ($repository->eventsOf($id, self::EVENTS_SHOWN) as $row) {
            $at = new \DateTimeImmutable((string) $row['occurred_at'], new \DateTimeZone('UTC'));
            $data = json_decode((string) $row['data'], true) ?: [];
            $events[] = ['id' => (int) $row['id'], 'type' => (string) $row['type'], 'label' => $this->texts->t('boxes.event.' . $row['type'], $locale),
                'at' => LossAuditEntry::iso($at), 'atLabel' => LossTexts::dateTime($at, $this->site->clock()->timezone(), $locale),
                'device' => $row['device_id'] !== null ? ($serials[(int) $row['device_id']] ?? null) : null,
                'silentSeconds' => isset($data['silentSeconds']) ? (int) $data['silentSeconds'] : null, 'byUser' => $row['user_id'] !== null];
        }

        return $this->view($box, $devices, $locale, $this->health->silentAfter(), $this->deviceExtras($devices)) + ['events' => $events, 'factsReceived' => $repository->pieceFactCount($id)];
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
            $id = $repository->create($name, $place, $this->tokens->keyHash($this->site(), $key), LossBoxKey::hint($key), false, $userId, $now);
            $repository->event($id, null, LossBoxEvent::BOX_CREATED, $userId, false, [], $now);

            return ['box' => $this->sheet($id, $locale, false), 'key' => $key, 'enrolment' => $this->enrolment($key, $locale)];
        });
    }

    /**
     * Le nom et le lieu d'un boîtier à ajouter, vérifiés (lot L3-a2 : avant de regarder un code de jumelage).
     *
     * @param array<string, mixed> $body
     *
     * @return array{0: string, 1: ?string}
     */
    public function checkedNames(array $body): array
    {
        return $this->names($body, true);
    }

    /**
     * Ajouter un boîtier JUMELÉ par code (lot L3-a2, § 16.16) : il attend sa machine, comme un boîtier ajouté par clé, mais
     * personne ne voit de clé. À la place de l'empreinte d'une clé, il porte celle qui le lie à ce site et à `$linked` (le code et
     * l'aléa de ce jumelage) : la machine qui a déposé ce code, et elle seule, s'y inscrira. Même plafond de boîtiers, compté sous
     * le même verrou.
     *
     * @return int l'identifiant du boîtier
     */
    public function createPaired(string $name, ?string $place, string $code, string $locale, int $userId): int
    {
        return $this->transaction(function () use ($name, $place, $code, $locale, $userId): int {
            $repository = $this->repository();
            $this->emProvider->getEntityManager()->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(' . LossBoxRepository::CREATE_LOCK . ')');
            if ($repository->liveCount(false) >= self::BOX_LIMIT) {
                throw new GestionConflictException('BOX_LIMIT_REACHED', $this->texts->t('boxes.error.BOX_LIMIT_REACHED', $locale, ['limit' => self::BOX_LIMIT]), 409, ['limit' => self::BOX_LIMIT]);
            }
            $now = self::now();
            $id = $repository->create($name, $place, $this->tokens->pairingKeyHash($this->site(), $code), null, false, $userId, $now);
            // la marque du jumelage reste dans ce fait (un fait ne se modifie jamais) : elle survit à une remise à zéro de la clé
            $repository->event($id, null, LossBoxEvent::BOX_CREATED, $userId, false, ['pairing' => true, 'ref' => $this->tokens->pairingRef($this->site(), $code)], $now);

            return $id;
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
        [$name, $place] = $this->names($body, false);

        return $this->transaction(function () use ($id, $body, $name, $place, $locale): array {
            // la ligne est verrouillée : deux renommages qui se croisent (l'un le nom, l'autre l'endroit) ne s'effacent pas
            $box = $this->repository()->box($id, true);
            if ($box === null || (bool) $box['exercise']) {
                throw new GestionConflictException('BOX_NOT_FOUND', $this->texts->t('boxes.error.BOX_NOT_FOUND', $locale), 404);
            }
            $this->repository()->rename($id, $name ?? (string) $box['name'], array_key_exists('place', $body) ? $place : $box['place'], self::now());

            return $this->sheet($id, $locale, false);
        });
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
            $repository->resetKey($id, $this->tokens->keyHash($this->site(), $key), LossBoxKey::hint($key), $now);
            // la machine d'avant est détachée : ce qu'elle disait posé ne l'est plus (fermé à l'heure de SON horloge, comme ses faits)
            $repository->closePresencesOf($repository->matIdsOf($id), LossBoxChannelService::boxTime(time(), $box['clock_offset']));
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
                // un boîtier révoqué ne dit plus rien de ses tapis, et rend leurs postes de caisse : un autre tapis peut les prendre
                $repository->closePresencesOf($repository->matIdsOf($id), LossBoxChannelService::boxTime(time(), $box['clock_offset']));
                $repository->releaseRegistersOf($id);
            }

            return $this->sheet($id, $locale, false);
        });
    }

    /**
     * Relier un tapis de comptoir à un poste de caisse `{ cashRegisterId }` (un tapis par poste, un poste par tapis ; `null` le
     * délie), ou donner sa zone à un lecteur `{ zone }` (`null` : aucune). Une seule des deux clés, selon le genre de l'appareil.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> la fiche du boîtier
     */
    public function assignDevice(int $id, int $deviceId, array $body, string $locale, int $userId): array
    {
        return $this->transaction(function () use ($id, $deviceId, $body, $locale, $userId): array {
            $repository = $this->repository();
            [$box, $device] = $this->lockedDevice($id, $deviceId, $locale);
            if ($device['status'] === LossDevice::RETIRED || $box['status'] === LossBox::REVOKED) {
                // un appareil retiré, ou celui d'un boîtier révoqué, ne se relie plus à rien
                throw new GestionConflictException('DEVICE_NOT_FOUND', $this->texts->t('boxes.error.DEVICE_NOT_FOUND', $locale), 404);
            }
            $mat = $device['kind'] === LossDevice::COUNTER_MAT;
            $key = $mat ? 'cashRegisterId' : 'zone';
            $errors = [];
            foreach (array_diff(['cashRegisterId', 'zone'], [$key]) as $other) {
                if (array_key_exists($other, $body)) {
                    $errors[$other] = 'NOT_FOR_THIS_DEVICE'; // un poste pour un tapis, une zone pour un lecteur
                }
            }
            if ($device['kind'] !== LossDevice::COUNTER_MAT && $device['kind'] !== LossDevice::READER) {
                $errors['device'] = 'NOT_ASSIGNABLE';
            } elseif (!array_key_exists($key, $body)) {
                $errors[$key] = 'REQUIRED';
            } elseif ($mat && $body[$key] !== null && (!is_int($body[$key]) || $body[$key] < 1 || $body[$key] > 2147483647)) {
                $errors[$key] = 'MUST_BE_POSITIVE_INTEGER';
            } elseif (!$mat && $body[$key] !== null && !in_array($body[$key], LossDevice::ZONES, true)) {
                $errors[$key] = 'INVALID_VALUE';
            }
            if ($errors !== []) {
                throw new GestionValidationException($errors);
            }
            $value = $body[$key];
            if ($mat && $value !== null) {
                if ($repository->cashRegister($value) === null) {
                    throw new GestionConflictException('CASH_REGISTER_NOT_FOUND', $this->texts->t('boxes.error.CASH_REGISTER_NOT_FOUND', $locale), 404);
                }
                // deux tapis reliés au même poste au même instant, depuis deux boîtiers : le second attend ici, puis lit le premier
                $repository->lockRegister($value);
                $holder = $repository->matOfRegisterId($value, $deviceId);
                if ($holder !== null) {
                    throw new GestionConflictException('REGISTER_ALREADY_HAS_MAT', $this->texts->t('boxes.error.REGISTER_ALREADY_HAS_MAT', $locale), 409, ['deviceId' => $holder]);
                }
            }
            $column = $mat ? 'cash_register_id' : 'zone';
            $current = $mat && $device[$column] !== null ? (int) $device[$column] : $device[$column];
            if ($current !== $value) { // la même valeur : rien à écrire
                try {
                    $repository->assignDevice($deviceId, $column, $value);
                } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                    // ceinture : l'index de la base a refusé malgré le verrou du poste. La transaction est perdue (aucune lecture de
                    // plus n'y est possible) : le refus part sans dire quel tapis tient le poste
                    throw new GestionConflictException('REGISTER_ALREADY_HAS_MAT', $this->texts->t('boxes.error.REGISTER_ALREADY_HAS_MAT', $locale), 409, ['deviceId' => null]);
                }
                $repository->event($id, $deviceId, LossBoxEvent::DEVICE_ASSIGNED, $userId, false, [$key => $value], self::now());
            }

            return $this->sheet($id, $locale, false);
        });
    }

    /**
     * Retirer un appareil remplacé `{ confirm: true }` : il ne compte plus, n'envoie plus de faits et rend son poste de caisse.
     * Si le boîtier l'annonce de nouveau, il revient. Retirer deux fois ne fait rien.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> la fiche du boîtier
     */
    public function retireDevice(int $id, int $deviceId, array $body, string $locale, int $userId): array
    {
        $this->confirmed($body);

        return $this->transaction(function () use ($id, $deviceId, $locale, $userId): array {
            $repository = $this->repository();
            [$box, $device] = $this->lockedDevice($id, $deviceId, $locale);
            if ($device['status'] !== LossDevice::RETIRED) {
                $now = self::now();
                $repository->closePresencesOf([$deviceId], LossBoxChannelService::boxTime(time(), $box['clock_offset']));
                $repository->retireDevice($deviceId);
                $repository->event($id, $deviceId, LossBoxEvent::DEVICE_RETIRED, $userId, false, [], $now);
            }

            return $this->sheet($id, $locale, false);
        });
    }

    /**
     * Un vrai boîtier et un de SES appareils, verrouillés (le boîtier d'abord, comme partout).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function lockedDevice(int $id, int $deviceId, string $locale): array
    {
        $repository = $this->repository();
        $box = $repository->box($id, true);
        if ($box === null || (bool) $box['exercise']) {
            throw new GestionConflictException('BOX_NOT_FOUND', $this->texts->t('boxes.error.BOX_NOT_FOUND', $locale), 404);
        }
        $device = $repository->device($id, $deviceId, true) ?? throw new GestionConflictException('DEVICE_NOT_FOUND', $this->texts->t('boxes.error.DEVICE_NOT_FOUND', $locale), 404);

        return [$box, $device];
    }

    // ----- Mode exercice : le boîtier simulé -----

    /**
     * Un boîtier simulé, déjà « inscrit », avec ses appareils simulés : deux lecteurs (la sortie, le lecteur à main), un tapis de
     * comptoir et une imprimante à puce (lot L3-c).
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
            foreach ([[self::SIM_EXIT_READER, LossDevice::READER, 'exit'], [self::SIM_HAND_READER, LossDevice::READER, null], [self::SIM_MAT, LossDevice::COUNTER_MAT, null],
                [self::SIM_PRINTER, LossDevice::PRINTER, null], [self::SIM_STOCKROOM, LossDevice::READER, 'stockroom'], [self::SIM_FLOOR, LossDevice::READER, 'floor']] as [$serial, $kind, $zone]) {
                $device = $repository->insertDevice($id, $serial, $kind, null, LossDevice::OK, $zone, true, $now);
                $repository->event($id, $device, LossBoxEvent::DEVICE_SEEN, null, true, ['kind' => $kind], $now);
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
    public function play(int $id, array $body, string $locale, int $userId = 0): array
    {
        $scenario = $body['scenario'] ?? null;
        if (!is_string($scenario) || !in_array($scenario, self::SCENARIOS, true)) {
            throw new GestionValidationException(['scenario' => array_key_exists('scenario', $body) ? 'INVALID_VALUE' : 'REQUIRED']);
        }

        return $this->transaction(function () use ($id, $scenario, $locale, $userId): array {
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
            $job = null;
            switch ($scenario) {
                case 'print_ok':
                case 'print_refused':
                case 'print_no_labels':
                    // l'imprimante à puce simulée : un travail d'exercice sur des pièces d'exercice sans puce, et son résultat
                    $printer = $devices[self::SIM_PRINTER] ?? null;
                    if ($printer === null || $this->prints === null) {
                        throw new GestionConflictException('EXERCISE_NO_PRINTER', $this->texts->t('prints.error.EXERCISE_NO_PRINTER', $locale), 409);
                    }
                    $played = $this->prints->play($id, $printer, $scenario, $locale, $userId, $now);
                    $written = $played['written'];
                    $job = $played['jobId'];
                    break;
                case 'heartbeat':
                    $repository->seen($id, $now, null, 0);
                    break;
                case 'network_cut':
                    // deux heures sans réseau, puis le retour : le fait dit le trou
                    $repository->event($id, null, LossBoxEvent::BOX_BACK, null, true, ['silentSeconds' => 7200], $now);
                    $repository->seen($id, $now, null, 0);
                    $repository->closePresencesOf($repository->matIdsOf($id), $now); // comme un vrai boîtier : ce qui était posé ne l'est plus pour nous
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
                case 'zone_move':
                    // Lot L4-a : une pièce d'exercice est vue à la réserve, en part, puis arrive en rayon. Trois faits, et la présence par
                    // zone qui en découle : la fiche de la pièce dit où elle a été vue en dernier
                    $from = $devices[self::SIM_STOCKROOM] ?? null;
                    $to = $devices[self::SIM_FLOOR] ?? null;
                    if ($from === null || $to === null) {
                        throw new GestionConflictException('EXERCISE_NO_ZONE_READER', $this->texts->t('boxes.error.EXERCISE_NO_ZONE_READER', $locale), 409);
                    }
                    $pieces = $repository->exercisePiecesWithTag(1);
                    if ($pieces === []) {
                        throw new GestionConflictException('EXERCISE_NO_PIECE', $this->texts->t('boxes.error.EXERCISE_NO_PIECE', $locale), 409);
                    }
                    [$piece, $tag] = [(int) $pieces[0]['piece_id'], (int) $pieces[0]['tag_id']];
                    $start = time();
                    foreach ([[LossPieceEvent::ZONE_SEEN, $from, 'stockroom', -40], [LossPieceEvent::ZONE_LEFT, $from, 'stockroom', -10], [LossPieceEvent::ZONE_SEEN, $to, 'floor', 0]] as [$type, $device, $zone, $shift]) {
                        $at = gmdate('Y-m-d H:i:s', $start + $shift);
                        $repository->pieceFact($type, $at, $piece, $tag, $id, (int) $device['id'], null, true, ['zone' => $zone], $now);
                        $type === LossPieceEvent::ZONE_SEEN ? $repository->openZonePresence((int) $device['id'], $tag, $zone, $at, true) : $repository->closeZonePresence((int) $device['id'], $tag, $zone, $at, false, true);
                        ++$written;
                    }
                    break;
                case 'counter_put':
                case 'counter_take':
                    // une pièce d'exercice posée sur le tapis simulé, puis reprise : le fait, et la présence qui en découle
                    $mat = $devices[self::SIM_MAT] ?? null;
                    if ($mat === null) {
                        throw new GestionConflictException('EXERCISE_NO_PIECE', $this->texts->t('boxes.error.EXERCISE_NO_PIECE', $locale), 409);
                    }
                    $matId = (int) $mat['id'];
                    $onMat = $repository->openPresencesOf($matId);
                    if ($scenario === 'counter_put' && $onMat === []) {
                        $pieces = $repository->exercisePiecesWithTag(1);
                        if ($pieces === []) {
                            throw new GestionConflictException('EXERCISE_NO_PIECE', $this->texts->t('boxes.error.EXERCISE_NO_PIECE', $locale), 409);
                        }
                        $repository->pieceFact(LossPieceEvent::COUNTER_SEEN, $now, (int) $pieces[0]['piece_id'], (int) $pieces[0]['tag_id'], $id, $matId, null, true, ['zone' => 'counter'], $now);
                        $repository->openPresence($matId, (int) $pieces[0]['tag_id'], $now, true);
                        $written = 1;
                    } elseif ($scenario === 'counter_take') {
                        // c'est la pièce POSÉE qui part (pas la dernière créée)
                        foreach ($onMat as $present) {
                            $repository->pieceFact(LossPieceEvent::COUNTER_LEFT, $now, $present['piece_id'] !== null ? (int) $present['piece_id'] : null, (int) $present['tag_id'], $id, $matId, null, true, ['zone' => 'counter'], $now);
                            ++$written;
                        }
                        $repository->closePresencesOf([$matId], $now);
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
                        if (!$spot && $device !== null) {
                            $repository->openZonePresence((int) $device['id'], (int) $piece['tag_id'], 'exit', $now, true); // lot L4-a : vue en dernier à la sortie
                        }
                        ++$written;
                    }
            }

            return ['scenario' => $scenario, 'written' => $written, 'box' => $this->sheet($id, $locale, true)] + ($job !== null ? ['printJobId' => $job] : []);
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
    private function view(array $box, array $devices, string $locale, int $silentAfter, array $extras = ['registers' => [], 'onMat' => []]): array
    {
        $tz = $this->site->clock()->timezone();
        $stamp = static fn (mixed $stored): ?\DateTimeImmutable => $stored === null ? null : new \DateTimeImmutable((string) $stored, new \DateTimeZone('UTC'));
        $seen = $stamp($box['last_seen_at']);
        $silent = $seen !== null ? max(0, time() - $seen->getTimestamp()) : null;
        $active = $box['status'] === LossBox::ACTIVE;
        $online = $active && $silent !== null && $silent <= $silentAfter;
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
            // ajouté par un code de jumelage (lot L3-a2) : personne n'a vu de clé ; faux dès qu'un administrateur lui en donne une
            'paired' => !(bool) $box['exercise'] && $box['key_hint'] === null,
            'devices' => array_map(function (array $device) use ($locale, $stamp, $extras): array {
                $register = $device['cash_register_id'] !== null ? (int) $device['cash_register_id'] : null;
                $mat = $device['kind'] === LossDevice::COUNTER_MAT;

                return ['id' => (int) $device['id'], 'serial' => (string) $device['serial'], 'kind' => (string) $device['kind'], 'kindLabel' => $this->texts->t('boxes.device.' . $device['kind'], $locale),
                    'model' => $device['model'], 'status' => (string) $device['status'], 'statusLabel' => $this->texts->t('boxes.deviceStatus.' . $device['status'], $locale),
                    'zone' => $device['zone'], 'zoneLabel' => $device['zone'] !== null ? $this->texts->t('boxes.zone.' . $device['zone'], $locale) : null,
                    // un tapis de comptoir : son poste de caisse, et combien de puces sont posées dessus en ce moment
                    'cashRegister' => $register !== null ? ['id' => $register, 'name' => $extras['registers'][$register] ?? null] : null,
                    'onMat' => $mat ? ($extras['onMat'][(int) $device['id']] ?? 0) : null,
                    'lastSeenAt' => LossAuditEntry::iso($stamp($device['last_seen_at']))];
            }, $devices),
            'createdAt' => LossAuditEntry::iso($stamp($box['created_at'])),
            'screen' => self::SCREEN,
        ];
    }

    /** Le site de la requête : la clé d'un boîtier n'a de sens que pour lui */
    private function site(): string
    {
        $code = $this->tenant->getTenantCode();
        if (!is_string($code) || $code === '') {
            throw new \LogicException('Aucun site : un boîtier se crée dans un site.');
        }

        return $code;
    }

    /**
     * Ce que les appareils montrent en plus : le nom du poste d'un tapis, et le nombre de puces posées sur chaque tapis.
     *
     * @param list<array<string, mixed>> $devices
     *
     * @return array{registers: array<int, string>, onMat: array<int, int>}
     */
    private function deviceExtras(array $devices): array
    {
        $registers = [];
        $mats = [];
        foreach ($devices as $device) {
            if ($device['cash_register_id'] !== null) {
                $registers[] = (int) $device['cash_register_id'];
            }
            if ($device['kind'] === LossDevice::COUNTER_MAT) {
                $mats[] = (int) $device['id'];
            }
        }
        $repository = $this->repository();

        return ['registers' => $repository->cashRegisterNames(array_values(array_unique($registers))), 'onMat' => $repository->presentCounts($mats)];
    }

    /** @return array{host: ?string, key: string, code: ?string, text: string} ce qu'il faut donner à la machine pour l'inscrire */
    private function enrolment(string $key, string $locale): array
    {
        $host = $this->hosts->describe($this->site())['host'] ?? null;

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
