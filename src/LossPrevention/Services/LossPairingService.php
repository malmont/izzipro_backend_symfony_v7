<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionConflictException;
use App\LossPrevention\Master\LossPairingRepository;
use App\Services\FeatureAccess\ModuleAccessService;
use App\Services\Platform\PlatformModeService;
use App\Services\TenantConnectionManager;
use App\Services\TenantConnectionProvider;
use App\Services\TenantPublicHostResolver;

/**
 * Jumelage d'un boîtier par code (lot L3-a2, 16/11/2026, `docs/prevention-pertes.md` § 16.16) : on ne tape plus de clé dans le
 * boîtier, c'est le boîtier qu'on déclare dans l'application.
 *
 * 1. **Le boîtier dépose sa demande** (`deposit`), sans site ni jeton : l'empreinte du code qu'il a créé, et celle de sa machine.
 *    Il la dépose AVANT de montrer son code (étiquette, terminal) : la première demande déposée pour un code gagne, pour toujours
 *    (une demande n'est jamais supprimée : aucune autre machine ne dépose jamais un code déjà déposé).
 * 2. **Un administrateur jumelle** (`pair`) : il donne le code, et le boîtier est créé dans SON site, celui de sa session, jamais
 *    un autre. Le code ne sert qu'une fois.
 * 3. **Le boîtier demande où il en est** (`state`), toujours avec la preuve de sa machine : jumelé, il reçoit l'adresse de son
 *    site et son jeton. Le code seul ne donne rien.
 *
 * Trois choses tenues ici :
 * - **personne ne jumelle un boîtier à un autre site que le sien** : le site d'un jumelage n'est jamais lu dans une requête, c'est
 *   celui de la session de l'administrateur ; et le boîtier du site porte une empreinte qui le lie à ce code ET à ce site ;
 * - **un code volé sur l'étiquette ne détourne pas un boîtier déjà jumelé** : un code jumelé, fermé ou expiré reçoit le même refus
 *   qu'un code inconnu, et aucune autre machine ne peut prendre un code déjà déposé (même expiré) ;
 * - **aucun code ne se devine à la chaîne** : essais plafonnés (`LossBoxLimits`), refus neutre.
 *
 * Les demandes vivent dans la base maître (`LossPairingRepository`) ; le boîtier, lui, reste une ligne de `loss_box` dans la base
 * de son site.
 */
class LossPairingService
{
    /** Une demande en attente reste jumelable ce temps-là après le dernier signe du boîtier (30 jours) */
    public const WAIT_SECONDS = 2592000;
    /** Une demande en attente est renouvelée au plus une fois par demi-journée (une écriture, pas une par question) */
    public const RENEW_EVERY = 43200;
    /**
     * Demandes NOUVELLES acceptées par heure pour toute la plateforme, comptées dans la base sous verrou. Avec 10 par adresse et par
     * heure (`LossBoxLimits`), il faut des dizaines d'adresses pour l'atteindre.
     */
    public const NEW_PER_HOUR = 300;
    /** Ce que la plateforme conseille au boîtier entre deux questions (secondes) */
    public const POLL_SECONDS = 15;
    public const INVALID = 'PAIRING_CODE_INVALID';

    public function __construct(
        private readonly LossPairingRepository $pairings,
        private readonly LossBoxToken $tokens,
        private readonly LossBoxLimits $limits,
        private readonly LossBoxChannelService $channel,
        private readonly LossBoxService $boxes,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantConnectionProvider $tenant,
        private readonly TenantPublicHostResolver $hosts,
        private readonly ModuleAccessService $modules,
        private readonly PlatformModeService $platform,
        private readonly LossTexts $texts,
    ) {
    }

