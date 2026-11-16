<?php

namespace App\LossPrevention\Services;

use App\LossPrevention\Entity\LossBox;
use App\LossPrevention\Entity\LossBoxEvent;
use App\LossPrevention\Entity\LossDevice;
use App\LossPrevention\Entity\LossPieceEvent;
use App\LossPrevention\Entity\LossPrintJobLine;
use App\LossPrevention\Entity\LossTag;
use App\LossPrevention\Repository\LossBoxRepository;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;

/**
 * Ce que la plateforme répond à un BOÎTIER (lot L3-a, 15/11/2026, `docs/prevention-pertes.md` § 16) : inscription, jeton, signe de
 * vie, réglages, lots de faits. Le boîtier appelle, la plateforme ne le joint jamais.
 *
 * - **Le boîtier n'est pas un utilisateur.** Son jeton lui donne exactement ceci, sur son site : dire qu'il est là, lire SES
 *   réglages (ses appareils, les zones, les faits acceptés), envoyer des faits. Rien ici ne lit une vente, un client, le catalogue
 *   ni un constat ; aucune réponse ne porte de nom (d'article, de personne, ni même du boîtier), de prix ni de donnée d'un autre site.
 * - **Chaque appel relit le boîtier** : actif, de la génération du jeton. Le signe de vie et un lot de faits le relisent encore
 *   SOUS VERROU avant d'écrire : une révocation validée entre-temps arrête l'écriture.
 * - **La clé et la machine ne sont gardées que par leur empreinte**, liée au site et au secret de l'application
 *   (`LossBoxToken::keyHash`, `machineHash`) : une copie de la base ne donne ni l'une ni l'autre, et ne vaut pas sur un autre site.
 * - **Faits rejouables** : un fait porte une clé d'idempotence ; un fait déjà reçu répond « déjà connu ». Un fait refusé ne fait
 *   jamais échouer le lot, et il n'est pas à renvoyer.
 * - **Le signe de vie n'est pas un fait** : il met à jour l'état du boîtier et de ses appareils ; seul un CHANGEMENT écrit un fait
 *   (appareil vu pour la première fois, en panne, revenu ; boîtier revenu après un silence). Un appareil refusé (genre qui change,
 *   série en double, trop d'appareils) est dit dans la réponse : il n'empêche jamais le boîtier de donner signe de vie.
 * - Aucun état de pièce ne change en L3-a : les faits de pièces sont gardés tels quels (les lots L3-b et L4 les liront).
 */
class LossBoxChannelService
{
    public const MAX_FACTS = 200;
    public const HEARTBEAT_SECONDS = 60;
    /** Écart d'horloge toléré (secondes) : au-delà, les faits portent un doute sur leur heure */
    public const CLOCK_TOLERANCE = 60;
    /** Un fait plus vieux que cela (la file du boîtier garde 7 jours) porte aussi un doute sur son heure */
    public const OLDEST_FACT = 691200;
    private const MACHINE = '/\A[A-Za-z0-9_.:-]{16,128}\z/';
    private const VERSION = '/\A[0-9A-Za-z.+_-]{1,40}\z/';
    private const SERIAL = '/\A[A-Za-z0-9_.:-]{1,64}\z/';
    private const MODEL = '/\A[\p{L}\p{N} _.()\/+-]{1,60}\z/u';
    private const FACT_KEY = '/\A[A-Za-z0-9_-]{8,48}\z/';
    private const TIME = '/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})\z/';
    public const ARCHS = ['amd64', 'arm64'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenant,
        private readonly LossBoxToken $tokens,
        private readonly LossHealthService $health,
        private readonly LossBoxLimits $limits,
        private readonly ?LossPrintService $prints = null,
    ) {
    }

    /**
     * Le boîtier de la requête, d'après son jeton : signature de ce site, non expiré, boîtier réel, actif, de la même génération.
     *
     * @return array<string, mixed> la ligne du boîtier, avec `token_expires_at`
     */
    public function authenticate(mixed $token): array
    {
        return $this->authenticated($this->claims($token));
    }

    /**
     * Ce que dit un jeton dont la signature est bonne pour ce site (expiré ou non). Aucune lecture de la base.
     *
     * @return array{boxId: int, generation: int, expiresAt: int}
     */
    public function claims(mixed $token): array
    {
        if (!is_string($token) || $token === '') {
            throw new LossBoxException('BOX_TOKEN_REQUIRED', 'En-tête ' . LossBoxToken::HEADER . ' requis.', 401);
        }

        return $this->tokens->read($this->tenantCode(), $token) ?? throw new LossBoxException('BOX_TOKEN_INVALID', 'Jeton de boîtier invalide.', 401);
    }

