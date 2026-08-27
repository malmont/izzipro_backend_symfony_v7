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

        // 1. Try phpredis connection
        try {
            $redisUrl = $_ENV['REDIS_URL'] ?? 'redis://redis:6379';
            $parsed = parse_url($redisUrl);
            $host = $parsed['host'] ?? 'redis';
            $port = $parsed['port'] ?? 6379;

            if (class_exists('\Redis')) {
                $redis = new \Redis();
                if (@$redis->connect($host, (int)$port, 2.0)) {
                    if (isset($parsed['pass']) && $parsed['pass'] !== '') {
                        $redis->auth($parsed['pass']);
                    }
                    if ($redis->flushAll()) {
                        $flushSuccess = true;
                        $outputMessage = 'OK (redis-cli flushall)';
                    }
                }
            }
        } catch (\Throwable $e) {
            $logger->warning('Redis flush via phpredis failed: ' . $e->getMessage());
        }

        // 2. Fallback: Socket stream FLUSHALL
        if (!$flushSuccess) {
            try {
                $redisUrl = $_ENV['REDIS_URL'] ?? 'redis://redis:6379';
                $parsed = parse_url($redisUrl);
                $host = $parsed['host'] ?? 'redis';
                $port = $parsed['port'] ?? 6379;

                $fp = @fsockopen($host, (int)$port, $errno, $errstr, 2.0);
                if ($fp) {
                    fwrite($fp, "FLUSHALL\r\n");
                    $response = fgets($fp);
                    fclose($fp);
                    if ($response && (str_starts_with(trim($response), '+OK') || trim($response) === 'OK')) {
                        $flushSuccess = true;
                        $outputMessage = 'OK (socket FLUSHALL)';
                    }
                }
            } catch (\Throwable $e) {
                $logger->warning('Redis flush via socket failed: ' . $e->getMessage());
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

        return $this->redirectToRoute('admin');
    }
}
