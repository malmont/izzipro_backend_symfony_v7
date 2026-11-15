<?php

namespace App\LossPrevention\Services;

use App\LossPrevention\Entity\LossBox;
use App\LossPrevention\Entity\LossBoxEvent;
use App\LossPrevention\Entity\LossDevice;
use App\LossPrevention\Entity\LossPieceEvent;
use App\LossPrevention\Repository\LossBoxRepository;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;

/**
 * Ce que la plateforme répond à un BOÎTIER (lot L3-a, 15/11/2026, `docs/prevention-pertes.md` § 16) : inscription, jeton, signe de
 * vie, réglages, lots de faits. Le boîtier appelle, la plateforme ne le joint jamais.
 *
 * - **Le boîtier n'est pas un utilisateur.** Son jeton lui donne exactement ceci, sur son site : dire qu'il est là, lire SES
 *   réglages (ses appareils, les zones, les faits acceptés), envoyer des faits. Rien ici ne lit une vente, un client, le catalogue
 *   ni un constat ; aucune réponse ne porte de nom d'article, de prix ni de personne.
 * - **Chaque appel relit le boîtier** : actif, de la génération du jeton. Révoquer un boîtier ou remettre sa clé à zéro vaut à
 *   l'appel suivant.
 * - **Faits rejouables** : un fait porte une clé d'idempotence ; un fait déjà reçu répond « déjà connu ». Un fait refusé ne fait
 *   jamais échouer le lot, et il n'est pas à renvoyer.
 * - **Le signe de vie n'est pas un fait** : il met à jour l'état du boîtier et de ses appareils ; seul un CHANGEMENT écrit un fait
 *   (appareil vu pour la première fois, en panne, revenu ; boîtier revenu après un silence).
 * - Aucun état de pièce ne change en L3-a : les faits de pièces sont gardés tels quels (les lots L3-b et L4 les liront).
 */