    /**
     * Le boîtier dépose (ou redépose) sa demande `{ code, machine, version, arch }`. Sans site ni jeton.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> { status: waiting | paired | closed, expiresAt, pollSeconds, serverTime }
     */
    public function deposit(array $body, string $ip): array
    {
        $code = $this->boxInput($body, ['machine', 'version', 'arch']);
        $this->guardAddress($ip);
        $wait = $this->limits->pairDeposit($ip);
        if ($wait !== null) {
            throw self::tooMany($wait);
        }
        $codeHash = $this->tokens->pairingCodeHash($code);
        $machineHash = $this->tokens->pairingMachineHash($body['machine']);
        $serverNow = time();
        $now = gmdate('Y-m-d H:i:s', $serverNow);
        $expires = $serverNow + self::WAIT_SECONDS;

        return $this->pairings->transaction(function () use ($codeHash, $machineHash, $body, $serverNow, $now, $expires, $ip): array {
            $row = $this->pairings->byCodeHash($codeHash, true);
            if ($row === null) {
                // un code que la plateforme ne connaît pas : une ligne de plus dans la base maître, qui ne s'effacera pas. Leur nombre
                // par heure est plafonné par adresse, puis pour toute la plateforme (compté ici, dans la base, sous verrou)
                $wait = $this->limits->pairNew($ip);
                if ($wait !== null) {
                    throw self::tooMany($wait);
                }
                if ($this->pairings->createdSince(gmdate('Y-m-d H:i:s', $serverNow - 3600)) >= self::NEW_PER_HOUR) {
                    throw self::tooMany(900);
                }
                if ($this->pairings->insert($codeHash, $machineHash, $body['version'], $body['arch'], $now, gmdate('Y-m-d H:i:s', $expires))) {
                    return self::answer(LossPairingRepository::WAITING, $serverNow, $expires);
                }
                $row = $this->pairings->byCodeHash($codeHash, true); // déposé au même instant par un autre appel
            }
            // le code est déjà déposé : seul le boîtier qui l'a déposé peut le redéposer (une autre machine reçoit le refus d'un inconnu)
            if ($row === null || !hash_equals((string) $row['machine_hash'], $machineHash)) {
                $this->limits->pairFailed($ip);
                throw self::unknown();
            }
            if ($row['status'] === LossPairingRepository::WAITING) {
                $this->pairings->renew((int) $row['id'], $body['version'], $body['arch'], $now, gmdate('Y-m-d H:i:s', $expires));

                return self::answer(LossPairingRepository::WAITING, $serverNow, $expires);
            }

            return self::answer((string) $row['status'], $serverNow, null);
        });
    }

