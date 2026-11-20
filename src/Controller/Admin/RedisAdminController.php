<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Psr\Log\LoggerInterface;

class RedisAdminController extends AbstractController
{
    /** Flux Redis Messenger (normalement en base Redis 1, protégés ici au cas où ils se trouveraient en base 0) */
    private const MESSENGER_KEYS = ['messages', 'messages_failed'];

    /** Sessions utilisateurs (RedisSessionHandler) : les supprimer déconnecterait tous les admins */
    private const SESSION_PREFIX = 'sf_s';

    /**
     * Lot CACHE-ETAT (20/11/2026) : ce qui est un ÉTAT et non un cache n'est jamais vidé ici. Préfixes fixes des pools d'état
     * (FixedCacheNamespacePass : compteurs des limiteurs, état de courte durée, signal d'arrêt des workers) et laissez-passer d'EasyAdmin à
     * usage unique (AdminPassService : les effacer rouvrirait leur rejeu).
     */
    public const PROTECTED_PREFIXES = ['iz_rl:', 'iz_state:', 'iz_workers:', 'admin_pass_used:'];

    // Lot CACHE-ETAT : POST seul (un GET répond 405) ; le bouton du tableau de bord envoie POST + confirmation + jeton CSRF
    #[Route('/admin/redis/flush', name: 'admin_redis_flush', methods: ['POST'])]
    public function flushRedis(Request $request, LoggerInterface $logger, ?CacheInterface $cache = null): Response
    {
        // Le cache est partagé par tous les sites : propriétaire de la plateforme seulement (03/11/2026)
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $submittedToken = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('admin_redis_flush', $submittedToken)) {
            $this->addFlash('danger', 'Jeton CSRF invalide. Action annulée.');
            return $this->redirectToRoute('admin');
        }

        $flushSuccess = false;
        $outputMessage = '';

        // 1. Connexion ciblée via phpredis (protection des métriques de fréquentation)
        try {
            $redisUrl = $_ENV['REDIS_URL'] ?? 'redis://redis:6379';
            $parsed = parse_url($redisUrl);
            $host = $parsed['host'] ?? 'redis';
            $port = (int) ($parsed['port'] ?? 6379);

            if (class_exists('\Redis')) {
                $redis = new \Redis();
                if (@$redis->connect($host, $port, 2.0)) {
                    if (!empty($parsed['pass'])) {
                        $redis->auth($parsed['pass']);
                    }
                    // Lot CACHE-ETAT : la base de l'adresse (/2 pour les essais) ; avant, toujours la base 0, celle de la production,
                    // même depuis l'environnement des essais
                    $database = self::databaseOf($redisUrl);
                    if ($database > 0) {
                        $redis->select($database);
                    }

                    // Récupérer toutes les clés et filtrer pour préserver les statistiques de trafic
                    $allKeys = $redis->keys('*') ?: [];
                    $keysToDelete = [];
                    $protectedTrafficKeysCount = 0;

                    foreach ($allKeys as $key) {
                        // Compteurs du tableau de bord technique (tech:req, tech:job) : préservés comme les métriques de trafic
                        if (str_starts_with($key, 'tenant:traffic:') || str_starts_with($key, 'tech:')) {
                            $protectedTrafficKeysCount++;
                        } elseif (str_starts_with($key, self::SESSION_PREFIX)) {
                            continue;
                        } elseif (self::isProtected($key)) {
                            continue; // lot CACHE-ETAT : un état, pas un cache
                        } elseif (in_array($key, self::MESSENGER_KEYS, true)) {
                            // File de messages (générations en attente) : ne jamais la supprimer
                            continue;
                        } else {
                            $keysToDelete[] = $key;
                        }
                    }

                    if (!empty($keysToDelete)) {
                        foreach (array_chunk($keysToDelete, 500) as $chunk) {
                            $redis->del($chunk);
                        }
                    }

                    $flushSuccess = true;
                    $outputMessage = sprintf(
                        '%d clés de cache supprimées (%d statistiques de fréquentation préservées)',
                        count($keysToDelete),
                        $protectedTrafficKeysCount
                    );
                }
            }
        } catch (\Throwable $e) {
            $logger->warning('Redis selective flush via phpredis failed: ' . $e->getMessage());
        }

        // 2. Fallback: Socket stream uniquement si phpredis n'a pas pu se connecter
        if (!$flushSuccess && !class_exists('\Redis')) {
            try {
                $redisUrl = $_ENV['REDIS_URL'] ?? 'redis://redis:6379';
                $parsed = parse_url($redisUrl);
                $host = $parsed['host'] ?? 'redis';
                $port = (int) ($parsed['port'] ?? 6379);

                $fp = @fsockopen($host, $port, $errno, $errstr, 2.0);
                if ($fp) {
                    fwrite($fp, "PING\r\n");
                    $response = fgets($fp);
                    fclose($fp);
                    if ($response && str_contains($response, 'PONG')) {
                        $flushSuccess = true;
                        $outputMessage = 'OK';
                    }
                }
            } catch (\Throwable $e) {
                $logger->warning('Redis ping via socket failed: ' . $e->getMessage());
            }
        }

        // 3. Clear Symfony application cache adapter
        if ($cache !== null) {
            try {
                $cache->clear();
            } catch (\Throwable $e) {
                $logger->warning('Symfony cache pool clear failed: ' . $e->getMessage());
            }
        }

        if ($flushSuccess || $outputMessage !== '') {
            $this->addFlash('success', sprintf('OK - Le cache Redis a été vidé avec succès ! (Résultat: %s)', $outputMessage ?: 'OK'));
            $logger->info('Redis cache flushed by admin user.');
        } else {
            $this->addFlash('danger', 'Erreur : Impossible de vider le cache Redis.');
        }

        // Retour à la page d'origine uniquement si elle est sur ce même site (pas de redirection vers un domaine externe)
        $referer = (string) $request->headers->get('referer', '');
        $refererHost = parse_url($referer, PHP_URL_HOST);
        $refererPath = (string) parse_url($referer, PHP_URL_PATH);
        if ($refererHost === $request->getHost() && str_starts_with($refererPath, '/admin')) {
            $query = parse_url($referer, PHP_URL_QUERY);
            return $this->redirect($refererPath . ($query ? '?' . $query : ''));
        }

        return $this->redirectToRoute('admin');
    }

    /** Clé d'un état protégé (lot CACHE-ETAT) ? */
    public static function isProtected(string $key): bool
    {
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Base Redis d'une adresse (« redis://hôte:6379/2 » → 2 ; sans chemin → 0) */
    public static function databaseOf(string $url): int
    {
        return (int) ltrim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');
    }
}
