<?php

namespace App\Services\Worker;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * État des workers Messenger, vu depuis l'application web (les workers tournent dans d'autres conteneurs) :
 * - battement écrit par chaque worker dans le cache applicatif (WorkerHeartbeatSubscriber) ;
 * - contenu des files, lu directement dans Redis (messages en attente, message en cours de traitement) ;
 * - demande de redémarrage, identique à la commande « messenger:stop-workers » (arrêt entre deux messages, Docker
 *   relance aussitôt le conteneur).
 */
class WorkerMonitor
{
    /** Transport => libellé et suffixe de la file (voir config/packages/messenger.yaml) */
    public const WORKERS = [
        'async' => ['label' => 'Mémoires Vivantes — chapitres', 'suffix' => ''],
        'esg' => ['label' => 'Boussole ESG — rapports', 'suffix' => '_esg'],
        'landing_ai' => ['label' => 'Assistant IA des landing pages', 'suffix' => '_landing_ai'],
        'media' => ['label' => 'Médiathèque — vidéos pour le défilement', 'suffix' => '_media'],
        'email' => ['label' => 'Courriels — tous les sites', 'suffix' => '_email'],
    ];

    /** Secondes entre deux battements d'un worker au repos */
    public const HEARTBEAT_INTERVAL = 5;

    /** Au-delà, un worker au repos qui ne bat plus est en redémarrage (cache reconstruit : 10 à 30 s) */
    private const ALIVE_SECONDS = 30;

    /** Au-delà, il est considéré comme arrêté */
    private const RESTART_SECONDS = 120;

    /** Un traitement plus long que cela est anormal (appels à l'IA bornés à 10 min chacun) */
    private const LONG_PROCESSING_SECONDS = 900;

    /** Base Redis des files (option « dbindex » de messenger.yaml) */
    private const MESSENGER_REDIS_DB = 1;

    private const RESTART_KEY = 'workers.restart_requested_timestamp';