    /**
     * Le boîtier que désigne un jeton à la signature bonne : réel, actif, de la même génération, jeton non expiré.
     *
     * @param array{boxId: int, generation: int, expiresAt: int} $claims
     *
     * @return array<string, mixed> la ligne du boîtier, avec `token_expires_at`
     */
    public function authenticated(array $claims): array
    {
        $box = self::usable($this->repository()->box($claims['boxId']), $claims['generation']);
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
        $site = $this->tenantCode(); // avant toute lecture : sans site résolu, rien n'est cherché
        $errors = self::machineErrors($body);
        if (!array_key_exists('key', $body)) {
            $errors['key'] = 'REQUIRED';
        }
        if ($errors !== []) {
            throw new LossBoxException('VALIDATION_FAILED', 'Inscription invalide.', 422, ['fields' => $errors]);
        }
        // une saisie qui n'a pas la forme d'une clé reçoit la réponse d'une clé inconnue
        $key = LossBoxKey::canonical($body['key']) ?? throw new LossBoxException('BOX_KEY_INVALID', 'Clé de boîtier inconnue.', 404);
        $machine = $this->tokens->machineHash($site, $body['machine']);

        return $this->transaction(function () use ($site, $key, $machine, $body): array {
            $box = $this->repository()->boxByKeyHash($this->tokens->keyHash($site, $key)) ?? throw new LossBoxException('BOX_KEY_INVALID', 'Clé de boîtier inconnue.', 404);
            if ($box['status'] === LossBox::REVOKED) {
                throw new LossBoxException('BOX_REVOKED', 'Ce boîtier a été révoqué.', 403);
            }
            if ($box['machine_hash'] !== null && !hash_equals((string) $box['machine_hash'], $machine)) {
                throw new LossBoxException('BOX_KEY_ALREADY_ENROLLED', 'Cette clé est déjà liée à une autre machine : l\'administrateur doit la remettre à zéro.', 409);
            }

            return $this->enrolLocked($box, $site, $machine, $body['version'], $body['arch'], false);
        });
    }

    /**
     * Inscription de la machine d'un boîtier JUMELÉ par code (lot L3-a2, § 16.17), dans le site courant. Le boîtier du site est
     * retrouvé par l'empreinte qui le lie à CE site et à `$code` (le code, et l'aléa du jumelage qui l'a créé) : aucun identifiant
     * venu d'ailleurs ne désigne un boîtier.
     * La forme de `$machine`, `$version` et `$arch` est vérifiée par l'appelant (`LossPairingService`).
     *
     * @return array<string, mixed>|null { box, token, expiresAt, serverTime, settings } ; null : ce jumelage ne vaut plus rien
     *                                   (boîtier révoqué, clé remise à zéro depuis, autre machine inscrite)
     */
    public function enrolPaired(string $code, string $machine, string $version, string $arch): ?array
    {
        $site = $this->tenantCode();
        $machineHash = $this->tokens->machineHash($site, $machine);

        return $this->transaction(function () use ($site, $code, $machineHash, $version, $arch): ?array {
            $box = $this->repository()->boxByKeyHash($this->tokens->pairingKeyHash($site, $code));
            if ($box === null || (bool) $box['exercise'] || $box['status'] === LossBox::REVOKED
                || ($box['machine_hash'] !== null && !hash_equals((string) $box['machine_hash'], $machineHash))) {
                return null;
            }

            return $this->enrolLocked($box, $site, $machineHash, $version, $arch, true);
        });
    }

    /**
     * Le boîtier, tenu par l'appelant dans sa transaction, reçoit sa machine : il devient actif, sa génération avance, ses
     * présences se ferment, et il reçoit son jeton.
     *
     * @param array<string, mixed> $box la ligne du boîtier, verrouillée
     *
     * @return array<string, mixed> { box, token, expiresAt, serverTime, settings }
     */
    private function enrolLocked(array $box, string $site, string $machine, string $version, string $arch, bool $paired): array
    {
        $repository = $this->repository();
        $serverNow = time();
        $now = gmdate('Y-m-d H:i:s', $serverNow);
        $boxId = (int) $box['id'];
        $again = $box['machine_hash'] !== null;
        // une machine qui se réinscrit après un silence : le trou est un fait, comme au retour d'un signe de vie
        $silent = $again && $box['last_seen_at'] !== null ? $serverNow - self::stamp((string) $box['last_seen_at']) : 0;
        if ($silent > $this->health->silentAfter()) {
            $repository->event($boxId, null, LossBoxEvent::BOX_BACK, null, false, ['silentSeconds' => $silent, 'since' => self::iso(self::stamp((string) $box['last_seen_at']))], $now);
        }
        // une machine (ré)inscrite ne sait plus ce que la plateforme croyait posé sur ses tapis : tout se ferme, elle le redira
        $repository->closePresencesOf($repository->matIdsOf($boxId), self::boxTime($serverNow, $box['clock_offset']));
        $generation = $repository->enrol($boxId, $machine, $version, $arch, $now);
        $repository->event($boxId, null, LossBoxEvent::BOX_ENROLLED, null, false, ['version' => $version, 'arch' => $arch, 'again' => $again] + ($paired ? ['pairing' => true] : []), $now);
        $token = $this->tokens->issue($site, $boxId, $generation, $serverNow);

        return ['box' => ['id' => $boxId], 'token' => $token['token'], 'expiresAt' => self::iso($token['expiresAt']), 'serverTime' => self::iso($serverNow), 'settings' => $this->settingsOf($boxId)];
    }

