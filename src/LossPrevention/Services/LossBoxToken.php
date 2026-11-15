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

    private function signature(string $tenantCode, int $boxId, int $generation, int $expires): string
    {
        return hash_hmac('sha256', sprintf('loss-box|%s|%d|%d|%d', $tenantCode, $boxId, $generation, $expires), $this->secret);
    }
}