class LossBoxChannelService
{
    public const MAX_FACTS = 200;
    public const HEARTBEAT_SECONDS = 60;
    /** Sans signe de vie depuis plus longtemps, le boîtier est dit silencieux ; son retour écrit un fait */
    public const SILENT_AFTER = 300;
    /** Écart d'horloge toléré (secondes) : au-delà, les faits portent un doute sur leur heure */
    public const CLOCK_TOLERANCE = 60;
    private const MACHINE = '/\A[A-Za-z0-9_.:-]{16,128}\z/';
    private const VERSION = '/\A[0-9A-Za-z.+_-]{1,40}\z/';
    private const SERIAL = '/\A[A-Za-z0-9_.:-]{1,64}\z/';
    private const MODEL = '/\A[\p{L}\p{N} _.()\/+-]{1,60}\z/u';
    private const FACT_KEY = '/\A[A-Za-z0-9_-]{8,48}\z/';
    private const TIME = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})\z/';
    public const ARCHS = ['amd64', 'arm64'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenant,
        private readonly LossBoxToken $tokens,
    ) {
    }

    /**
     * Le boîtier de la requête, d'après son jeton : signature de ce site, non expiré, boîtier réel, actif, de la même génération.
     *
     * @return array<string, mixed> la ligne du boîtier, avec `token_expires_at`
     */
    public function authenticate(mixed $token): array
    {
        if (!is_string($token) || $token === '') {
            throw new LossBoxException('BOX_TOKEN_REQUIRED', 'En-tête ' . LossBoxToken::HEADER . ' requis.', 401);
        }
        $claims = $this->tokens->read($this->tenantCode(), $token);
        if ($claims === null) {
            throw new LossBoxException('BOX_TOKEN_INVALID', 'Jeton de boîtier invalide.', 401);
        }
        $box = $this->boxOfToken($claims);
        if ($claims['expiresAt'] <= time()) {
            throw new LossBoxException('BOX_TOKEN_EXPIRED', 'Jeton de boîtier expiré : demandez-en un neuf.', 401);
        }

        return $box + ['token_expires_at' => $claims['expiresAt']];
    }

    /**
     * Inscription d'une machine avec la clé du boîtier `{ key, machine, version, arch }`. La clé est liée à CETTE machine : une
     * autre machine est refusée tant que l'administrateur n'a pas remis la clé à zéro ; la même machine peut se réinscrire.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> { box, token, expiresAt, serverTime, settings }
     */
    public function enrol(array $body): array
    {
        $errors = [];
        foreach (['machine' => self::MACHINE, 'version' => self::VERSION] as $field => $pattern) {
            if (!is_string($body[$field] ?? null) || preg_match($pattern, $body[$field]) !== 1) {
                $errors[$field] = array_key_exists($field, $body) ? 'INVALID_VALUE' : 'REQUIRED';
            }
        }
        if (!in_array($body['arch'] ?? null, self::ARCHS, true)) {
            $errors['arch'] = array_key_exists('arch', $body) ? 'INVALID_VALUE' : 'REQUIRED';
        }
        if (!array_key_exists('key', $body)) {
            $errors['key'] = 'REQUIRED';
        }
        if ($errors !== []) {
            throw new LossBoxException('VALIDATION_FAILED', 'Inscription invalide.', 422, ['fields' => $errors]);
        }
        // une saisie qui n'a pas la forme d'une clé reçoit la réponse d'une clé inconnue
        $key = LossBoxKey::canonical($body['key']) ?? throw new LossBoxException('BOX_KEY_INVALID', 'Clé de boîtier inconnue.', 404);
        $machine = hash('sha256', $body['machine']);

        return $this->transaction(function () use ($key, $machine, $body): array {
            $repository = $this->repository();
            $box = $repository->boxByKeyHash(LossBoxKey::hash($key)) ?? throw new LossBoxException('BOX_KEY_INVALID', 'Clé de boîtier inconnue.', 404);
            if ($box['status'] === LossBox::REVOKED) {
                throw new LossBoxException('BOX_REVOKED', 'Ce boîtier a été révoqué.', 403);
            }
            if ($box['machine_hash'] !== null && !hash_equals((string) $box['machine_hash'], $machine)) {
                throw new LossBoxException('BOX_KEY_ALREADY_ENROLLED', 'Cette clé est déjà liée à une autre machine : l\'administrateur doit la remettre à zéro.', 409);
            }
            $now = self::now();
            $generation = $repository->enrol((int) $box['id'], $machine, $body['version'], $body['arch'], $now);
            $repository->event((int) $box['id'], null, LossBoxEvent::BOX_ENROLLED, null, false, ['version' => $body['version'], 'arch' => $body['arch'], 'again' => $box['machine_hash'] !== null], $now);
            $token = $this->tokens->issue($this->tenantCode(), (int) $box['id'], $generation);

            return ['box' => ['id' => (int) $box['id'], 'name' => (string) $box['name']], 'token' => $token['token'], 'expiresAt' => self::iso($token['expiresAt']),
                'serverTime' => self::iso(time()), 'settings' => $this->settingsOf((int) $box['id'])];
        });
    }

    /**
     * Échange d'un jeton (encore bon, ou expiré depuis moins de 30 jours) contre un neuf `{ machine }` : il faut la signature de ce
     * site, un boîtier actif de la même génération, et l'empreinte de la machine inscrite.
     *
     * @param array<string, mixed> $body
     *
     * @return array{token: string, expiresAt: string, serverTime: string}
     */
    public function renew(mixed $token, array $body): array
    {
        $claims = is_string($token) ? $this->tokens->read($this->tenantCode(), $token) : null;
        if ($claims === null || $claims['expiresAt'] + LossBoxToken::GRACE <= time()) {
            throw new LossBoxException('BOX_TOKEN_INVALID', 'Jeton de boîtier invalide : il faut une nouvelle inscription.', 401);
        }
        $box = $this->boxOfToken($claims);
        if (!is_string($body['machine'] ?? null) || !hash_equals((string) $box['machine_hash'], hash('sha256', $body['machine']))) {
            throw new LossBoxException('BOX_TOKEN_INVALID', 'Jeton de boîtier invalide : il faut une nouvelle inscription.', 401);
        }
        $fresh = $this->tokens->issue($this->tenantCode(), (int) $box['id'], (int) $box['generation']);

        return ['token' => $fresh['token'], 'expiresAt' => self::iso($fresh['expiresAt']), 'serverTime' => self::iso(time())];
    }

    /**
     * Signe de vie `{ now, version?, devices: [{ serial, kind, model?, status }] }` : dernier contact, écart d'horloge, état des
     * appareils. Un appareil connu que le boîtier n'annonce plus est noté en panne. Rend un jeton neuf dès la mi-vie du sien.
     *
     * @param array<string, mixed> $box  le boîtier authentifié
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> { serverTime, clockOffsetSeconds, clockDoubt, token, settings }
     */
    public function heartbeat(array $box, array $body): array
    {
        $errors = [];
        $boxNow = self::time($body['now'] ?? null);
        if ($boxNow === null) {
            $errors['now'] = array_key_exists('now', $body) ? 'INVALID_VALUE' : 'REQUIRED';
        }
        $version = $body['version'] ?? null;
        if ($version !== null && (!is_string($version) || preg_match(self::VERSION, $version) !== 1)) {
            $errors['version'] = 'INVALID_VALUE';
        }
        $devices = $body['devices'] ?? [];
        $announced = [];
        if (!is_array($devices) || !array_is_list($devices)) {
            $errors['devices'] = 'MUST_BE_LIST';
        } elseif (count($devices) > LossDevice::MAX_PER_BOX) {
            $errors['devices'] = 'TOO_MANY';
        } else {
            foreach ($devices as $i => $device) {
                if (!is_array($device)) {
                    $errors["devices[$i]"] = 'INVALID_VALUE';
                    continue;
                }
                $serial = $device['serial'] ?? null;
                if (!is_string($serial) || preg_match(self::SERIAL, $serial) !== 1) {
                    $errors["devices[$i].serial"] = array_key_exists('serial', $device) ? 'INVALID_VALUE' : 'REQUIRED';
                } elseif (isset($announced[$serial])) {
                    $errors["devices[$i].serial"] = 'DUPLICATE';
                }
                if (!in_array($device['kind'] ?? null, LossDevice::KINDS, true)) {
                    $errors["devices[$i].kind"] = array_key_exists('kind', $device) ? 'INVALID_VALUE' : 'REQUIRED';
                }
                if (!in_array($device['status'] ?? null, [LossDevice::OK, LossDevice::DOWN], true)) {
                    $errors["devices[$i].status"] = array_key_exists('status', $device) ? 'INVALID_VALUE' : 'REQUIRED';
                }
                $model = $device['model'] ?? null;
                if ($model !== null && (!is_string($model) || preg_match(self::MODEL, $model) !== 1)) {
                    $errors["devices[$i].model"] = 'INVALID_VALUE';
                }
                if (is_string($serial)) {
                    $announced[$serial] = ['kind' => $device['kind'] ?? null, 'status' => $device['status'] ?? null, 'model' => $model];
                }
            }
        }
        if ($errors !== []) {
            throw new LossBoxException('VALIDATION_FAILED', 'Signe de vie invalide.', 422, ['fields' => $errors]);
        }
        $boxId = (int) $box['id'];

        return $this->transaction(function () use ($boxId, $boxNow, $version, $announced, $box): array {
            $repository = $this->repository();
            // la ligne du boîtier est verrouillée : deux signes de vie du même boîtier ne se croisent pas
            $current = $repository->box($boxId, true);
            if ($current === null || $current['status'] !== LossBox::ACTIVE || (int) $current['generation'] !== (int) $box['generation']) {
                throw new LossBoxException('BOX_TOKEN_INVALID', 'Jeton de boîtier invalide.', 401);
            }
            $serverNow = time();
            $now = gmdate('Y-m-d H:i:s', $serverNow);
            $known = [];
            foreach ($repository->devicesOf([$boxId])[$boxId] as $row) {
                $known[(string) $row['serial']] = $row;
            }
            $errors = [];
            $i = 0;
            foreach ($announced as $serial => $device) {
                if (isset($known[$serial]) && $known[$serial]['kind'] !== $device['kind']) {
                    $errors["devices[$i].kind"] = 'MISMATCH'; // le genre d'un appareil ne change pas
                }
                ++$i;
            }
            if (count($known + $announced) > LossDevice::MAX_PER_BOX) {
                $errors['devices'] = 'TOO_MANY';
            }
            if ($errors !== []) {
                throw new LossBoxException('VALIDATION_FAILED', 'Signe de vie invalide.', 422, ['fields' => $errors]);
            }
            $silent = $current['last_seen_at'] !== null ? $serverNow - self::stamp((string) $current['last_seen_at']) : 0;
            if ($silent > self::SILENT_AFTER) {
                $repository->event($boxId, null, LossBoxEvent::BOX_BACK, null, false, ['silentSeconds' => $silent, 'since' => self::iso(self::stamp((string) $current['last_seen_at']))], $now);
            }
            $offset = max(-2147483647, min(2147483647, $boxNow - $serverNow));
            $repository->seen($boxId, $now, $version, $offset);
            foreach ($announced as $serial => $device) {
                if (!isset($known[$serial])) {
                    $id = $repository->insertDevice($boxId, (string) $serial, $device['kind'], $device['model'], $device['status'], null, false, $now);
                    $repository->event($boxId, $id, LossBoxEvent::DEVICE_SEEN, null, false, ['kind' => $device['kind']], $now);
                    if ($device['status'] === LossDevice::DOWN) {
                        $repository->event($boxId, $id, LossBoxEvent::SOURCE_DOWN, null, false, ['reason' => 'reported'], $now);
                    }
                    continue;
                }
                $id = (int) $known[$serial]['id'];
                if ($known[$serial]['status'] !== $device['status']) {
                    $repository->event($boxId, $id, $device['status'] === LossDevice::DOWN ? LossBoxEvent::SOURCE_DOWN : LossBoxEvent::SOURCE_UP, null, false, ['reason' => 'reported'], $now);
                }
                $repository->deviceStatus($id, $device['status'], $device['model'], true, $now);
            }
            // un appareil connu que le boîtier n'annonce plus : en panne (débranché), une seule fois
            foreach (array_diff_key($known, $announced) as $row) {
                if ($row['status'] !== LossDevice::DOWN) {
                    $repository->event($boxId, (int) $row['id'], LossBoxEvent::SOURCE_DOWN, null, false, ['reason' => 'absent'], $now);
                    $repository->deviceStatus((int) $row['id'], LossDevice::DOWN, null, false, $now);
                }
            }
            $token = null;
            if ((int) $box['token_expires_at'] - $serverNow < LossBoxToken::RENEW_BEFORE) {
                $fresh = $this->tokens->issue($this->tenantCode(), $boxId, (int) $current['generation'], $serverNow);
                $token = ['token' => $fresh['token'], 'expiresAt' => self::iso($fresh['expiresAt'])];
            }

            return ['serverTime' => self::iso($serverNow), 'clockOffsetSeconds' => $offset, 'clockDoubt' => abs($offset) > self::CLOCK_TOLERANCE, 'token' => $token,
                'settings' => $this->settingsOf($boxId)];
        });
    }

    /**
     * Réglages du boîtier : ce qu'il a le droit de savoir, et rien d'autre.
     *
     * @param array<string, mixed> $box le boîtier authentifié
     *
     * @return array<string, mixed>
     */
    public function settings(array $box): array
    {
        return ['box' => ['id' => (int) $box['id'], 'name' => (string) $box['name']], 'serverTime' => self::iso(time())] + $this->settingsOf((int) $box['id']);
    }

    /**
     * Un lot de faits `{ facts: [{ key, type, at, device, zone?, seq?, data: { identifier, technology? } }] }`, de 1 à 200. Chaque
     * fait a sa réponse : `accepted`, `duplicate` (déjà reçu : même clé) ou `refused` avec un code (à ne pas renvoyer).
     *
     * @param array<string, mixed> $box  le boîtier authentifié
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> { received, accepted, duplicates, refused, results [{ index, key, status, code }], serverTime }
     */
    public function facts(array $box, array $body): array
    {
        $facts = $body['facts'] ?? null;
        if (!is_array($facts) || !array_is_list($facts) || $facts === []) {
            throw new LossBoxException('VALIDATION_FAILED', 'Lot de faits invalide.', 422, ['fields' => ['facts' => array_key_exists('facts', $body) ? 'MUST_BE_LIST' : 'REQUIRED']]);
        }
        if (count($facts) > self::MAX_FACTS) {
            throw new LossBoxException('FACTS_LIMIT_EXCEEDED', sprintf('Au plus %d faits par lot.', self::MAX_FACTS), 422, ['max' => self::MAX_FACTS, 'received' => count($facts)]);
        }
        $boxId = (int) $box['id'];
        $repository = $this->repository();
        $devices = [];
        foreach ($repository->devicesOf([$boxId])[$boxId] as $row) {
            $devices[(string) $row['serial']] = $row;
        }
        $serverNow = time();
        // Première passe : la forme de chaque fait, sans la base
        $checked = [];
        $identifiers = [];
        foreach ($facts as $index => $fact) {
            $checked[$index] = $this->checkFact($fact, $devices, $serverNow);
            if (isset($checked[$index]['identifier'])) {
                $identifiers[$checked[$index]['identifier']] = true;
            }
        }
        $tags = $repository->tagsByIdentifier(array_map('strval', array_keys($identifiers)));
        // l'heure d'un fait est douteuse si l'horloge du boîtier n'a pas été mesurée, ou si elle s'écarte de celle du serveur
        $offsetDoubt = $box['clock_offset'] === null || abs((int) $box['clock_offset']) > self::CLOCK_TOLERANCE;

        return $this->transaction(function () use ($checked, $tags, $boxId, $repository, $serverNow, $offsetDoubt): array {
            $now = gmdate('Y-m-d H:i:s', $serverNow);
            $results = [];
            $counts = ['accepted' => 0, 'duplicate' => 0, 'refused' => 0];
            foreach ($checked as $index => $fact) {
                $refusal = $fact['refusal'] ?? null;
                $tag = $refusal === null ? ($tags[$fact['identifier']] ?? null) : null;
                if ($refusal === null && $tag === null) {
                    $refusal = 'TAG_UNKNOWN';
                } elseif ($refusal === null && (bool) $tag['exercise']) {
                    $refusal = 'TAG_SIMULATED'; // un vrai boîtier ne lit pas une puce d'exercice
                }
                if ($refusal !== null) {
                    ++$counts['refused'];
                    $results[] = ['index' => $index, 'key' => $fact['key'], 'status' => 'refused', 'code' => $refusal];
                    continue;
                }
                $data = array_filter(['zone' => $fact['zone'], 'seq' => $fact['seq']], static fn (mixed $v): bool => $v !== null)
                    + ($offsetDoubt || $fact['at'] > $serverNow + self::CLOCK_TOLERANCE ? ['clockDoubt' => true] : []);
                $written = $repository->pieceFact($fact['type'], gmdate('Y-m-d H:i:s', $fact['at']), $tag['piece_id'] !== null ? (int) $tag['piece_id'] : null, (int) $tag['id'], $boxId,
                    $fact['deviceId'], 'b' . $boxId . ':' . $fact['key'], false, $data, $now);
                $status = $written ? 'accepted' : 'duplicate';
                ++$counts[$status];
                $results[] = ['index' => $index, 'key' => $fact['key'], 'status' => $status, 'code' => null];
            }

            return ['received' => count($checked), 'accepted' => $counts['accepted'], 'duplicates' => $counts['duplicate'], 'refused' => $counts['refused'], 'results' => $results,
                'serverTime' => self::iso($serverNow)];
        });
    }

    /**
     * La forme d'un fait : sa clé, son genre, son heure, son appareil, sa zone, l'identifiant de la puce.
     *
     * @param array<string, array<string, mixed>> $devices appareils du boîtier, par numéro de série
     *
     * @return array<string, mixed> { key, refusal } ou { key, type, at, deviceId, zone, seq, identifier }
     */
    private function checkFact(mixed $fact, array $devices, int $serverNow): array
    {
        $key = is_array($fact) && is_string($fact['key'] ?? null) && preg_match(self::FACT_KEY, $fact['key']) === 1 ? $fact['key'] : null;
        $refused = static fn (string $code): array => ['key' => $key, 'refusal' => $code];
        if (!is_array($fact) || $key === null) {
            return $refused('FACT_INVALID');
        }
        $type = $fact['type'] ?? null;
        if (!is_string($type) || !in_array($type, LossPieceEvent::FROM_BOX, true)) {
            return $refused('FACT_TYPE_UNKNOWN');
        }
        $at = self::time($fact['at'] ?? null);
        $seq = $fact['seq'] ?? null;
        if ($at === null || ($seq !== null && (!is_int($seq) || $seq < 0))) {
            return $refused('FACT_INVALID');
        }
        $device = is_string($fact['device'] ?? null) ? ($devices[$fact['device']] ?? null) : null;
        if ($device === null) {
            return $refused('DEVICE_UNKNOWN'); // le boîtier annonce ses appareils dans son signe de vie, avant leurs faits
        }
        if ($device['kind'] !== LossDevice::READER) {
            return $refused('DEVICE_KIND_INVALID');
        }
        $zone = $fact['zone'] ?? null;
        if (($zone === null && $type === LossPieceEvent::ZONE_SEEN) || ($zone !== null && !in_array($zone, LossDevice::ZONES, true))) {
            return $refused('ZONE_INVALID');
        }
        $data = $fact['data'] ?? null;
        $raw = is_array($data) ? ($data['identifier'] ?? null) : null;
        $technology = is_array($data) ? ($data['technology'] ?? null) : null;
        if (!is_string($raw) || strlen($raw) > 128 || ($technology !== null && !in_array($technology, LossTagIdentifier::REAL, true))) {
            return $refused('TAG_INVALID');
        }
        if (stripos(ltrim($raw), 'SIM-') === 0) {
            return $refused('TAG_SIMULATED');
        }
        $identifier = $technology !== null ? LossTagIdentifier::canonical($raw, $technology) : LossTagIdentifier::loose($raw);
        if ($identifier === null) {
            return $refused('TAG_INVALID');
        }

        return ['key' => $key, 'type' => $type, 'at' => $at, 'deviceId' => (int) $device['id'], 'zone' => $zone, 'seq' => $seq, 'identifier' => $identifier];
    }

    /** @return array<string, mixed> les réglages servis au boîtier : cadence, faits acceptés, zones, ses appareils */
    private function settingsOf(int $boxId): array
    {
        $devices = [];
        foreach ($this->repository()->devicesOf([$boxId])[$boxId] as $row) {
            $devices[] = ['id' => (int) $row['id'], 'serial' => (string) $row['serial'], 'kind' => (string) $row['kind'], 'zone' => $row['zone'],
                'cashRegisterId' => $row['cash_register_id'] !== null ? (int) $row['cash_register_id'] : null];
        }

        return ['heartbeatSeconds' => self::HEARTBEAT_SECONDS, 'facts' => ['types' => LossPieceEvent::FROM_BOX, 'maxBatch' => self::MAX_FACTS], 'zones' => LossDevice::ZONES, 'devices' => $devices];
    }

    /**
     * Le boîtier que désigne un jeton à la signature bonne : réel, actif, de la même génération.
     *
     * @param array{boxId: int, generation: int, expiresAt: int} $claims
     *
     * @return array<string, mixed>
     */
    private function boxOfToken(array $claims): array
    {
        $box = $this->repository()->box($claims['boxId']);
        if ($box === null || (bool) $box['exercise']) {
            throw new LossBoxException('BOX_TOKEN_INVALID', 'Jeton de boîtier invalide.', 401);
        }
        if ($box['status'] === LossBox::REVOKED) {
            throw new LossBoxException('BOX_REVOKED', 'Ce boîtier a été révoqué.', 403);
        }
        if ($box['status'] !== LossBox::ACTIVE || (int) $box['generation'] !== $claims['generation'] || $box['machine_hash'] === null) {
            throw new LossBoxException('BOX_TOKEN_INVALID', 'Jeton de boîtier invalide.', 401);
        }

        return $box;
    }

    private function tenantCode(): string
    {
        $code = $this->tenant->getTenantCode();
        if (!is_string($code) || $code === '') {
            throw new LossBoxException('BOX_TOKEN_INVALID', 'Jeton de boîtier invalide.', 401);
        }

        return $code;
    }

    /** Une heure ISO 8601 avec son décalage → secondes depuis l'époque ; null si elle n'en est pas une */
    private static function time(mixed $value): ?int
    {
        if (!is_string($value) || preg_match(self::TIME, $value) !== 1) {
            return null;
        }
        try {
            $stamp = (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }

        // une heure hors de ce que la base garde (avant 2000, après 2100) n'est pas une heure de boîtier
        return $stamp >= 946684800 && $stamp < 4102444800 ? $stamp : null;
    }

    private static function stamp(string $stored): int
    {
        return (new \DateTimeImmutable($stored, new \DateTimeZone('UTC')))->getTimestamp();
    }

    private static function iso(int $stamp): string
    {
        return gmdate('Y-m-d\TH:i:s\+00:00', $stamp);
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
