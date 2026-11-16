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
 * - `refused` : appels d'un jeton bien signé mais refusé (expiré, révoqué, d'une génération passée), par boîtier ET par génération
 *   (15 minutes) : un tel appel coûte une lecture de la base. Jamais par adresse : les boîtiers d'une boutique sortent par la même
 *   adresse que le Wi-Fi des visiteurs, et personne ne doit pouvoir les faire taire en envoyant du bruit. Un jeton mal signé ne
 *   compte nulle part : il ne coûte aucune lecture. Une ancienne machine qui insiste avec le jeton d'une génération passée ne
 *   gêne pas la machine qui l'a remplacée ;
 * - `machine` : empreintes de machine fausses présentées avec un bon jeton, par boîtier (15 minutes) : un jeton copié ne sert pas
 *   à deviner la machine ;
 * - `calls` : appels d'un boîtier authentifié (5 minutes) ;
 * - `facts` : faits ENVOYÉS par un boîtier (1 heure), comptés à l'arrivée du lot, rejeux et faits refusés compris : rien ne se
 *   supprime dans la table des faits, un boîtier déréglé ne la remplit pas.
 *
 * - `till` : questions de la caisse « articles non lus avant paiement », par compte (5 minutes) : une par paiement suffit très au
 *   large ; la route ne sert pas à sonder le catalogue (lot L3-b1).
 *
 * Chaque méthode rend null quand l'appel passe, sinon le nombre de secondes à attendre.
 */
class LossBoxLimits
{
    public const LIMITS = ['open' => 20, 'openGlobal' => 60, 'refused' => 60, 'machine' => 10, 'calls' => 600, 'facts' => 20000, 'till' => 300];
    private const INTERVALS = ['open' => '15 minutes', 'openGlobal' => '15 minutes', 'refused' => '15 minutes', 'machine' => '15 minutes', 'calls' => '5 minutes', 'facts' => '1 hour', 'till' => '5 minutes'];

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

    /** Ce jeton (ce boîtier, cette génération) a-t-il encore droit à un appel refusé ? Lu avant la base, sans rien compter */
    public function refusedLeft(string $site, int $boxId, int $generation): ?int
    {
        return $this->left('refused', $site . '|' . $boxId . '|' . $generation);
    }

    /** Un appel au jeton bien signé mais refusé */
    public function refused(string $site, int $boxId, int $generation): void
    {
        $this->factories['refused']->create($site . '|' . $boxId . '|' . $generation)->consume(1);
    }

    /** Ce boîtier a-t-il encore droit à une empreinte de machine fausse ? Lu avant de comparer, sans rien compter */
    public function machineLeft(string $site, int $boxId): ?int
    {
        return $this->left('machine', $site . '|' . $boxId);
    }

    /** Une empreinte de machine fausse présentée avec un bon jeton */
    public function machineFailed(string $site, int $boxId): void
    {
        $this->factories['machine']->create($site . '|' . $boxId)->consume(1);
    }

    /** Un appel d'un boîtier authentifié */
    public function call(string $site, int $boxId): ?int
    {
        return $this->consume('calls', $site . '|' . $boxId, 1);
    }

    /** Une question de la caisse, par compte */
    public function till(string $site, int $userId): ?int
    {
        return $this->consume('till', $site . '|' . $userId, 1);
    }

    /** `$count` faits envoyés par un boîtier */
    public function facts(string $site, int $boxId, int $count): ?int
    {
        return $this->consume('facts', $site . '|' . $boxId, $count);
    }

    private function left(string $name, string $key): ?int
    {
        $limit = $this->factories[$name]->create($key)->consume(0);

        return $limit->getRemainingTokens() > 0 ? null : max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }

    private function consume(string $name, string $key, int $tokens): ?int
    {
        $limit = $this->factories[$name]->create($key)->consume($tokens);

        return $limit->isAccepted() ? null : max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }
}
