<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\Entity\LossJournalEntry;
use App\LossPrevention\Master\LossPairingRepository;
use App\LossPrevention\Message\NotifyLossBoxPluggedMessage;
use App\LossPrevention\Repository\LossJournalRepository;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformModeService;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Les boîtiers vendus, expédiés, reconnus au premier branchement (lot L6-b, 17/11/2026, `docs/prevention-pertes.md` § 19.5). Lot À
 * RISQUE : un boîtier ne doit jamais atterrir sur le mauvais site.
 *
 * 1. **La plateforme réserve** (`preview`, puis `reserve` avec `confirm: true`) : le numéro de série et le code de l'étiquette d'un
 *    boîtier, pour UN site, qui doit avoir le module. Rien de réversible n'est gardé : l'empreinte du code, la même que le jumelage.
 * 2. **Le boîtier est branché** (`plugged`, appelé dans la transaction du dépôt de sa demande de jumelage, qui porte le code en clair) :
 *    le serveur prépare tout de suite les empreintes qui relieront le futur boîtier du site, liées à CE site et à l'aléa du jumelage
 *    (exactement ce que fait la saisie du code, `LossPairingService::pair`), et prévient les administrateurs du site (courriel et
 *    notification « Votre boîtier est branché »).
 * 3. **Le site acheteur confirme** (`confirm`, « C'est bien mon boîtier ») dans les 72 h : le boîtier du site est créé avec ces
 *    empreintes, la demande est jumelée à CE site, celui de la session. Passé 72 h, l'expédition revient à `reserved` (empreintes
 *    effacées) ; un nouveau branchement relance l'avis.
 * 4. **Un code réservé ne se jumelle jamais à un autre site** (`guardPair`, appelé par la saisie du code) : le même refus qu'un code
 *    inconnu. Par le site acheteur, la saisie reste le secours, et confirme l'expédition.
 * 5. **La plateforme annule** (`cancel`) : le code est libéré ; un boîtier déjà jumelé n'est pas touché.
 * Chaque étape va au journal des expéditions (base maître), avec l'auteur ; la confirmation et le refus vont aussi au journal du
 * module du site.
 */
class LossShipmentService
{
    /** Une expédition branchée reste confirmable 72 h */
    public const CONFIRM_SECONDS = 259200;
    public const NOT_FOUND = 'SHIPMENT_NOT_FOUND';
    /** La clé du droit du module, telle que le registre des droits la nomme */
    public const MODULE_KEY = 'module.loss_prevention';
    public const SERIAL = '/\A[A-Za-z0-9][A-Za-z0-9._-]{2,63}\z/';

    public function __construct(
        private readonly LossPairingRepository $pairings,
        private readonly LossBoxToken $tokens,
        private readonly LossBoxService $boxes,
        private readonly LossBoxLimits $limits,
        private readonly TenantConnectionProvider $tenant,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantPublicHostResolver $hosts,
        private readonly PlatformModeService $platform,
        private readonly LossTexts $texts,
        private readonly ?EntitlementService $entitlements = null,
        private readonly ?MessageBusInterface $bus = null,
        private readonly ?LossBoxCodeMailer $codeMailer = null, // lot L6-c : le code envoyé au site acheteur, jamais gardé
    ) {
    }

    // ----- la plateforme

    /**
     * L'aperçu d'une expédition `{ serial, code, tenantCode, orderRef? }`, sans rien écrire : le site visé (nom, domaine, module), pour
     * que le super administrateur voie qu'il s'agit du bon avant de confirmer.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> { serial, orderRef, site { code, name, host, lossPrevention }, pairing: waiting }
     *
     * @throws GestionValidationException 422 { fields }
     */
    public function preview(array $body): array
    {
        [$serial, $codeHash, $site, $orderRef] = $this->checked($body);
        // relecture de L6-b (B3) : `checked` exige déjà la demande déposée à la préparation ; l'aperçu le redit
        return ['serial' => $serial, 'orderRef' => $orderRef, 'site' => $site, 'pairing' => 'waiting'];
    }