    private ?\Redis $redis = null;
    private bool $redisUnavailable = false;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        #[Autowire(service: 'cache.messenger.restart_workers_signal')]
        private readonly CacheItemPoolInterface $restartSignalPool,
        #[Autowire('%env(MESSENGER_TRANSPORT_DSN)%')]
        private readonly string $transportDsn,
        private readonly LoggerInterface $logger
    ) {
    }

    /** Écrit par le worker lui-même */
    public function beat(string $transport, array $data): void
    {
        try {
            $item = $this->cache->getItem(self::cacheKey($transport));
            $item->set($data + ['at' => time()]);
            $item->expiresAfter(86400);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            // Le battement ne doit jamais interrompre un traitement
            $this->logger->warning('WorkerMonitor : battement non enregistré : ' . $e->getMessage());
        }
    }

    public function heartbeat(string $transport): ?array
    {
        try {
            $item = $this->cache->getItem(self::cacheKey($transport));

            return $item->isHit() && is_array($item->get()) ? $item->get() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Messages d'une file : « waiting » = pas encore pris par le worker, « inFlight » = pris, pas encore terminé.
     * null si la file n'est pas lisible (Redis injoignable, transport en mémoire des tests).
     *
     * @return array{waiting: int, inFlight: int}|null
     */
    public function queue(string $transport): ?array
    {
        $stream = $this->streamName(self::WORKERS[$transport]['suffix'] ?? null);
        $redis = $this->redis();
        if ($stream === null || $redis === null) {
            return null;
        }

        try {
            $groups = $redis->xInfo('GROUPS', $stream);
            if (!is_array($groups)) {
                // File jamais créée : aucun message n'y a été envoyé
                return ['waiting' => 0, 'inFlight' => 0];
            }
            foreach ($groups as $group) {
                if (($group['name'] ?? null) !== 'symfony') {
                    continue;
                }
                $waiting = $group['lag'] ?? null;
                if (!is_int($waiting)) {
                    // « lag » indisponible après des suppressions : on compte ce qui suit le dernier message remis
                    $waiting = count($redis->xRange($stream, '(' . ($group['last-delivered-id'] ?? '0-0'), '+', 500) ?: []);
                }

                return ['waiting' => $waiting, 'inFlight' => (int) ($group['pending'] ?? 0)];
            }

            return ['waiting' => (int) $redis->xLen($stream), 'inFlight' => 0];
        } catch (\Throwable $e) {
            $this->logger->warning("WorkerMonitor : file « $stream » illisible : " . $e->getMessage());

            return null;
        }
    }

    /** true : rien en attente ni en cours ; false : au moins un message ; null : inconnu */
    public function isQueueEmpty(string $transport): ?bool
    {
        $queue = $this->queue($transport);

        return $queue === null ? null : ($queue['waiting'] + $queue['inFlight'] === 0);
    }

    /** Messages dont toutes les tentatives ont échoué (file « failed ») */
    public function failedCount(): ?int
    {
        $stream = $this->streamName('_failed');
        $redis = $this->redis();
        if ($stream === null || $redis === null) {
            return null;
        }
        try {
            return (int) $redis->xLen($stream);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Synthèse par worker, pour l'écran d'administration.
     *
     * @return array<string, array{label: string, state: string, stateLabel: string, level: string, detail: string, heartbeat: ?array, queue: ?array}>
     */
    public function status(): array
    {
        $now = time();
        $status = [];
        foreach (self::WORKERS as $transport => $config) {
            $beat = $this->heartbeat($transport);
            $queue = $this->queue($transport);
            $age = $beat !== null ? $now - (int) ($beat['at'] ?? 0) : null;

            if ($beat === null) {
                [$state, $label, $level, $detail] = ['unknown', 'Inconnu', 'secondary', 'Aucun battement reçu : le worker n\'a pas démarré depuis la mise en place du suivi, ou le cache vient d\'être vidé.'];
            } elseif (($beat['state'] ?? '') === 'processing') {
                $duration = $now - (int) ($beat['since'] ?? $now);
                $long = $duration > self::LONG_PROCESSING_SECONDS;
                [$state, $label, $level] = ['processing', 'En cours de traitement', $long ? 'danger' : 'primary'];
                $detail = sprintf('%s, depuis %s.%s', $beat['message'] ?? 'message', self::duration($duration), $long ? ' Durée anormale : le worker est peut-être bloqué.' : '');
            } elseif ($age <= self::ALIVE_SECONDS && ($beat['state'] ?? '') !== 'stopped') {
                [$state, $label, $level, $detail] = ['idle', 'Actif, en attente', 'success', sprintf('Dernier battement il y a %s.', self::duration($age))];
            } elseif ($age <= self::RESTART_SECONDS) {
                [$state, $label, $level, $detail] = ['restarting', 'Redémarrage en cours', 'warning', sprintf('Dernier battement il y a %s (un redémarrage prend 10 à 30 s).', self::duration($age))];
            } else {
                [$state, $label, $level, $detail] = ['down', 'Arrêté', 'danger', sprintf('Aucun battement depuis %s. Vérifier le conteneur Docker du worker.', self::duration($age))];
            }

            $status[$transport] = [
                'label' => $config['label'],
                'state' => $state,
                'stateLabel' => $label,
                'level' => $level,
                'detail' => $detail,
                'heartbeat' => $beat,
                'queue' => $queue,
            ];
        }

        return $status;
    }

    /** Le worker du transport répond-il (au repos, en traitement ou en redémarrage) ? null : inconnu */
    public function isWorkerUp(string $transport): ?bool
    {
        $state = $this->status()[$transport]['state'] ?? 'unknown';

        return $state === 'unknown' ? null : $state !== 'down';
    }

    /** Même signal que « messenger:stop-workers » : chaque worker s'arrête après son message en cours */
    public function requestRestart(): void
    {
        $item = $this->restartSignalPool->getItem(self::RESTART_KEY);
        $item->set(microtime(true));
        $this->restartSignalPool->save($item);
    }

    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' min ' . ($seconds % 60) . ' s';
        }

        return intdiv($seconds, 3600) . ' h ' . intdiv($seconds % 3600, 60) . ' min';
    }

    private static function cacheKey(string $transport): string
    {
        return 'worker_heartbeat.' . preg_replace('/[^a-z0-9_]/', '_', $transport);
    }

    /** Nom de la file Redis : chemin du DSN, suivi du suffixe du transport */
    private function streamName(?string $suffix): ?string
    {
        if ($suffix === null || !str_starts_with($this->transportDsn, 'redis')) {
            return null;
        }
        $path = trim((string) parse_url($this->transportDsn, PHP_URL_PATH), '/');
        $stream = explode('/', $path)[0] ?: 'messages';

        return $stream . $suffix;
    }

    private function redis(): ?\Redis
    {
        if ($this->redis !== null || $this->redisUnavailable) {
            return $this->redis;
        }
        if (!class_exists(\Redis::class) || !str_starts_with($this->transportDsn, 'redis')) {
            $this->redisUnavailable = true;

            return null;
        }

        try {
            $parts = parse_url($this->transportDsn);
            $redis = new \Redis();
            $redis->connect($parts['host'] ?? 'redis', (int) ($parts['port'] ?? 6379), 1.0);
            if (!empty($parts['pass'])) {
                $redis->auth(isset($parts['user']) && $parts['user'] !== '' ? [urldecode($parts['user']), urldecode($parts['pass'])] : urldecode($parts['pass']));
            }
            $redis->select(self::MESSENGER_REDIS_DB);

            return $this->redis = $redis;
        } catch (\Throwable $e) {
            $this->logger->warning('WorkerMonitor : Redis injoignable : ' . $e->getMessage());
            $this->redisUnavailable = true;

            return null;
        }
    }
}
