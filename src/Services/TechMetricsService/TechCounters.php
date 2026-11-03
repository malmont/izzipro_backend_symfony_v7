<?php

namespace App\Services\TechMetricsService;

/**
 * Compteurs techniques par site et par heure dans Redis (tableau de bord technique, T1, 03/11/2026).
 *
 * Clés : `tech:req:<site>:<AAAAMMJJHH>` (hachage n, ms, peak, e4, e5) pour les requêtes web, `tech:job:<site>:<AAAAMMJJHH>`
 * (hachage <file> → nombre) pour les tâches Messenger traitées. Expiration 48 h : le collecteur (`app:tech-metrics:collect`) les
 * vide en table bien avant. Tout échec Redis est avalé : la mesure est perdue, jamais la requête. Le coût du dernier incrément
 * est mémorisé (`$lastCostMs`) pour que les tests le mesurent.
 */
class TechCounters
{
    public const TTL_SECONDS = 172800;
    public const KIND_REQUEST = 'req';
    public const KIND_JOB = 'job';
    /** Site des requêtes qui n'ont pas résolu de site (page de création d'une boutique, santé…) */
    public const PLATFORM_SITE = 'plateforme';
    /** Maximum atomique d'un champ de hachage (le pic mémoire de l'heure) */
    private const LUA_MAX = "local c = redis.call('HGET', KEYS[1], ARGV[1]) if (not c) or tonumber(c) < tonumber(ARGV[2]) then redis.call('HSET', KEYS[1], ARGV[1], ARGV[2]) end return 1";

    /** Durée du dernier incrément, en millisecondes (null si Redis indisponible) ; lue par les tests */
    public static ?float $lastCostMs = null;

    public function __construct(private readonly TechRedis $redis)
    {
    }

    public static function hourKey(string $kind, string $site, \DateTimeInterface $at): string
    {
        return 'tech:' . $kind . ':' . self::site($site) . ':' . $at->format('YmdH');
    }

    /** Code de site sans « : » (séparateur des clés) */
    public static function site(?string $site): string
    {
        $site = trim((string) $site);

        return $site === '' ? self::PLATFORM_SITE : str_replace(':', '_', $site);
    }

    public function countRequest(?string $site, int $durationMs, int $peakMb, int $status, ?\DateTimeImmutable $at = null): void
    {
        $redis = $this->redis->connection();
        if ($redis === null) {
            self::$lastCostMs = null;

            return;
        }
        $started = microtime(true);
        try {
            $key = self::hourKey(self::KIND_REQUEST, self::site($site), $at ?? self::now());
            $pipe = $redis->multi(\Redis::PIPELINE);
            $pipe->hIncrBy($key, 'n', 1);
            $pipe->hIncrBy($key, 'ms', max(0, $durationMs));
            if ($status >= 500) {
                $pipe->hIncrBy($key, 'e5', 1);
            } elseif ($status >= 400) {
                $pipe->hIncrBy($key, 'e4', 1);
            }
            $pipe->eval(self::LUA_MAX, [$key, 'peak', (string) max(0, $peakMb)], 1);
            $pipe->expire($key, self::TTL_SECONDS);
            $pipe->exec();
        } catch (\Throwable) {
            // mesure perdue, jamais la requête
        }
        self::$lastCostMs = (microtime(true) - $started) * 1000;
    }

    public function countJob(?string $site, string $transport, ?\DateTimeImmutable $at = null): void
    {
        $redis = $this->redis->connection();
        if ($redis === null) {
            return;
        }
        try {
            $key = self::hourKey(self::KIND_JOB, self::site($site), $at ?? self::now());
            $pipe = $redis->multi(\Redis::PIPELINE);
            $pipe->hIncrBy($key, $transport === '' ? 'inconnue' : $transport, 1);
            $pipe->expire($key, self::TTL_SECONDS);
            $pipe->exec();
        } catch (\Throwable) {
        }
    }

    /** @return array<string, string> champs du hachage (vide si absent ou Redis indisponible) */
    public function readHour(string $kind, string $site, \DateTimeInterface $at): array
    {
        $redis = $this->redis->connection();
        if ($redis === null) {
            return [];
        }
        try {
            return $redis->hGetAll(self::hourKey($kind, $site, $at)) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<string> toutes les clés d'un type (SCAN, jamais KEYS) */
    public function keys(string $kind): array
    {
        $redis = $this->redis->connection();
        if ($redis === null) {
            return [];
        }
        $found = [];
        try {
            $redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
            $it = null;
            while (($batch = $redis->scan($it, 'tech:' . $kind . ':*', 200)) !== false) {
                foreach ($batch as $key) {
                    $found[] = $key;
                }
                if ($it === 0) {
                    break;
                }
            }
        } catch (\Throwable) {
        }
        sort($found);

        return array_values(array_unique($found));
    }

    public function delete(string ...$keys): void
    {
        $redis = $this->redis->connection();
        if ($redis === null || $keys === []) {
            return;
        }
        try {
            $redis->del(...$keys);
        } catch (\Throwable) {
        }
    }

    /** Décompose une clé en [kind, site, heure UTC] ; null si la clé n'a pas la forme attendue */
    public static function parseKey(string $key): ?array
    {
        $parts = explode(':', $key, 4);
        if (count($parts) !== 4 || $parts[0] !== 'tech' || !preg_match('/^\d{10}$/', $parts[3])) {
            return null;
        }
        $hour = \DateTimeImmutable::createFromFormat('!YmdH', $parts[3], new \DateTimeZone('UTC'));

        return $hour ? [$parts[1], $parts[2], $hour] : null;
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
