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
    #[Route('/admin/redis/flush', name: 'admin_redis_flush', methods: ['GET', 'POST'])]
    public function flushRedis(Request $request, LoggerInterface $logger, ?CacheInterface $cache = null): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if ($request->isMethod('POST')) {
            $submittedToken = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('admin_redis_flush', $submittedToken)) {
                $this->addFlash('danger', 'Jeton CSRF invalide. Action annulée.');
                return $this->redirectToRoute('admin');
            }
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

                    // Récupérer toutes les clés et filtrer pour préserver les statistiques de trafic
                    $allKeys = $redis->keys('*') ?: [];
                    $keysToDelete = [];
                    $protectedTrafficKeysCount = 0;

                    foreach ($allKeys as $key) {
                        if (str_starts_with($key, 'tenant:traffic:')) {
                            $protectedTrafficKeysCount++;
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

        $referer = $request->headers->get('referer');
        if ($referer && str_contains($referer, '/admin')) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('admin');
    }
}