    /**
     * La forme de ce qu'une machine dit d'elle : son empreinte, sa version, son architecture (aussi pour le jumelage par code).
     *
     * @param array<string, mixed> $body
     * @param list<string>         $fields parmi `machine`, `version`, `arch`
     *
     * @return array<string, string> erreurs par champ
     */
    public static function machineErrors(array $body, array $fields = ['machine', 'version', 'arch']): array
    {
        $errors = [];
        foreach (array_intersect_key(['machine' => self::MACHINE, 'version' => self::VERSION], array_flip($fields)) as $field => $pattern) {
            if (!is_string($body[$field] ?? null) || preg_match($pattern, $body[$field]) !== 1) {
                $errors[$field] = array_key_exists($field, $body) ? 'INVALID_VALUE' : 'REQUIRED';
            }
        }
        if (in_array('arch', $fields, true) && !in_array($body['arch'] ?? null, self::ARCHS, true)) {
            $errors['arch'] = array_key_exists('arch', $body) ? 'INVALID_VALUE' : 'REQUIRED';
        }

        return $errors;
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
        $site = $this->tenantCode();
        $claims = is_string($token) ? $this->tokens->read($site, $token) : null;
        if ($claims === null || $claims['expiresAt'] + LossBoxToken::GRACE <= time()) {
            throw new LossBoxException('BOX_TOKEN_INVALID', 'Jeton de boîtier invalide : il faut une nouvelle inscription.', 401);
        }
        $box = self::usable($this->repository()->box($claims['boxId']), $claims['generation']);
        $this->sameMachine($box, $body, $site);
        $fresh = $this->tokens->issue($site, (int) $box['id'], (int) $box['generation']);

        return ['token' => $fresh['token'], 'expiresAt' => self::iso($fresh['expiresAt']), 'serverTime' => self::iso(time())];
    }

