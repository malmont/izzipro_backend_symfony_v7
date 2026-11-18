<?php

namespace App\Services\SubscriptionService;

use Doctrine\DBAL\Connection;

/**
 * Lot PROVISION-3 (18/11/2026) : un seul traitement à la fois par abonnement (webhook Stripe, file de reprise, réconciliation, « Rejouer »).
 * Verrou de session PostgreSQL de la base du site (réentrant : un geste local sous le verrou du webhook le reprend sans attendre), pris
 * AVANT de lire l'abonnement et rendu dans tous les cas ; attente bornée à 10 secondes. Stripe livre les événements en
 * parallèle : sans lui, deux événements du même abonnement liraient le même état et le plus ancien pourrait écrire en dernier, et
 * `invoice.paid` et `invoice.payment_succeeded` de la même facture créeraient deux commandes.
 */
final class SubscriptionLock
{
    public const TIMEOUT_SECONDS = 10;

    /** La clé : le site et l'abonnement Stripe (ou, sans abonnement Stripe, l'abonnement local) */
    public static function key(string $tenantCode, ?string $stripeSubscriptionId, ?int $subscriptionId = null): string
    {
        return 'subscription:' . $tenantCode . ':' . ($stripeSubscriptionId !== null && $stripeSubscriptionId !== '' ? $stripeSubscriptionId : 'local-' . (int) $subscriptionId);
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function run(Connection $connection, string $key, callable $fn, int $timeoutSeconds = self::TIMEOUT_SECONDS): mixed
    {
        // attente bornée : le détenteur peut appeler Stripe sous le verrou ; au-delà du délai, SubscriptionLockTimeout
        $deadline = microtime(true) + $timeoutSeconds;
        while (!filter_var($connection->fetchOne('SELECT pg_try_advisory_lock(hashtext(?))', [$key]), FILTER_VALIDATE_BOOLEAN)) {
            if (microtime(true) >= $deadline) {
                throw new SubscriptionLockTimeout('abonnement tenu par un autre traitement');
            }
            usleep(200000);
        }
        try {
            return $fn();
        } finally {
            try {
                $connection->executeQuery('SELECT pg_advisory_unlock(hashtext(?))', [$key]);
            } catch (\Throwable) {
                // connexion tombée : le verrou de session est rendu avec elle ; l'erreur d'origine n'est pas masquée
            }
        }
    }
}
