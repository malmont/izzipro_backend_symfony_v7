<?php

namespace App\LossPrevention\Services;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Plafonds du canal du boîtier (lot L3-a, `docs/prevention-pertes.md` § 16.13), gardés dans le pool des limites de débit :
 *
 * - `open` : essais d'inscription et d'échange de jeton, par site et adresse (15 minutes) ; `openGlobal` : par adresse seule ;
 * - `failed` : appels au jeton refusé, par site et adresse (15 minutes) : un jeton périmé coûte une lecture de la base par appel ;
 * - `calls` : appels d'un boîtier authentifié (5 minutes) ;
 * - `facts` : faits ENVOYÉS par un boîtier (1 heure) : rien ne se supprime dans la table des faits, un boîtier déréglé ne la
 *   remplit pas.
 *
 * Chaque méthode rend null quand l'appel passe, sinon le nombre de secondes à attendre.
 */
class LossBoxLimits
{
    public const LIMITS = ['open' => 20, 'openGlobal' => 60, 'failed' => 60, 'calls' => 600, 'facts' => 20000];
    private const INTERVALS = ['open' => '15 minutes', 'openGlobal' => '15 minutes', 'failed' => '15 minutes', 'calls' => '5 minutes', 'facts' => '1 hour'];

    /** @var array<string, RateLimiterFactory> */
    private array $factories = [];

    /** @param array<string, int> $limits plafonds à la place de ceux de `LIMITS` (essais) */
    public function __construct(#[Autowire(service: 'cache.rate_limiter')] CacheItemPoolInterface $pool, array $limits = [])
    {
        $storage = new CacheStorage($pool);
        foreach (self::LIMITS as $name => $limit) {
            $this->factories[$name] = new RateLimiterFactory(['id' => 'loss_box_' . $name, 'policy' => 'sliding_window', 'limit' => $limits[$name] ?? $limit, 'interval' => self::INTERVALS[$name]], $storage);
        }
    }

    /** Un essai d'inscription ou d'échange de jeton depuis cette adresse */
    public function open(string $site, string $ip): ?int
    {
        return $this->consume('open', $site . '|' . $ip, 1) ?? $this->consume('openGlobal', $ip, 1);
    }

    /** Cette adresse a-t-elle encore droit à un appel au jeton douteux ? (lu avant de vérifier le jeton, sans rien compter) */
    public function failuresLeft(string $site, string $ip): ?int
    {
        $limit = $this->factories['failed']->create($site . '|' . $ip)->consume(0);

        return $limit->getRemainingTokens() > 0 ? null : max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }

    /** Un appel au jeton refusé depuis cette adresse */
    public function failed(string $site, string $ip): void
    {
        $this->factories['failed']->create($site . '|' . $ip)->consume(1);
    }

    /** Un appel d'un boîtier authentifié */
    public function call(string $site, int $boxId): ?int
    {
        return $this->consume('calls', $site . '|' . $boxId, 1);
    }

    /** `$count` faits envoyés par un boîtier */
    public function facts(string $site, int $boxId, int $count): ?int
    {
        return $this->consume('facts', $site . '|' . $boxId, $count);
    }

    private function consume(string $name, string $key, int $tokens): ?int
    {
        $limit = $this->factories[$name]->create($key)->consume($tokens);

        return $limit->isAccepted() ? null : max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }
}