    /**
     * Signe de vie `{ now, machine, version?, devices: [{ serial, kind, model?, status }] }` : dernier contact, écart d'horloge,
     * état des appareils. Un appareil connu que le boîtier n'annonce plus est noté en panne. Un appareil que la plateforme ne peut
     * pas prendre (genre qui change, série en double, trop d'appareils) est rendu dans `refusedDevices` : le signe de vie vaut
     * quand même. Rend un jeton neuf dès la mi-vie du sien.
     *
     * @param array<string, mixed> $box  le boîtier authentifié
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> { serverTime, clockOffsetSeconds, clockDoubt, token, refusedDevices, settings }
     */
    public function heartbeat(array $box, array $body): array
    {
        $site = $this->tenantCode();
        $errors = [];
        $boxNow = self::time($body['now'] ?? null);
        if ($boxNow === null) {
            $errors['now'] = array_key_exists('now', $body) ? 'INVALID_VALUE' : 'REQUIRED';
        }
        if (!is_string($body['machine'] ?? null) || preg_match(self::MACHINE, $body['machine']) !== 1) {
            $errors['machine'] = array_key_exists('machine', $body) ? 'INVALID_VALUE' : 'REQUIRED';
        }
        $version = $body['version'] ?? null;
        if ($version !== null && (!is_string($version) || preg_match(self::VERSION, $version) !== 1)) {
            $errors['version'] = 'INVALID_VALUE';
        }
        // l'heure du serveur lue dans la DERNIÈRE réponse reçue (inscription ou signe de vie) : absente au démarrage du boîtier
        $echo = $body['lastServerTime'] ?? null;
        $lastAnswer = $echo !== null ? self::time($echo) : null;
        if ($echo !== null && $lastAnswer === null) {
            $errors['lastServerTime'] = 'INVALID_VALUE';
        }
        // la liste est obligatoire : un signe de vie sans elle déclarerait tous les appareils débranchés
        $devices = $body['devices'] ?? null;
        $announced = [];
        $refused = [];
        if (!is_array($devices) || !array_is_list($devices)) {
            $errors['devices'] = array_key_exists('devices', $body) ? 'MUST_BE_LIST' : 'REQUIRED';
        } else {
            foreach ($devices as $i => $device) {
                if (!is_array($device)) {
                    $errors["devices[$i]"] = 'INVALID_VALUE';
                    continue;
                }
                $serial = $device['serial'] ?? null;
                $valid = true;
                if (!is_string($serial) || preg_match(self::SERIAL, $serial) !== 1) {
                    $errors["devices[$i].serial"] = array_key_exists('serial', $device) ? 'INVALID_VALUE' : 'REQUIRED';
                    $valid = false;
                }
                if (!in_array($device['kind'] ?? null, LossDevice::KINDS, true)) {
                    $errors["devices[$i].kind"] = array_key_exists('kind', $device) ? 'INVALID_VALUE' : 'REQUIRED';
                    $valid = false;
                }
                if (!in_array($device['status'] ?? null, [LossDevice::OK, LossDevice::DOWN], true)) {
                    $errors["devices[$i].status"] = array_key_exists('status', $device) ? 'INVALID_VALUE' : 'REQUIRED';
                    $valid = false;
                }
                $model = $device['model'] ?? null;
                if ($model !== null && (!is_string($model) || preg_match(self::MODEL, $model) !== 1)) {
                    $errors["devices[$i].model"] = 'INVALID_VALUE';
                    $valid = false;
                }
                if (!$valid) {
                    continue;
                }
                if (isset($announced[$serial])) {
                    $refused[] = ['serial' => $serial, 'code' => 'DUPLICATE']; // deux appareils au même numéro de série : le premier compte
                    continue;
                }
                if (count($announced) >= LossDevice::MAX_PER_BOX) {
                    $refused[] = ['serial' => $serial, 'code' => 'TOO_MANY']; // 16 appareils par signe de vie : les suivants ne sont pas pris
                    continue;
                }
                $announced[$serial] = ['kind' => $device['kind'], 'status' => $device['status'], 'model' => $model];
            }
        }
        if ($errors !== []) {
            throw new LossBoxException('VALIDATION_FAILED', 'Signe de vie invalide.', 422, ['fields' => $errors]);
        }
        $this->sameMachine($box, $body, $site);
        $boxId = (int) $box['id'];

        return $this->transaction(function () use ($site, $boxId, $boxNow, $version, $announced, $refused, $box, $lastAnswer): array {
            $repository = $this->repository();
            // la ligne du boîtier est verrouillée : deux signes de vie du même boîtier ne se croisent pas, et un boîtier révoqué
            // entre-temps n'écrit plus rien
            $current = self::usable($repository->box($boxId, true), (int) $box['generation']);
            $serverNow = time();
            $now = gmdate('Y-m-d H:i:s', $serverNow);
            $known = [];
            $mats = [];
            foreach ($repository->devicesOf([$boxId])[$boxId] as $row) {
                $known[(string) $row['serial']] = $row;
                if ($row['kind'] === LossDevice::COUNTER_MAT) {
                    $mats[] = (int) $row['id'];
                }
            }
            $silent = $current['last_seen_at'] !== null ? $serverNow - self::stamp((string) $current['last_seen_at']) : 0;
            if ($silent > $this->health->silentAfter()) { // le délai de silence du site (5 minutes par défaut)
                $repository->event($boxId, null, LossBoxEvent::BOX_BACK, null, false, ['silentSeconds' => $silent, 'since' => self::iso(self::stamp((string) $current['last_seen_at']))], $now);
                // pendant le silence on ne sait plus ce qui était sur les tapis : les présences se ferment au dernier signe de vie,
                // et le boîtier redit ce qui est posé (COUNTER_SEEN) maintenant qu'il est revenu
                $repository->closePresencesOf($mats, self::boxTime(self::stamp((string) $current['last_seen_at']), $current['clock_offset']));
            }
            // Le boîtier n'a pas lu notre dernière réponse (perdue en route), ou il vient de redémarrer : on ne sait plus ce qu'il sait.
            // Tout repart de zéro : ce qu'on croyait posé se ferme, et il redit tout. Un `resendMats` perdu n'est donc jamais perdu
            $missed = $current['last_seen_at'] !== null && $lastAnswer !== self::stamp((string) $current['last_seen_at']);
            if ($missed) {
                $repository->closePresencesOf($mats, self::boxTime($serverNow, $current['clock_offset']));
            }
            $offset = max(-2147483647, min(2147483647, $boxNow - $serverNow));
            $repository->seen($boxId, $now, $version, $offset);
            // Tapis dont le boîtier doit REDIRE ce qui est posé (`resendMats`) : chaque fois que le serveur a fermé ses présences sans que
            // le boîtier le sache (retour d'un silence, tapis revenu d'une panne ou d'un retrait, horloge redevenue juste, réponse perdue)
            $resendAll = $missed || $silent > $this->health->silentAfter();
            $doubtBefore = $current['clock_offset'] === null || abs((int) $current['clock_offset']) > self::CLOCK_TOLERANCE;
            $doubtNow = abs($offset) > self::CLOCK_TOLERANCE;
            if ($doubtNow && !$doubtBefore) {
                // l'horloge du boîtier vient de se dérégler : ses faits ne poseront plus rien, ce qu'on croyait posé ne l'est plus pour nous
                $repository->closePresencesOf($mats, self::boxTime($serverNow, $current['clock_offset']));
            }
            $resendAll = $resendAll || ($doubtBefore && !$doubtNow);
            $back = [];
            $count = count(array_filter($known, static fn (array $row): bool => $row['status'] !== LossDevice::RETIRED)); // un appareil retiré ne compte plus
            $down = [];
            foreach ($announced as $serial => $device) {
                $serial = (string) $serial;
                if (!isset($known[$serial])) {
                    if ($count >= LossDevice::MAX_KNOWN_PER_BOX) {
                        $refused[] = ['serial' => $serial, 'code' => 'TOO_MANY'];
                        continue;
                    }
                    ++$count;
                    $id = $repository->insertDevice($boxId, $serial, $device['kind'], $device['model'], $device['status'], null, false, $now);
                    $repository->event($boxId, $id, LossBoxEvent::DEVICE_SEEN, null, false, ['kind' => $device['kind']], $now);
                    if ($device['status'] === LossDevice::DOWN) {
                        $repository->event($boxId, $id, LossBoxEvent::SOURCE_DOWN, null, false, ['reason' => 'reported'], $now);
                    }
                    continue;
                }
                if ($known[$serial]['kind'] !== $device['kind']) {
                    // le genre d'un appareil ne change pas : celui qu'on connaissait sous ce numéro n'est plus là. Il est noté en panne,
                    // une fois, pour que l'administrateur le voie dans la fiche du boîtier
                    $refused[] = ['serial' => $serial, 'code' => 'MISMATCH'];
                    if (!in_array($known[$serial]['status'], [LossDevice::DOWN, LossDevice::RETIRED], true)) {
                        $repository->event($boxId, (int) $known[$serial]['id'], LossBoxEvent::SOURCE_DOWN, null, false, ['reason' => 'mismatch'], $now);
                        $repository->deviceStatus((int) $known[$serial]['id'], LossDevice::DOWN, null, false, $now);
                        $down[] = (int) $known[$serial]['id'];
                    }
                    continue;
                }
                $id = (int) $known[$serial]['id'];
                if ($known[$serial]['status'] === LossDevice::RETIRED) {
                    // un appareil retiré que le boîtier annonce de nouveau est là : il revient (s'il y a encore de la place)
                    if ($count >= LossDevice::MAX_KNOWN_PER_BOX) {
                        $refused[] = ['serial' => $serial, 'code' => 'TOO_MANY'];
                        continue;
                    }
                    ++$count;
                    $repository->event($boxId, $id, LossBoxEvent::DEVICE_SEEN, null, false, ['kind' => $device['kind'], 'again' => true], $now);
                    $back[] = $serial;
                } elseif ($known[$serial]['status'] !== $device['status']) {
                    $repository->event($boxId, $id, $device['status'] === LossDevice::DOWN ? LossBoxEvent::SOURCE_DOWN : LossBoxEvent::SOURCE_UP, null, false, ['reason' => 'reported'], $now);
                    if ($device['status'] === LossDevice::OK) {
                        $back[] = $serial;
                    }
                }
                if ($device['status'] === LossDevice::DOWN) {
                    $down[] = $id;
                }
                $repository->deviceStatus($id, $device['status'], $device['model'], true, $now);
            }
            // un appareil connu que le boîtier n'annonce plus : en panne (débranché), une seule fois ; un appareil retiré reste retiré
            foreach (array_diff_key($known, $announced) as $row) {
                if (!in_array($row['status'], [LossDevice::DOWN, LossDevice::RETIRED], true)) {
                    $repository->event($boxId, (int) $row['id'], LossBoxEvent::SOURCE_DOWN, null, false, ['reason' => 'absent'], $now);
                    $repository->deviceStatus((int) $row['id'], LossDevice::DOWN, null, false, $now);
                    $down[] = (int) $row['id'];
                }
            }
            // un tapis en panne ne dit plus ce qui est posé sur lui : ses présences se ferment
            $repository->closePresencesOf(array_values(array_intersect($down, $mats)), self::boxTime($serverNow, $doubtNow ? $current['clock_offset'] : $offset));
            $token = null;
            if ((int) $box['token_expires_at'] - $serverNow < LossBoxToken::RENEW_BEFORE) {
                $fresh = $this->tokens->issue($site, $boxId, (int) $current['generation'], $serverNow);
                $token = ['token' => $fresh['token'], 'expiresAt' => self::iso($fresh['expiresAt'])];
            }

            $resend = [];
            foreach ($announced as $serial => $device) {
                if ($device['kind'] === LossDevice::COUNTER_MAT && $device['status'] === LossDevice::OK && ($resendAll || in_array((string) $serial, $back, true))
                    && isset($known[(string) $serial]) && $known[(string) $serial]['kind'] === LossDevice::COUNTER_MAT) {
                    $resend[] = (string) $serial;
                }
            }

            return ['serverTime' => self::iso($serverNow), 'clockOffsetSeconds' => $offset, 'clockDoubt' => $doubtNow, 'token' => $token,
                'refusedDevices' => $refused, 'resendMats' => $doubtNow ? [] : $resend, 'settings' => $this->settingsOf($boxId)];
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
        return ['box' => ['id' => (int) $box['id']], 'serverTime' => self::iso(time())] + $this->settingsOf((int) $box['id']);
    }

    /**
     * Les travaux d'impression que ce boîtier doit encore faire (lot L3-c), avec le mot de passe d'accès des puces : la SEULE réponse
     * de la plateforme qui le porte. Une lecture.
     *
     * @param array<string, mixed> $box le boîtier authentifié
     *
     * @return array<string, mixed> { serverTime, jobs [{ id, printer, withPrice, access { rank, password }, lines [...] }], more }
     */
    public function jobs(array $box): array
    {
        return ['serverTime' => self::iso(time())] + ($this->prints?->jobsOf((int) $box['id']) ?? ['jobs' => [], 'more' => false]);
    }

    /**
     * Un lot de faits `{ facts: [{ key, type, at, device, zone?, seq?, data: { identifier, technology? } }] }`, de 1 à 200.
     * Résultats d'une imprimante à puce (lot L3-c) : `TAG_WRITTEN { data: { line, identifier } }`, `TAG_WRITE_FAILED { data: { line, reason } }`. Chaque
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
        // plafond de faits par boîtier et par heure : la table des faits ne se vide pas, un boîtier déréglé ne la remplit pas
        $wait = $this->limits->facts($this->tenantCode(), $boxId, count($facts));
        if ($wait !== null) {
            throw new LossBoxException('RATE_LIMITED', 'Trop de faits envoyés. Réessayez plus tard.', 429, ['retryAfterSeconds' => $wait]);
        }
        $repository = $this->repository();
        $serverNow = time();

        return $this->transaction(function () use ($facts, $boxId, $box, $repository, $serverNow): array {
            // le boîtier est relu et tenu : révoqué ou réinscrit depuis l'authentification, il n'écrit rien. Ses appareils et les puces
            // sont lus APRÈS ce verrou : un appareil retiré ou une puce associée entre-temps est vu tel qu'il est maintenant
            $current = self::usable($repository->box($boxId, false, true), (int) $box['generation']);
            $devices = [];
            foreach ($repository->devicesOf([$boxId], false)[$boxId] as $row) { // un appareil retiré n'envoie plus de faits
                $devices[(string) $row['serial']] = $row;
            }
            // Première passe : la forme de chaque fait
            $checked = [];
            $identifiers = [];
            foreach ($facts as $index => $fact) {
                $checked[$index] = $this->checkFact($fact, $devices);
                if (isset($checked[$index]['identifier']) && !isset($checked[$index]['line'])) {
                    $identifiers[$checked[$index]['identifier']] = true;
                }
            }
            $tags = $repository->tagsByIdentifier(array_map('strval', array_keys($identifiers)));
            // les résultats d'impression du lot : leurs pièces puis leurs travaux sont tenus d'avance, dans l'ordre commun au module
            $prints = array_values(array_filter($checked, static fn (array $fact): bool => !isset($fact['refusal']) && isset($fact['line'])));
            if ($prints !== []) {
                $this->prints?->hold($boxId, $prints);
            }
            // l'heure d'un fait est douteuse si l'horloge du boîtier n'a pas été mesurée, ou si elle s'écarte de celle du serveur
            $offsetDoubt = $current['clock_offset'] === null || abs((int) $current['clock_offset']) > self::CLOCK_TOLERANCE;
            $now = gmdate('Y-m-d H:i:s', $serverNow);
            $results = [];
            $counts = ['accepted' => 0, 'duplicate' => 0, 'refused' => 0];
            foreach ($checked as $index => $fact) {
                $refusal = $fact['refusal'] ?? null;
                if ($refusal === null && isset($fact['line'])) {
                    // résultat d'une imprimante à puce : il clôt une ligne de travail (et pose la puce relue conforme sur la pièce)
                    $late = $offsetDoubt || $fact['at'] > $serverNow + self::CLOCK_TOLERANCE || $fact['at'] < $serverNow - self::OLDEST_FACT;
                    [$status, $code] = $this->prints !== null ? $this->prints->result($boxId, $fact + ['doubt' => $late], $now) : ['refused', 'FACT_TYPE_UNKNOWN'];
                    ++$counts[$status];
                    $results[] = ['index' => $index, 'key' => $fact['key'], 'status' => $status, 'code' => $code];
                    continue;
                }
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
                // un fait daté du futur, ou plus vieux que la file du boîtier, porte lui aussi un doute sur son heure
                $doubt = $offsetDoubt || $fact['at'] > $serverNow + self::CLOCK_TOLERANCE || $fact['at'] < $serverNow - self::OLDEST_FACT;
                $data = array_filter(['zone' => $fact['zone'], 'seq' => $fact['seq']], static fn (mixed $v): bool => $v !== null)
                    + ($tag['status'] === LossTag::RETIRED ? ['tagRetired' => true] : []) // la puce a été retirée : lue quand même, et dite
                    + ($doubt ? ['clockDoubt' => true] : []);
                $at = gmdate('Y-m-d H:i:s', $fact['at']);
                $pieceId = $tag['piece_id'] !== null ? (int) $tag['piece_id'] : null;
                $written = $repository->pieceFact($fact['type'], $at, $pieceId, (int) $tag['id'], $boxId, $fact['deviceId'], 'b' . $boxId . ':' . $fact['key'], false, $data, $now);
                // Tapis de comptoir : la présence est la projection de ces faits. Un fait rejoué ne la touche pas une seconde fois. Une
                // ARRIVÉE à l'heure douteuse ne pose rien (la présence sert à dire ce qui est posé AVANT un paiement) ; un DÉPART vaut
                // toujours, à l'heure du serveur s'il est douteux : on ne garde pas « posée » une pièce qu'on nous dit partie
                if ($written && !$doubt && $fact['type'] === LossPieceEvent::COUNTER_SEEN) {
                    $repository->openPresence($fact['deviceId'], (int) $tag['id'], $at, false);
                } elseif ($written && $fact['type'] === LossPieceEvent::COUNTER_LEFT) {
                    $doubt ? $repository->closePresencesOfTag($fact['deviceId'], (int) $tag['id'], self::boxTime($serverNow, $current['clock_offset'])) : $repository->closePresence($fact['deviceId'], (int) $tag['id'], $at, false);
                }
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
    private function checkFact(mixed $fact, array $devices): array
    {
        $key = is_array($fact) && is_string($fact['key'] ?? null) && preg_match(self::FACT_KEY, $fact['key']) === 1 ? $fact['key'] : null;
        $refused = static fn (string $code): array => ['key' => $key, 'refusal' => $code];
        if (!is_array($fact) || $key === null) {
            return $refused('FACT_INVALID');
        }
        $type = $fact['type'] ?? null;
        if (is_string($type) && in_array($type, LossPieceEvent::PRINTS, true)) {
            return $this->checkPrintFact($fact, $key, $type, $devices);
        }
        if (!is_string($type) || !in_array($type, LossPieceEvent::READS, true)) {
            return $refused('FACT_TYPE_UNKNOWN');
        }
        $fromMat = in_array($type, LossPieceEvent::FROM_MAT, true);
        $at = self::time($fact['at'] ?? null);
        $seq = $fact['seq'] ?? null;
        if ($at === null || ($seq !== null && (!is_int($seq) || $seq < 0))) {
            return $refused('FACT_INVALID');
        }
        $device = is_string($fact['device'] ?? null) ? ($devices[$fact['device']] ?? null) : null;
        if ($device === null) {
            return $refused('DEVICE_UNKNOWN'); // le boîtier annonce ses appareils dans son signe de vie, avant leurs faits
        }
        // un tapis dit ce qui arrive sur lui et ce qui en part ; un lecteur dit ce qu'il lit : chacun ses faits
        if ($device['kind'] !== ($fromMat ? LossDevice::COUNTER_MAT : LossDevice::READER)) {
            return $refused('DEVICE_KIND_INVALID');
        }
        $zone = $fact['zone'] ?? null;
        if ($fromMat) {
            if ($zone !== null && $zone !== 'counter') {
                return $refused('ZONE_INVALID');
            }
            $zone = 'counter'; // un tapis est au comptoir
        } elseif (($zone === null && $type === LossPieceEvent::ZONE_SEEN) || ($zone !== null && !in_array($zone, LossDevice::ZONES, true))) {
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

    /**
     * La forme d'un résultat d'imprimante à puce : sa clé, son heure, son imprimante, la ligne du travail, et l'identifiant relu
     * (écriture réussie) ou le motif (échec).
     *
     * @param array<string, mixed>                $fact
     * @param array<string, array<string, mixed>> $devices
     *
     * @return array<string, mixed> { key, refusal } ou { key, type, at, deviceId, line, identifier | reason }
     */
    private function checkPrintFact(array $fact, string $key, string $type, array $devices): array
    {
        $refused = static fn (string $code): array => ['key' => $key, 'refusal' => $code];
        $at = self::time($fact['at'] ?? null);
        $data = $fact['data'] ?? null;
        $line = is_array($data) ? ($data['line'] ?? null) : null;
        if ($at === null || !is_int($line) || $line < 1 || $line > 2147483647) {
            return $refused('FACT_INVALID');
        }
        $device = is_string($fact['device'] ?? null) ? ($devices[$fact['device']] ?? null) : null;
        if ($device === null) {
            return $refused('DEVICE_UNKNOWN');
        }
        if ($device['kind'] !== LossDevice::PRINTER) {
            return $refused('DEVICE_KIND_INVALID'); // seule une imprimante à puce écrit une puce
        }
        $checked = ['key' => $key, 'type' => $type, 'at' => $at, 'deviceId' => (int) $device['id'], 'line' => $line];
        if ($type === LossPieceEvent::TAG_WRITE_FAILED) {
            $reason = $data['reason'] ?? null;

            return is_string($reason) && in_array($reason, LossPrintJobLine::BOX_REASONS, true) ? $checked + ['reason' => $reason] : $refused('REASON_INVALID');
        }
        $identifier = LossTagIdentifier::canonical($data['identifier'] ?? null, LossTagIdentifier::UHF);

        return $identifier !== null ? $checked + ['identifier' => $identifier] : $refused('TAG_INVALID');
    }

    /** @return array<string, mixed> les réglages servis au boîtier : cadence, faits acceptés, zones, ses appareils */
    private function settingsOf(int $boxId): array
    {
        $devices = [];
        foreach ($this->repository()->devicesOf([$boxId], false)[$boxId] as $row) {
            $devices[] = ['id' => (int) $row['id'], 'serial' => (string) $row['serial'], 'kind' => (string) $row['kind'], 'zone' => $row['zone'],
                'cashRegisterId' => $row['cash_register_id'] !== null ? (int) $row['cash_register_id'] : null];
        }

        return ['heartbeatSeconds' => self::HEARTBEAT_SECONDS, 'silentAfterSeconds' => $this->health->silentAfter(), 'facts' => ['types' => [...LossPieceEvent::READS, ...LossPieceEvent::PRINTS], 'maxBatch' => self::MAX_FACTS], 'zones' => LossDevice::ZONES, 'devices' => $devices];
    }

    /**
     * Un boîtier qui a le droit de parler : réel, actif, lié à une machine, de la génération attendue. Révoqué : 403, le boîtier
     * s'arrête ; tout autre cas : 401, il lui faut une nouvelle inscription.
     *
     * @param array<string, mixed>|null $box
     *
     * @return array<string, mixed>
     */
    private static function usable(?array $box, int $generation): array
    {
        if ($box === null || (bool) $box['exercise']) {
            throw new LossBoxException('BOX_TOKEN_INVALID', 'Jeton de boîtier invalide.', 401);
        }
        if ($box['status'] === LossBox::REVOKED) {
            throw new LossBoxException('BOX_REVOKED', 'Ce boîtier a été révoqué.', 403);
        }
        if ($box['status'] !== LossBox::ACTIVE || (int) $box['generation'] !== $generation || $box['machine_hash'] === null) {
            throw new LossBoxException('BOX_TOKEN_INVALID', 'Jeton de boîtier invalide.', 401);
        }

        return $box;
    }

    /**
     * La machine qui appelle est-elle celle qui s'est inscrite ? Un jeton copié ne suffit pas à tenir un boîtier en vie.
     *
     * @param array<string, mixed> $box
     * @param array<string, mixed> $body
     */
    private function sameMachine(array $box, array $body, string $site): void
    {
        $boxId = (int) $box['id'];
        // un jeton copié ne sert pas à deviner la machine : quelques empreintes fausses, et ce boîtier attend
        $wait = $this->limits->machineLeft($site, $boxId);
        if ($wait !== null) {
            throw new LossBoxException('RATE_LIMITED', 'Trop d\'essais. Réessayez plus tard.', 429, ['retryAfterSeconds' => $wait]);
        }
        if (!is_string($body['machine'] ?? null) || !hash_equals((string) $box['machine_hash'], $this->tokens->machineHash($site, $body['machine']))) {
            $this->limits->machineFailed($site, $boxId);
            throw new LossBoxException('BOX_TOKEN_INVALID', 'Jeton de boîtier invalide : il faut une nouvelle inscription.', 401);
        }
    }

    private function tenantCode(): string
    {
        $code = $this->tenant->getTenantCode();
        if (!is_string($code) || $code === '') {
            throw new LossBoxException('BOX_TOKEN_INVALID', 'Jeton de boîtier invalide.', 401);
        }

        return $code;
    }

    /** Une heure ISO 8601 avec son décalage → secondes depuis l'époque ; null si elle n'en est pas une (le 30 février n'existe pas) */
    private static function time(mixed $value): ?int
    {
        if (!is_string($value) || preg_match(self::TIME, $value, $m) !== 1) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59) {
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

    /**
     * L'heure d'une fermeture décidée par le SERVEUR, dite dans l'horloge du BOÎTIER. Les arrivées et les départs d'un tapis sont
     * datés par le boîtier ; une fermeture datée par l'horloge du serveur ferait juger « plus ancienne qu'un départ connu » une
     * arrivée que le boîtier redit juste après, dès qu'il retarde de quelques secondes. Écart inconnu ou douteux : la tolérance
     * entière est retirée (la fermeture est alors dite au plus tôt ; le dépôt ne ferme jamais avant l'arrivée).
     */
    public static function boxTime(int $serverStamp, mixed $clockOffset): string
    {
        $known = $clockOffset !== null && abs((int) $clockOffset) <= self::CLOCK_TOLERANCE;

        return gmdate('Y-m-d H:i:s', $serverStamp + ($known ? (int) $clockOffset : -self::CLOCK_TOLERANCE));
    }

    private static function stamp(string $stored): int
    {
        return (new \DateTimeImmutable($stored, new \DateTimeZone('UTC')))->getTimestamp();
    }

    private static function iso(int $stamp): string
    {
        return gmdate('Y-m-d\TH:i:s\+00:00', $stamp);
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
