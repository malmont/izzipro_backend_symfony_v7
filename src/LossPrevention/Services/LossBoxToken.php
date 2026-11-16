<?php

namespace App\LossPrevention\Services;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Jeton d'un boîtier (lot L3-a, `docs/prevention-pertes.md` § 16) : `b<boîtier>.<génération>.<échéance>.<signature>`, signé avec
 * le secret de l'application pour CE site. Rien n'est stocké : le jeton dit quel boîtier, de quelle génération, jusqu'à quand ; la
 * signature prouve que c'est le serveur qui l'a écrit. Il ne suffit jamais seul : chaque appel relit le boîtier (actif, même
 * génération), donc une révocation ou une remise à zéro de la clé vaut tout de suite.
 *
 * Durée courte (24 h), renouvelé au signe de vie dès la mi-vie. Un boîtier resté coupé du réseau plus longtemps échange son jeton
 * expiré contre un neuf (30 jours de grâce, avec l'empreinte de sa machine) ; au-delà il faut une nouvelle inscription.
 */
class LossBoxToken
{
    public const HEADER = 'X-Loss-Box';
    public const LIFETIME = 86400;
    /** Le signe de vie rend un jeton neuf quand il reste moins que cette durée */
    public const RENEW_BEFORE = 43200;
    /** Délai pendant lequel un jeton expiré s'échange encore contre un neuf */
    public const GRACE = 2592000;

    public function __construct(#[Autowire('%kernel.secret%')] private readonly string $secret)
    {
    }

    /** @return array{token: string, expiresAt: int} */
    public function issue(string $tenantCode, int $boxId, int $generation, ?int $now = null): array
    {
        $expires = ($now ?? time()) + self::LIFETIME;

        return ['token' => sprintf('b%d.%d.%d.%s', $boxId, $generation, $expires, $this->signature($tenantCode, $boxId, $generation, $expires)), 'expiresAt' => $expires];
    }

    /**
     * Ce que dit un jeton dont la signature est bonne pour ce site, qu'il soit expiré ou non ; null sinon.
     *
     * @return array{boxId: int, generation: int, expiresAt: int}|null
     */
    public function read(string $tenantCode, mixed $token): ?array
    {
        if (!is_string($token) || preg_match('/\Ab([1-9]\d{0,9})\.([1-9]\d{0,9})\.([1-9]\d{9,10})\.([0-9a-f]{64})\z/', $token, $m) !== 1) {
            return null;
        }
        [$boxId, $generation, $expires] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if (!hash_equals($this->signature($tenantCode, $boxId, $generation, $expires), $m[4])) {
            return null;
        }

        return ['boxId' => $boxId, 'generation' => $generation, 'expiresAt' => $expires];
    }

    /**
     * Empreinte gardée d'une clé de boîtier : liée au site et au secret de l'application. Une copie de la base ne suffit pas à
     * retrouver la clé (il faut aussi le secret), et la clé copiée avec une base ne vaut pas sur un autre site.
     */
    public function keyHash(string $tenantCode, string $key): string
    {
        return hash_hmac('sha256', 'loss-box-key|' . $tenantCode . '|' . $key, $this->secret);
    }

    /** Empreinte gardée de la machine inscrite : même principe que la clé */
    public function machineHash(string $tenantCode, string $machine): string
    {
        return hash_hmac('sha256', 'loss-box-machine|' . $tenantCode . '|' . $machine, $this->secret);
    }

    /**
     * Empreinte gardée d'un code de jumelage (lot L3-a2), dans la base maître : le code n'a pas encore de site. Liée au secret de
     * l'application : une copie de la table ne rend aucun code.
     */
    public function pairingCodeHash(string $code): string
    {
        return hash_hmac('sha256', 'loss-pairing-code|' . $code, $this->secret);
    }

    /** Empreinte gardée de la machine qui a déposé une demande de jumelage : la preuve qu'elle présente à chaque appel */
    public function pairingMachineHash(string $machine): string
    {
        return hash_hmac('sha256', 'loss-pairing-machine|' . $machine, $this->secret);
    }

    /**
     * Empreinte posée à la place de la clé d'un boîtier JUMELÉ par code : elle lie le boîtier du site au code qui l'a créé, et à
     * ce site. Elle ne peut pas être celle d'une clé `IZ-BOX` (la forme d'une clé ne commence jamais par « pairing| »).
     */
    public function pairingKeyHash(string $tenantCode, string $code): string
    {
        return $this->keyHash($tenantCode, 'pairing|' . $code);
    }

    private function signature(string $tenantCode, int $boxId, int $generation, int $expires): string
    {
        return hash_hmac('sha256', sprintf('loss-box|%s|%d|%d|%d', $tenantCode, $boxId, $generation, $expires), $this->secret);
    }
}
