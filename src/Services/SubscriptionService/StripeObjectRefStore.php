<?php

namespace App\Services\SubscriptionService;

use App\Services\TenantConnectionManager;

/**
 * Lot CACHE-ETAT (20/11/2026, contrat validé par le chef) : identifiants des objets Stripe créés à la demande (taux de taxe, produit et
 * prix de livraison des abonnements), gardés en BASE MAÎTRE (`stripe_object_ref`, `ops/sql/cache_state_master.sql`) et non plus dans le
 * cache : un cache vidé ou une montée de version recréait chaque objet chez Stripe (doublons).
 *
 * - Clé unique : compte Stripe (identifiant du compte connecté, ou « platform ») + MODE (test / live) + genre + clé (sha256 de TOUS les
 *   paramètres de création : un paramètre changé donne une autre clé, donc un autre objet, et jamais le refus de Stripe « same idempotency
 *   key, different parameters »).
 * - Création sous verrou consultatif de la base maître (pg_advisory_xact_lock sur la clé complète) : on relit, on crée seulement si rien
 *   n'est gardé ; deux appels parallèles ne créent qu'UN objet. La clé d'idempotence passée à Stripe est fixe pour une génération : si le
 *   processus meurt entre la création chez Stripe et l'écriture de la ligne, Stripe rend le même objet au rejeu (24 h).
 * - Référence périmée : avant usage, l'objet gardé est vérifié (`$alive`) ; disparu (resource_missing) ou archivé chez Stripe, il est
 *   recréé SOUS LE MÊME VERROU avec generation + 1 (clé d'idempotence nouvelle, préfixée par la génération) et la ligne est mise à jour.
 */
class StripeObjectRefStore
{
    public const MODE_TEST = 'test';
    public const MODE_LIVE = 'live';

    public function __construct(private readonly TenantConnectionManager $tenants)
    {
    }

    /** Clé d'un objet : sha256 de tous ses paramètres de création, dans un ordre stable */
    public static function refKey(array $params): string
    {
        return hash('sha256', json_encode(self::sorted($params), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** Clé d'idempotence d'une création : génération + rôle (un objet peut en demander deux : produit puis prix) + clé */
    public static function idempotencyKey(int $generation, string $role, string $refKey): string
    {
        return sprintf('izref-g%d-%s-%s', $generation, $role, $refKey);
    }

    public static function modeOf(?string $secretKey): string
    {
        $key = (string) $secretKey;

        return str_starts_with($key, 'sk_live_') || str_starts_with($key, 'rk_live_') ? self::MODE_LIVE : self::MODE_TEST;
    }

    /**
     * L'identifiant Stripe de l'objet, créé une seule fois.
     *
     * @param callable(string): bool $alive  l'objet gardé existe-t-il encore, actif, chez Stripe ?
     * @param callable(int): string  $create crée l'objet chez Stripe pour cette génération et rend son identifiant
     */
    public function ensure(string $account, string $mode, string $kind, string $refKey, callable $alive, callable $create): string
    {
        $pdo = $this->tenants->getPdoMaster();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))')->execute([implode('|', ['stripe_object_ref', $account, $mode, $kind, $refKey])]);
            $find = $pdo->prepare('SELECT stripe_id, generation FROM stripe_object_ref WHERE account = ? AND mode = ? AND kind = ? AND ref_key = ?');
            $find->execute([$account, $mode, $kind, $refKey]);
            $row = $find->fetch(\PDO::FETCH_ASSOC) ?: null;
            if ($row !== null && $alive((string) $row['stripe_id'])) {
                $pdo->commit();

                return (string) $row['stripe_id'];
            }
            $generation = $row === null ? 1 : (int) $row['generation'] + 1;
            $id = $create($generation);
            $pdo->prepare('INSERT INTO stripe_object_ref (account, mode, kind, ref_key, stripe_id, generation, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON CONFLICT (account, mode, kind, ref_key) DO UPDATE SET stripe_id = EXCLUDED.stripe_id, generation = EXCLUDED.generation, updated_at = NOW()')
                ->execute([$account, $mode, $kind, $refKey, $id, $generation]);
            $pdo->commit();

            return $id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }
}