    /**
     * Réserver le boîtier pour le site `{ serial, code, tenantCode, orderRef?, confirm: true }`. Le boîtier doit avoir été PRÉPARÉ :
     * branché une fois à l'entrepôt, il a déposé sa demande avec ce code (sa machine est fixée, personne d'autre ne prendra le code en
     * chemin). La réservation ne branche RIEN (relecture de L6-b, B1) : le branchement est le premier contact de la machine APRÈS elle,
     * dans la boutique (dépôt ou question du boîtier).
     *
     * @param array<string, mixed> $body
     *
     * Lot L6-c (A) : le code part aussitôt aux administrateurs du site acheteur (`codeSentTo`), en direct, sans être gardé ; 0 : la
     * réservation tient, la plateforme le renverra (B, `resendCode`).
     *
     * @return array<string, mixed> { shipment, codeSentTo }
     *
     * @throws GestionValidationException 422 { fields } (dont `confirm: REQUIRED`), GestionConflictException 409 `SHIPMENT_EXISTS`
     */
    public function reserve(array $body, string $by): array
    {
        if (($body['confirm'] ?? null) !== true) {
            throw new GestionValidationException(['confirm' => 'REQUIRED']);
        }
        [$serial, $codeHash, $site, $orderRef] = $this->checked($body);
        $now = gmdate('Y-m-d H:i:s');
        $id = $this->pairings->transaction(function () use ($serial, $codeHash, $site, $orderRef, $by, $now): int {
            // la demande, revérifiée sous verrou (l'expédition n'existe pas encore : l'insertion ne prend aucun verrou qu'un autre tiendrait)
            $pairing = $this->pairings->byCodeHash($codeHash, true);
            if ($pairing !== null && $pairing['status'] !== LossPairingRepository::WAITING) {
                throw new GestionValidationException(['code' => 'ALREADY_PAIRED']);
            }
            if ($pairing === null || !$this->open($pairing)) {
                throw new GestionValidationException(['code' => 'NOT_PREPARED']);
            }
            $id = $this->pairings->insertShipment($serial, $codeHash, $site['code'], $orderRef, $by, $now);
            if ($id === null) {
                throw new GestionConflictException('SHIPMENT_EXISTS', 'Ce numéro de série ou ce code est déjà expédié.', 409);
            }
            $this->pairings->shipmentEvent($id, 'reserved', $by, $site['code'], ['serial' => $serial, 'orderRef' => $orderRef], $now);

            return $id;
        });
        $sent = $this->sendCode($id, (string) LossPairingCode::canonical($body['code']), $by);

        return ['shipment' => $this->view($this->pairings->shipment($id) ?? []), 'codeSentTo' => $sent];
    }

    /**
     * Lot L6-c (B) : la plateforme renvoie le code d'une expédition réservée ou branchée `{ code }`, relu sur le boîtier ou sa fiche de
     * préparation : il doit être celui de l'expédition (empreintes comparées en temps constant). Plafonné.
     *
     * @param array<string, mixed> $body
     *
     * @return array{codeSentTo: int}
     *
     * @throws GestionValidationException 422 `code` (INVALID_VALUE, CODE_MISMATCH), GestionConflictException 404, 409, 429
     */
    public function resendCode(int $id, array $body, string $by): array
    {
        $wait = $this->limits->shipmentCode($id);
        if ($wait !== null) {
            throw new GestionConflictException('RATE_LIMITED', 'Trop d\'essais. Réessayez plus tard.', 429, ['retryAfterSeconds' => $wait]);
        }
        $shipment = $this->pairings->shipment($id) ?? throw new GestionConflictException(self::NOT_FOUND, 'Expédition introuvable.', 404);
        if (!in_array($shipment['status'], [LossPairingRepository::SHIPMENT_RESERVED, LossPairingRepository::SHIPMENT_PLUGGED], true)) {
            throw new GestionConflictException('SHIPMENT_NOT_OPEN', 'Cette expédition est déjà confirmée ou annulée.', 409);
        }
        $code = LossPairingCode::canonical($body['code'] ?? null);
        if ($code === null) {
            throw new GestionValidationException(['code' => array_key_exists('code', $body) ? 'INVALID_VALUE' : 'REQUIRED']);
        }
        if (!hash_equals((string) $shipment['code_hash'], $this->tokens->pairingCodeHash($code))) {
            throw new GestionValidationException(['code' => 'CODE_MISMATCH']);
        }

        return ['codeSentTo' => $this->sendCode($id, $code, $by)];
    }

