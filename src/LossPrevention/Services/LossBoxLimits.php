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
 * - `till` : LIGNES de panier des questions de la caisse « articles non lus avant paiement », par compte (5 minutes ; une question
 *   compte pour ses lignes, au moins une) : une question par paiement suffit très au large ; la route ne sert pas à sonder le
 *   catalogue (lot L3-b1).
 *
 * Jumelage par code (lot L3-a2, § 16.16) : le boîtier n'a encore ni site ni jeton.
 * - `pairOpen` : dépôts d'une demande de jumelage, par adresse (15 minutes) ;
 * - `pairNew` : demandes NOUVELLES (un code que la plateforme ne connaît pas), par adresse (1 heure) : chaque demande est une
 *   ligne de la base maître qui ne s'efface pas, et n'importe qui peut en déposer. Le plafond de TOUTE la plateforme, lui, n'est
 *   pas ici : il se compte dans la base, sous verrou (`LossPairingService::NEW_PER_HOUR`) ;
 * - `pairFailed` : appels du boîtier pour un code inconnu ou avec la mauvaise preuve de machine, par adresse (15 minutes), lu
 *   avant la base : un code ne se devine pas à la chaîne. (Par adresse, comme l'inscription : avant son jumelage un boîtier n'a
 *   rien d'autre qui le désigne ; une fois jumelé il parle avec son jeton, que l'adresse ne limite jamais.)
 * - `pairPolls` : questions « où en est mon jumelage ? » d'un boîtier qui a fait sa preuve, par demande (5 minutes) ;
 * - `pairDelivery` : jetons remis à un boîtier jumelé, par demande (15 minutes) : chaque remise est une inscription (un fait de
 *   plus au journal du boîtier), un boîtier mal réglé ne le remplit pas ;
 * - `pairAdmin` et `pairAdminSite` : essais de code d'un administrateur, par site et adresse, et par site (15 minutes) ;
 * - `pairWarning` : lignes écrites au journal du serveur pour un jumelage qu'on ne peut ni servir ni fermer, par demande (1 heure).
 *
 * Chaque méthode rend null quand l'appel passe, sinon le nombre de secondes à attendre.
 */
class LossBoxLimits
{
    public const LIMITS = ['open' => 20, 'openGlobal' => 60, 'refused' => 60, 'machine' => 10, 'calls' => 600, 'facts' => 20000, 'till' => 6000,
        'pairOpen' => 20, 'pairNew' => 10, 'pairFailed' => 30, 'pairPolls' => 120, 'pairDelivery' => 10, 'pairAdmin' => 10, 'pairAdminSite' => 30, 'pairWarning' => 1, 'shipmentRead' => 120];
    private const INTERVALS = ['open' => '15 minutes', 'openGlobal' => '15 minutes', 'refused' => '15 minutes', 'machine' => '15 minutes', 'calls' => '5 minutes', 'facts' => '1 hour', 'till' => '5 minutes',
        'pairOpen' => '15 minutes', 'pairNew' => '1 hour', 'pairFailed' => '15 minutes', 'pairPolls' => '5 minutes', 'pairDelivery' => '15 minutes', 'pairAdmin' => '15 minutes',
        'pairAdminSite' => '15 minutes', 'pairWarning' => '1 hour', 'shipmentRead' => '5 minutes'];

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

    /** Une question de la caisse de `$lines` lignes de panier, par compte */
    public function till(string $site, int $userId, int $lines): ?int
    {
        return $this->consume('till', $site . '|' . $userId, max(1, $lines));
    }

    /** Un dépôt de demande de jumelage depuis cette adresse */
    public function pairDeposit(string $ip): ?int
    {
        return $this->consume('pairOpen', $ip, 1);
    }

    /** Une demande NOUVELLE (un code que la plateforme ne connaît pas encore) depuis cette adresse */
    public function pairNew(string $ip): ?int
    {
        return $this->consume('pairNew', $ip, 1);
    }

    /** Un jeton remis à un boîtier jumelé */
    public function pairDelivery(string $codeHash): ?int
    {
        return $this->consume('pairDelivery', $codeHash, 1);
    }

    /** Peut-on écrire au journal du serveur pour ce jumelage ? (Une ligne par heure au plus.) */
    public function pairWarning(int $pairingId): bool
    {
        return $this->consume('pairWarning', (string) $pairingId, 1) === null;
    }

    /** Cette adresse a-t-elle encore droit à un code inconnu ou à une mauvaise preuve ? Lu avant la base, sans rien compter */
    public function pairFailedLeft(string $ip): ?int
    {
        return $this->left('pairFailed', $ip);
    }

    /** Un code inconnu, ou la mauvaise preuve de machine */
    public function pairFailed(string $ip): void
    {
        $this->factories['pairFailed']->create($ip)->consume(1);
    }

    /** Une question « où en est mon jumelage ? » d'un boîtier qui a fait sa preuve */
    public function pairPoll(string $codeHash): ?int
    {
        return $this->consume('pairPolls', $codeHash, 1);
    }

    /** Un essai de code par un administrateur */
    public function pairAdmin(string $site, string $ip): ?int
    {
        return $this->consume('pairAdmin', $site . '|' . $ip, 1) ?? $this->consume('pairAdminSite', $site, 1);
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

    /** Lot L6-b : la liste des boîtiers branchés à confirmer, lue par un site et une adresse (la confirmation, elle, compte comme un jumelage) */
    public function shipmentRead(string $site, string $ip): ?int
    {
        return $this->consume('shipmentRead', $site . '|' . $ip, 1);
    }

    private function consume(string $name, string $key, int $tokens): ?int
    {
        $limit = $this->factories[$name]->create($key)->consume($tokens);

        return $limit->isAccepted() ? null : max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }
}