    /**
     * Le boîtier demande où en est son jumelage `{ code, machine }`. Sans site ni jeton : sa preuve est l'empreinte de sa machine.
     * Jumelé, il reçoit l'adresse de son site et son jeton (il peut les redemander si la réponse s'est perdue).
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> { status: waiting | closed, expiresAt, pollSeconds, serverTime } ou
     *                              { status: paired, host, box { id }, token, expiresAt, serverTime, settings }
     */
    public function state(array $body, string $ip): array
    {
        $code = $this->boxInput($body, ['machine']);
        $this->guardAddress($ip);
        $codeHash = $this->tokens->pairingCodeHash($code);
        $row = $this->pairings->byCodeHash($codeHash);
        if ($row === null || !hash_equals((string) $row['machine_hash'], $this->tokens->pairingMachineHash($body['machine']))) {
            $this->limits->pairFailed($ip);
            throw self::unknown();
        }
        $wait = $this->limits->pairPoll($codeHash);
        if ($wait !== null) {
            throw self::tooMany($wait);
        }
        $serverNow = time();
        $now = gmdate('Y-m-d H:i:s', $serverNow);
        $id = (int) $row['id'];
        if ($row['status'] === LossPairingRepository::WAITING) {
            // le boîtier est là : sa demande reste jumelable (écrit au plus une fois par demi-journée)
            $expires = (new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC')))->getTimestamp();
            if ($expires - $serverNow < self::WAIT_SECONDS - self::RENEW_EVERY) {
                $expires = $serverNow + self::WAIT_SECONDS;
                $this->pairings->renew($id, null, null, $now, gmdate('Y-m-d H:i:s', $expires));
            }

            return self::answer(LossPairingRepository::WAITING, $serverNow, $expires);
        }
        if ($row['status'] !== LossPairingRepository::PAIRED) {
            return self::answer(LossPairingRepository::CLOSED, $serverNow, null);
        }
        // Jumelé : la suite se passe dans le site que l'administrateur a choisi en jumelant (lu de la demande, jamais de la requête)
        $site = (string) $row['tenant_code'];
        $config = $this->tenants->findTenantByCode($site);
        if ($config === null) {
            // site introuvable : il a disparu, ou la base maître a eu une panne d'un instant (la lecture ne distingue pas les deux).
            // Rien n'est remis et rien n'est fermé : le boîtier patiente
            return self::answer(LossPairingRepository::WAITING, $serverNow, null);
        }
        $this->tenant->switchTenant((string) $config['dbname'], $site);
        if (!$this->platform->isDemo() && !$this->modules->isEnabled(ModuleAccessService::LOSS_PREVENTION)) {
            // le site n'a plus le module : rien n'est remis, le boîtier patiente (comme ses autres appels, refusés sans le module)
            return self::answer(LossPairingRepository::WAITING, $serverNow, null);
        }
        // chaque remise est une inscription (génération, jeton, fait) : un boîtier qui redemande sans cesse est arrêté
        $wait = $this->limits->pairDelivery($codeHash);
        if ($wait !== null) {
            throw self::tooMany($wait);
        }
        $enrolment = $this->channel->enrolPaired(self::linked($code, (string) $row['link']), $body['machine'], (string) $row['version'], (string) $row['arch']);
        if ($enrolment === null) {
            // boîtier révoqué (« détaché »), clé remise à zéro, autre machine inscrite par la clé : ce code ne vaut plus rien
            $this->pairings->close($id, $now);

            return self::answer(LossPairingRepository::CLOSED, $serverNow, null);
        }
        $this->pairings->delivered($id, $now);

        return ['status' => LossPairingRepository::PAIRED, 'host' => $this->hosts->describe($site)['host'] ?? null] + $enrolment;
    }

    /**
     * Un administrateur jumelle un boîtier à SON site `{ code, name, place? }` : le site est celui de sa session.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> { box } : la fiche du boîtier, qui attend sa machine
     *
     * @throws GestionConflictException 404 `PAIRING_CODE_INVALID` (code inconnu, expiré ou déjà servi : même refus), 409
     *                                  `BOX_LIMIT_REACHED`, 429 `RATE_LIMITED`
     */
    public function pair(array $body, string $locale, int $userId, string $ip): array
    {
        [$name, $place] = $this->boxes->checkedNames($body);
        $site = $this->tenant->getTenantCode();
        if (!is_string($site) || $site === '') {
            throw new \LogicException('Aucun site : un boîtier se jumelle dans un site.');
        }
        $wait = $this->limits->pairAdmin($site, $ip);
        if ($wait !== null) {
            throw new GestionConflictException('RATE_LIMITED', $this->texts->t('boxes.error.PAIRING_RATE_LIMITED', $locale), 429, ['retryAfterSeconds' => $wait]);
        }
        $invalid = fn (): GestionConflictException => new GestionConflictException(self::INVALID, $this->texts->t('boxes.error.' . self::INVALID, $locale), 404);
        $code = LossPairingCode::canonical($body['code'] ?? null) ?? throw $invalid();
        $serverNow = time();
        $now = gmdate('Y-m-d H:i:s', $serverNow);
        $boxId = $this->pairings->transaction(function () use ($code, $name, $place, $locale, $userId, $site, $serverNow, $now, $invalid): int {
            // la demande est tenue jusqu'au bout : deux administrateurs qui donnent le même code au même instant ne jumellent pas deux fois
            $row = $this->pairings->byCodeHash($this->tokens->pairingCodeHash($code), true);
            if ($row === null || $row['status'] !== LossPairingRepository::WAITING
                || (new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC')))->getTimestamp() <= $serverNow) {
                throw $invalid();
            }
            // Le boîtier du site est lié à ce code, à ce site ET à l'aléa de ce jumelage-ci, écrit sur la demande. Si l'écriture de la
            // demande échoue après la création du boîtier (deux bases, pas de transaction commune), il reste un boîtier « en attente »
            // que rien ne désigne plus : l'administrateur le révoque et rejumelle le même code, qui crée un autre boîtier
            $link = bin2hex(random_bytes(16));
            $boxId = $this->boxes->createPaired($name, $place, self::linked($code, $link), $locale, $userId);
            $this->pairings->pair((int) $row['id'], $site, $boxId, $link, $now);

            return $boxId;
        });

        return ['box' => $this->boxes->sheet($boxId, $locale, false)];
    }

    /**
     * Ce que le boîtier envoie : son code, et ce qu'il dit de sa machine.
     *
     * @param array<string, mixed> $body
     * @param list<string>         $fields
     *
     * @return string le code, en forme canonique
     */
    private function boxInput(array $body, array $fields): string
    {
        $errors = LossBoxChannelService::machineErrors($body, $fields);
        $code = LossPairingCode::canonical($body['code'] ?? null);
        if ($code === null) {
            $errors['code'] = array_key_exists('code', $body) ? 'INVALID_VALUE' : 'REQUIRED';
        }
        if ($errors !== []) {
            throw new LossBoxException('VALIDATION_FAILED', 'Demande de jumelage invalide.', 422, ['fields' => $errors]);
        }

        return (string) $code;
    }

    /** Ce qui désigne le boîtier créé par UN jumelage : le code, et l'aléa de ce jumelage */
    private static function linked(string $code, string $link): string
    {
        return $code . '|' . $link;
    }

    /** Une adresse qui a trop présenté de codes inconnus ou de mauvaises preuves est arrêtée avant toute lecture */
    private function guardAddress(string $ip): void
    {
        $wait = $this->limits->pairFailedLeft($ip);
        if ($wait !== null) {
            throw self::tooMany($wait);
        }
    }

    /** @return array<string, mixed> */
    private static function answer(string $status, int $serverNow, ?int $expires): array
    {
        return ['status' => $status, 'expiresAt' => $expires !== null ? gmdate('Y-m-d\TH:i:s\+00:00', $expires) : null, 'pollSeconds' => self::POLL_SECONDS,
            'serverTime' => gmdate('Y-m-d\TH:i:s\+00:00', $serverNow)];
    }

    /** Le même refus pour un code inconnu et pour une mauvaise preuve de machine */
    private static function unknown(): LossBoxException
    {
        return new LossBoxException(self::INVALID, 'Code de jumelage inconnu.', 404);
    }

    private static function tooMany(int $wait): LossBoxException
    {
        return new LossBoxException('RATE_LIMITED', 'Trop d\'appels. Réessayez plus tard.', 429, ['retryAfterSeconds' => $wait]);
    }
}