    /** Le code au site acheteur, sans rien laisser derrière : une panne ne refuse jamais la réservation */
    private function sendCode(int $id, string $code, string $by): int
    {
        try {
            return $this->codeMailer?->send($id, $code, $by) ?? 0;
        } catch (\Throwable $e) {
            unset($e); // jamais le message (il pourrait porter le code)
            return 0;
        }
    }

    /** L'empreinte d'un code (la même que la base garde) : la page de la plateforme compare le code relu à celui de l'aperçu ; null si invalide */
    public function codeFingerprint(mixed $code): ?string
    {
        $canonical = LossPairingCode::canonical($code);

        return $canonical === null ? null : $this->tokens->pairingCodeHash($canonical);
    }

    /** @return array<string, mixed> { shipments } les plus récentes d'abord */
    public function all(): array
    {
        $this->expire(null);

        return ['shipments' => array_map(fn (array $s): array => $this->view($s), $this->pairings->shipments())];
    }

    /**
     * Annuler une expédition réservée ou branchée : le code est libéré. Un boîtier déjà jumelé n'est pas touché.
     *
     * @return array<string, mixed> { shipment }
     *
     * @throws GestionConflictException 404 SHIPMENT_NOT_FOUND, 409 SHIPMENT_NOT_CANCELLABLE
     */
    public function cancel(int $id, string $by): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $wasPlugged = null;
        $this->pairings->transaction(function () use ($id, $by, $now, &$wasPlugged): void {
            $shipment = $this->pairings->shipment($id, true) ?? throw new GestionConflictException(self::NOT_FOUND, 'Expédition introuvable.', 404);
            if (!$this->pairings->cancelShipment($id, $by, $now)) {
                throw new GestionConflictException('SHIPMENT_NOT_CANCELLABLE', 'Cette expédition est déjà confirmée ou annulée.', 409);
            }
            $this->pairings->shipmentEvent($id, 'cancelled', $by, (string) $shipment['tenant_code'], ['previous' => (string) $shipment['status']], $now);
            $wasPlugged = $shipment['status'] === LossPairingRepository::SHIPMENT_PLUGGED ? (string) $shipment['tenant_code'] : null;
        });
        // décision du chef de projet : le site d'un boîtier DÉJÀ branché apprend l'annulation (journal du site et avis), par la file des
        // courriels : la requête du super administrateur ne change jamais de site
        if ($wasPlugged !== null && $this->bus !== null) {
            try {
                $this->bus->dispatch(new NotifyLossBoxPluggedMessage($wasPlugged, $id, NotifyLossBoxPluggedMessage::CANCELLED), [new TransportNamesStamp(['email'])]);
            } catch (\Throwable) {
                // l'avis est perdu, pas l'annulation (au journal de la plateforme)
            }
        }

