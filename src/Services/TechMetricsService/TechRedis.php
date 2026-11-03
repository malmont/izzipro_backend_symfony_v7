<?php

namespace App\Services\TechMetricsService;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Connexion Redis des compteurs techniques (tableau de bord technique, T1, 03/11/2026) : l'instance du cache (REDIS_URL, sans éviction),
 * ouverte à la première utilisation, jamais bloquante : Redis injoignable → null, et l'appelant abandonne silencieusement la mesure.
 * Un échec de connexion n'est retenté qu'au prochain processus PHP (pas de boucle de reconnexion dans une requête).
 */
class TechRedis
{
    private ?\Redis $redis = null;
    private bool $unavailable = false;

    public function __construct(
        #[Autowire('%env(REDIS_URL)%')] private readonly string $redisUrl,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function connection(): ?\Redis
    {
        if ($this->redis !== null || $this->unavailable) {
            return $this->redis;
        }
        if (!class_exists(\Redis::class) || !str_starts_with($this->redisUrl, 'redis')) {
            $this->unavailable = true;

            return null;
        }
        try {
            $parts = parse_url($this->redisUrl) ?: [];
            $redis = new \Redis();
            $redis->connect($parts['host'] ?? 'redis', (int) ($parts['port'] ?? 6379), 0.5);
            if (!empty($parts['pass'])) {
                $redis->auth(isset($parts['user']) && $parts['user'] !== '' ? [urldecode($parts['user']), urldecode($parts['pass'])] : urldecode($parts['pass']));
            }
            $db = (int) trim((string) ($parts['path'] ?? ''), '/');
            if ($db > 0) {
                $redis->select($db);
            }

            return $this->redis = $redis;
        } catch (\Throwable $e) {
            $this->logger->warning('[Tech] Redis injoignable, compteurs techniques suspendus : ' . $e->getMessage());
            $this->unavailable = true;

            return null;
        }
    }
}