        return ['shipment' => $this->view($this->pairings->shipment($id) ?? [])];
    }

    // ----- le boîtier (appelé par LossPairingService, dans SA transaction de la base maître)

    /**
     * La demande de jumelage `$codeHash` est (re)déposée par sa machine, avec le code `$code` : si une expédition vivante porte ce code,
     * elle est branchée (ou rebranchée après 72 h). Rend ce qu'il faut pour prévenir le site, APRÈS la transaction (`dispatch`).
     *
     * @return array{tenant: string, shipment: int}|null
     */
    public function plugged(string $code, string $codeHash, string $now): ?array
    {
        $shipment = $this->pairings->shipmentByCodeHash($codeHash, true);
        if ($shipment === null) {
            return null;
        }
        if ($shipment['status'] === LossPairingRepository::SHIPMENT_PLUGGED && $this->expired($shipment, $now)) {
            $this->pairings->unplugShipment((int) $shipment['id']);
            $this->pairings->shipmentEvent((int) $shipment['id'], 'unplugged', null, (string) $shipment['tenant_code'], ['reason' => 'not_confirmed'], $now);
            $shipment = $this->pairings->shipment((int) $shipment['id'], true) ?? $shipment;
        }

        return $shipment['status'] === LossPairingRepository::SHIPMENT_RESERVED ? $this->plugLocked($shipment, $code, $now) : null;
    }

    /** Relecture (M1) : tient l'expédition vivante d'un code AVANT la demande, dans la transaction du dépôt (le même ordre partout) */
    public function lockFor(string $codeHash): void
    {
        $this->pairings->shipmentByCodeHash($codeHash, true);
    }

    /**
     * La question du boîtier (`LossPairingService::state`, hors transaction) : un code réservé, encore à brancher, est branché dans une
     * courte transaction (relecture, B1 : le premier contact APRÈS la réservation, dépôt ou question). Rend ce qu'il faut pour prévenir.
     *
     * @return array{tenant: string, shipment: int}|null
     */
    public function pluggedOnPoll(string $code, string $codeHash): ?array
    {
        $shipment = $this->pairings->shipmentByCodeHash($codeHash);
        if ($shipment === null || ($shipment['status'] !== LossPairingRepository::SHIPMENT_RESERVED
            && !($shipment['status'] === LossPairingRepository::SHIPMENT_PLUGGED && $this->expired($shipment, gmdate('Y-m-d H:i:s'))))) {
            return null; // le cas de chaque question : une lecture, aucune écriture
        }

        return $this->pairings->transaction(fn (): ?array => $this->plugged($code, $codeHash, gmdate('Y-m-d H:i:s')));
    }

    /**
     * La saisie du code par un administrateur (`LossPairingService::pair`, dans SA transaction) : un code réservé à un AUTRE site
     * reçoit le refus d'un code inconnu (vrai rendu) ; réservé à ce site, il passe (le secours) et rend l'expédition à confirmer.
     *
     * @return array{refused: bool, shipment: ?int}
     */
    public function guardPair(string $codeHash, string $site): array
    {
        $shipment = $this->pairings->shipmentByCodeHash($codeHash, true);
        if ($shipment === null) {
            return ['refused' => false, 'shipment' => null];
        }

        return ['refused' => !hash_equals((string) $shipment['tenant_code'], $site), 'shipment' => (int) $shipment['id']];
    }

    /**
     * Une saisie de code refusée, APRÈS la réponse (`LossPairingService::flushRefusals`) : au journal du module du site qui a saisi, la même
     * ligne qu'un code inconnu ; au journal des expéditions de la plateforme si le code était réservé à un autre site.
     */
    public function traceRefusal(string $site, int $userId, ?int $shipment): void
    {
        $this->journal()->append(LossJournalEntry::BOX_PAIRING_REFUSED, $userId, null, ['reason' => 'unknown']);
        if ($shipment !== null) {
            $this->pairings->shipmentEvent($shipment, 'refused', 'user:' . $userId, $site, ['reason' => 'code_other_site'], gmdate('Y-m-d H:i:s'));
        }
    }

    /** La saisie du code a jumelé, par le site acheteur, le boîtier d'une expédition : elle est confirmée (dans la même transaction) */
    public function pairedByCode(int $shipmentId, int $boxId, int $userId, string $site, string $now): void
    {
        if ($this->pairings->confirmShipment($shipmentId, $boxId, $userId, $now)) {
            $this->pairings->shipmentEvent($shipmentId, 'confirmed', 'user:' . $userId, $site, ['by' => 'code', 'boxId' => $boxId], $now);
        }
    }

    /** La confirmation par la saisie du code, au journal du module : APRÈS la transaction de la base maître (relecture 2) */
    public function journalConfirmedByCode(int $shipmentId, int $boxId, int $userId): void
    {
        $this->journal()->append(LossJournalEntry::BOX_SHIPMENT_CONFIRMED, $userId, null, ['shipment' => $shipmentId, 'boxId' => $boxId, 'by' => 'code']);
    }

    // ----- le site acheteur

    /**
     * Les boîtiers branchés de CE site, à confirmer.
     *
     * @return array<string, mixed> { boxes [{ id, serial, pluggedAt, pluggedAgo, confirmBefore }], notice }
     *
     * @throws GestionConflictException 429 RATE_LIMITED
     */
    public function pending(string $locale, string $ip): array
    {
        $site = $this->site();
        $wait = $this->limits->shipmentRead($site, $ip);
        if ($wait !== null) {
            throw new GestionConflictException('RATE_LIMITED', $this->texts->t('boxes.error.PAIRING_RATE_LIMITED', $locale), 429, ['retryAfterSeconds' => $wait]);
        }
        $this->expire($site);
        $now = time();
        $boxes = [];
        foreach ($this->pairings->shipments($site, LossPairingRepository::SHIPMENT_PLUGGED) as $s) {
            $plugged = self::ts((string) $s['plugged_at']);
            $boxes[] = ['id' => (int) $s['id'], 'serial' => (string) $s['serial'], 'pluggedAt' => gmdate('Y-m-d\TH:i:s\+00:00', $plugged),
                'pluggedAgo' => $this->duration(max(0, $now - $plugged), $locale), 'confirmBefore' => gmdate('Y-m-d\TH:i:s\+00:00', $plugged + self::CONFIRM_SECONDS)];
        }

        return ['boxes' => $boxes, 'notice' => $this->texts->t('shipments.notice', $locale)];
    }

    /**
     * « C'est bien mon boîtier » `{ name?, place? }` : le boîtier branché de l'expédition `$id` est créé dans CE site et jumelé. Une
     * expédition d'un autre site, inconnue, déjà confirmée ou passée les 72 h : le même 404.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> { box }
     *
     * @throws GestionConflictException 404 SHIPMENT_NOT_FOUND, 409 BOX_LIMIT_REACHED, 429 RATE_LIMITED
     */
    public function confirm(int $id, array $body, string $locale, int $userId, string $ip, bool $platformAccount = false): array
    {
        $site = $this->site();
        if ($platformAccount) {
            // relecture (M9) : « C'est bien mon boîtier » est le geste du commerçant, jamais celui du compte de la plateforme
            throw new GestionConflictException(self::NOT_FOUND, $this->texts->t('shipments.error.' . self::NOT_FOUND, $locale), 404);
        }
        $wait = $this->limits->pairAdmin($site, $ip);
        if ($wait !== null) {
            throw new GestionConflictException('RATE_LIMITED', $this->texts->t('boxes.error.PAIRING_RATE_LIMITED', $locale), 429, ['retryAfterSeconds' => $wait]);
        }
        $name = trim((string) ($body['name'] ?? '')) !== '' ? $body['name'] : $this->texts->t('shipments.defaultName', $locale);
        [$name, $place] = $this->boxes->checkedNames(['name' => $name] + $body);
        $now = gmdate('Y-m-d H:i:s');
        $refused = null;
        $boxId = $this->pairings->transaction(function () use ($id, $site, $name, $place, $locale, $userId, $now, &$refused): ?int {
            $shipment = $this->pairings->shipment($id, true);
            if ($shipment === null) {
                $refused = ['unknown', null];

                return null;
            }
            if (!hash_equals((string) $shipment['tenant_code'], $site)) {
                $refused = ['other_site', $id]; // au journal de la plateforme seulement (avec le site qui a tenté) ; le refus rendu est le même

                return null;
            }
            if ($shipment['status'] !== LossPairingRepository::SHIPMENT_PLUGGED) {
                $refused = [(string) $shipment['status'], $id];

                return null;
            }
            if ($this->expired($shipment, $now)) {
                $this->pairings->unplugShipment($id);
                $this->pairings->shipmentEvent($id, 'unplugged', null, $site, ['reason' => 'not_confirmed'], $now);
                $refused = ['expired', $id];

                return null;
            }
            $pairing = $this->pairings->byCodeHash((string) $shipment['code_hash'], true);
            if ($pairing === null || !$this->open($pairing)) {
                $refused = ['pairing', $id];

                return null;
            }
            $boxId = $this->boxes->createPairedPrepared($name, $place, (string) $shipment['key_hash'], (string) $shipment['ref'], $locale, $userId);
            $this->pairings->pair((int) $pairing['id'], $site, $boxId, (string) $shipment['link'], $now);
            $this->pairings->confirmShipment($id, $boxId, $userId, $now);
            $this->pairings->shipmentEvent($id, 'confirmed', 'user:' . $userId, $site, ['by' => 'confirm', 'boxId' => $boxId], $now);

            return $boxId;
        });
        if ($boxId === null) {
            // le refus est écrit APRÈS la transaction : il reste au journal. Au journal de la plateforme : l'expédition, le site qui a tenté,
            // la raison. Au journal du module de CE site : jamais l'identifiant d'une expédition d'un autre site
            [$reason, $known] = $refused ?? ['unknown', null];
            if ($known !== null) {
                $this->pairings->shipmentEvent($known, 'refused', 'user:' . $userId, $site, ['reason' => $reason], $now);
            }
            $this->journal()->append(LossJournalEntry::BOX_SHIPMENT_REFUSED, $userId, null, ['shipment' => $reason === 'other_site' ? null : $known, 'reason' => $reason === 'other_site' ? 'unknown' : $reason]);

            throw new GestionConflictException(self::NOT_FOUND, $this->texts->t('shipments.error.' . self::NOT_FOUND, $locale), 404);
        }
        $this->journal()->append(LossJournalEntry::BOX_SHIPMENT_CONFIRMED, $userId, null, ['shipment' => $id, 'boxId' => $boxId]);

        return ['box' => $this->boxes->sheet($boxId, $locale, false)];
    }

    // ----- interne

    /**
     * Branche une expédition RÉSERVÉE tenue sous verrou : les empreintes du futur boîtier sont calculées pour SON site, avec un aléa neuf.
     *
     * @param array<string, mixed> $shipment
     *
     * @return array{tenant: string, shipment: int}|null
     */
    private function plugLocked(array $shipment, string $code, string $now): ?array
    {
        if (($shipment['status'] ?? null) !== LossPairingRepository::SHIPMENT_RESERVED) {
            return null;
        }
        $site = (string) $shipment['tenant_code'];
        $link = bin2hex(random_bytes(16));
        $linked = $code . '|' . $link; // la même forme que LossPairingService::linked
        if (!$this->pairings->plugShipment((int) $shipment['id'], $this->tokens->pairingKeyHash($site, $linked), $this->tokens->pairingRef($site, $linked), $link, $now)) {
            return null;
        }
        $this->pairings->shipmentEvent((int) $shipment['id'], 'plugged', null, $site, [], $now);

        return ['tenant' => $site, 'shipment' => (int) $shipment['id']];
    }

    /** @param array{tenant: string, shipment: int}|null $notify */
    public function dispatch(?array $notify): void
    {
        if ($notify === null || $this->bus === null) {
            return;
        }
        try {
            $this->bus->dispatch(new NotifyLossBoxPluggedMessage($notify['tenant'], $notify['shipment']), [new TransportNamesStamp(['email'])]);
        } catch (\Throwable) {
            // l'avis est perdu, pas le branchement : l'écran « à confirmer » montre le boîtier branché
        }
    }

    /**
     * Le numéro de série, le code, le site (avec le module), la commande.
     *
     * @param array<string, mixed> $body
     *
     * @return array{0: string, 1: string, 2: array<string, mixed>, 3: string}
     */
    private function checked(array $body): array
    {
        $errors = [];
        $serial = is_string($body['serial'] ?? null) ? strtoupper(trim($body['serial'])) : null; // une série, quelle que soit la casse (relecture, M9)
        if ($serial === null || preg_match(self::SERIAL, $serial) !== 1) {
            $errors['serial'] = array_key_exists('serial', $body) ? 'INVALID_VALUE' : 'REQUIRED';
        }
        $code = LossPairingCode::canonical($body['code'] ?? null);
        if ($code === null) {
            $errors['code'] = array_key_exists('code', $body) ? 'INVALID_VALUE' : 'REQUIRED';
        }
        $orderRef = $body['orderRef'] ?? '';
        if (!is_string($orderRef) || mb_strlen(trim($orderRef)) > 80) {
            $errors['orderRef'] = 'INVALID_VALUE';
        }
        $tenantCode = is_string($body['tenantCode'] ?? null) ? trim($body['tenantCode']) : '';
        $info = $tenantCode !== '' ? $this->pairings->tenantInfo($tenantCode) : null;
        if ($info === null) {
            $errors['tenantCode'] = array_key_exists('tenantCode', $body) ? 'UNKNOWN_SITE' : 'REQUIRED';
        }
        $module = $info !== null && ($this->platform->isDemo() || $this->entitlements?->isActive($info['code'], self::MODULE_KEY) === true);
        if ($info !== null && !$module) {
            $errors['tenantCode'] = 'MODULE_INACTIVE';
        }
        $codeHash = $code !== null ? $this->tokens->pairingCodeHash($code) : '';
        if ($code !== null) {
            $pairing = $this->pairings->byCodeHash($codeHash);
            if ($pairing !== null && $pairing['status'] !== LossPairingRepository::WAITING) {
                $errors['code'] = 'ALREADY_PAIRED';
            } elseif ($pairing === null || !$this->open($pairing)) {
                // relecture (B3) : un boîtier non préparé laisserait son code au premier qui le lit en chemin
                $errors['code'] = 'NOT_PREPARED';
            } elseif ($serial !== null && !isset($errors['serial'])) {
                // décision du chef de projet (B2) : la série saisie est celle que la machine de CE code a dite en déposant sa demande ; deux
                // boîtiers échangés à la saisie ne se réservent pas
                $said = $pairing['serial'] ?? null;
                if ($said === null || $said === '') {
                    $errors['serial'] = 'SERIAL_UNKNOWN';
                } elseif (!hash_equals(strtoupper((string) $said), $serial)) {
                    $errors['serial'] = 'SERIAL_MISMATCH';
                }
            }
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $host = null;
        try {
            $host = $this->hosts->describe((string) $tenantCode)['host'] ?? null;
        } catch (\Throwable) {
        }

        return [(string) $serial, $codeHash, ['code' => $info['code'], 'name' => $info['name'], 'host' => $host ?? $info['customDomain'], 'lossPrevention' => true], trim((string) $orderRef)];
    }

    /** Les expéditions branchées depuis plus de 72 h reviennent à `reserved` (d'un site, ou de toute la plateforme) */
    private function expire(?string $site): void
    {
        $now = gmdate('Y-m-d H:i:s');
        foreach ($this->pairings->shipments($site, LossPairingRepository::SHIPMENT_PLUGGED, 500) as $s) {
            if ($this->expired($s, $now)) {
                $this->pairings->transaction(function () use ($s, $now): void {
                    $locked = $this->pairings->shipment((int) $s['id'], true);
                    if ($locked !== null && $locked['status'] === LossPairingRepository::SHIPMENT_PLUGGED && $this->expired($locked, $now) && $this->pairings->unplugShipment((int) $s['id'])) {
                        $this->pairings->shipmentEvent((int) $s['id'], 'unplugged', null, (string) $s['tenant_code'], ['reason' => 'not_confirmed'], $now);
                    }
                });
            }
        }
    }

    /** @param array<string, mixed> $shipment */
    private function expired(array $shipment, string $now): bool
    {
        return $shipment['plugged_at'] !== null && self::ts((string) $shipment['plugged_at']) + self::CONFIRM_SECONDS <= self::ts($now);
    }

    /** @param array<string, mixed> $pairing une demande encore jumelable */
    private function open(array $pairing): bool
    {
        return $pairing['status'] === LossPairingRepository::WAITING && self::ts((string) $pairing['expires_at']) > time();
    }

    /**
     * Ce que la plateforme voit d'une expédition : jamais l'empreinte du code ni celles du futur boîtier.
     *
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function view(array $s): array
    {
        $iso = fn (mixed $v): ?string => $v !== null ? gmdate('Y-m-d\TH:i:s\+00:00', self::ts((string) $v)) : null;

        return ['id' => (int) $s['id'], 'serial' => (string) $s['serial'], 'tenantCode' => (string) $s['tenant_code'], 'orderRef' => (string) $s['order_ref'],
            'status' => (string) $s['status'], 'boxId' => $s['box_id'] !== null ? (int) $s['box_id'] : null, 'createdAt' => $iso($s['created_at']),
            'createdBy' => $s['created_by'], 'pluggedAt' => $iso($s['plugged_at']), 'confirmBefore' => $s['plugged_at'] !== null ? gmdate('Y-m-d\TH:i:s\+00:00', self::ts((string) $s['plugged_at']) + self::CONFIRM_SECONDS) : null,
            'confirmedAt' => $iso($s['confirmed_at']), 'cancelledAt' => $iso($s['cancelled_at']), 'cancelledBy' => $s['cancelled_by']];
    }

    private function site(): string
    {
        $site = $this->tenant->getTenantCode();
        if (!is_string($site) || $site === '') {
            throw new \LogicException('Aucun site : une expédition se confirme dans un site.');
        }

        return $site;
    }

    private function journal(): LossJournalRepository
    {
        $repo = $this->emProvider->getEntityManager()->getRepository(LossJournalEntry::class);
        \assert($repo instanceof LossJournalRepository);

        return $repo;
    }

    private function duration(int $seconds, string $locale): string
    {
        [$unit, $count] = $seconds < 7200 ? ['minutes', max(1, intdiv($seconds, 60))] : ($seconds < 172800 ? ['hours', intdiv($seconds, 3600)] : ['days', intdiv($seconds, 86400)]);

        return $this->texts->t('health.duration.' . $unit, $locale, ['count' => $count]);
    }

    private static function ts(string $utc): int
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
