<?php

namespace App\Services;

use Psr\Log\LoggerInterface;

class TenantVisitorTrackingService
{
    private ?\Redis $redis = null;
    private bool $redisConnected = false;
    private string $secretKey;

    public function __construct(
        private LoggerInterface $logger,
        ?string $secretKey = null
    ) {
        $this->secretKey = $secretKey ?: ($_ENV['APP_SECRET'] ?? 'tenant-analytics-salt');
    }

    /**
     * Initialise la connexion Redis de façon paresseuse (lazy-load) et sécurisée.
     */
    private function getRedis(): ?\Redis
    {
        if ($this->redis !== null) {
            return $this->redisConnected ? $this->redis : null;
        }

        if (!class_exists('\Redis')) {
            $this->logger->warning('[TenantVisitorTrackingService] Extension phpredis non installée.');
            $this->redisConnected = false;
            return null;
        }

        try {
            $redisUrl = $_ENV['REDIS_URL'] ?? 'redis://redis:6379';
            $parsed = parse_url($redisUrl);
            $host = $parsed['host'] ?? 'redis';
            $port = (int) ($parsed['port'] ?? 6379);

            $this->redis = new \Redis();
            if (@$this->redis->connect($host, $port, 1.5)) {
                if (!empty($parsed['pass'])) {
                    $this->redis->auth($parsed['pass']);
                }
                $this->redisConnected = true;
            } else {
                $this->redisConnected = false;
                $this->logger->warning("[TenantVisitorTrackingService] Impossible de se connecter à Redis sur {$host}:{$port}");
            }
        } catch (\Throwable $e) {
            $this->redisConnected = false;
            $this->logger->warning('[TenantVisitorTrackingService] Erreur connexion Redis: ' . $e->getMessage());
        }

        return $this->redisConnected ? $this->redis : null;
    }

    /**
     * Enregistre une visite pour un tenant donné de façon non-bloquante et 100% conforme RGPD.
     */
    public function trackVisit(string $tenantCode, string $ip, ?string $userAgent = '', ?string $path = '/'): void
    {
        $redis = $this->getRedis();
        if (!$redis) {
            return;
        }

        $cleanCode = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $tenantCode));
        if (empty($cleanCode)) {
            return;
        }

        // Hachage anonymisé RGPD (l'IP brute n'est JAMAIS stockée en clair)
        // Le sel journalier garantit que le hash ne peut pas être corrélé d'un jour à l'autre
        $today = date('Y-m-d');
        $thisMonth = date('Y-m');
        $visitorHash = hash_hmac('sha256', $ip . '|' . ($userAgent ?? '') . '|' . $today, $this->secretKey);

        try {
            $now = time();

            // 1. Visiteurs en direct (fenêtre glissante de 15 minutes)
            $onlineKey = "tenant:traffic:{$cleanCode}:online";
            $redis->zAdd($onlineKey, $now, $visitorHash);
            $redis->expire($onlineKey, 1800); // 30 minutes TTL

            // 2. Visiteurs uniques du jour (HyperLogLog : ultra-léger, ~12KB de RAM par tenant)
            $dailyUniqueKey = "tenant:traffic:{$cleanCode}:unique:{$today}";
            $redis->pfAdd($dailyUniqueKey, [$visitorHash]);
            $redis->expire($dailyUniqueKey, 86400 * 45); // Conserver 45 jours

            // 3. Visiteurs uniques du mois en cours
            $monthlyUniqueKey = "tenant:traffic:{$cleanCode}:unique:{$thisMonth}";
            $redis->pfAdd($monthlyUniqueKey, [$visitorHash]);
            $redis->expire($monthlyUniqueKey, 86400 * 90); // Conserver 90 jours

            // 4. Déduplication intelligente des requêtes concurrentes frontend (ex: Next.js lance 5 à 10 requêtes API simultanées pour charger 1 page)
            // On incrémente le compteur de page vue au maximum 1 fois toutes les 15 secondes par visiteur et par page
            $cleanPath = !empty($path) ? (parse_url($path, PHP_URL_PATH) ?: '/') : '/';
            if (strlen($cleanPath) > 120) {
                $cleanPath = substr($cleanPath, 0, 120) . '...';
            }

            $pvDebounceKey = "tenant:traffic:{$cleanCode}:debounce:{$visitorHash}:" . md5($cleanPath);
            $isNewPageView = (bool) $redis->set($pvDebounceKey, '1', ['nx', 'ex' => 15]);

            if ($isNewPageView) {
                // Compteur de vraies pages vues aujourd'hui
                $dailyViewsKey = "tenant:traffic:{$cleanCode}:views:{$today}";
                $redis->incr($dailyViewsKey);
                $redis->expire($dailyViewsKey, 86400 * 45);

                // Total historique de pages vues
                $redis->incr("tenant:traffic:{$cleanCode}:views:total");

                // Enregistrement de la page consultée (Top pages via Redis Sorted Set)
                $pagesKey = "tenant:traffic:{$cleanCode}:pages:total";
                $redis->zIncrBy($pagesKey, 1, $cleanPath);
                $redis->expire($pagesKey, 86400 * 90); // 90 jours
            }
        } catch (\Throwable $e) {
            $this->logger->warning("[TenantVisitorTrackingService] Échec enregistrement visite: " . $e->getMessage());
        }
    }

    /**
     * Récupère les métriques d'un tenant spécifique.
     *
     * @return array{online: int, today_unique: int, month_unique: int, today_views: int, total_views: int}
     */
    public function getTenantStats(string $tenantCode): array
    {
        $redis = $this->getRedis();
        if (!$redis) {
            return [
                'online' => 0,
                'today_unique' => 0,
                'month_unique' => 0,
                'today_views' => 0,
                'total_views' => 0,
            ];
        }

        $cleanCode = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $tenantCode));
        $today = date('Y-m-d');
        $thisMonth = date('Y-m');

        try {
            $now = time();
            $onlineKey = "tenant:traffic:{$cleanCode}:online";

            // Nettoyage des visiteurs inactifs depuis plus de 15 minutes (900 secondes)
            $redis->zRemRangeByScore($onlineKey, '-inf', (string) ($now - 900));
            $online = (int) $redis->zCard($onlineKey);

            // Visiteurs uniques du jour
            $todayUnique = (int) $redis->pfCount("tenant:traffic:{$cleanCode}:unique:{$today}");

            // Visiteurs uniques du mois
            $monthUnique = (int) $redis->pfCount("tenant:traffic:{$cleanCode}:unique:{$thisMonth}");

            // Pages vues aujourd'hui
            $todayViews = (int) ($redis->get("tenant:traffic:{$cleanCode}:views:{$today}") ?: 0);

            // Pages vues totales
            $totalViews = (int) ($redis->get("tenant:traffic:{$cleanCode}:views:total") ?: 0);

            // Pages les plus visitées (Top 5)
            $topPages = $this->getTopPages($cleanCode, 5);
            $topPage = !empty($topPages) ? $topPages[0]['path'] : '/';

            return [
                'online' => $online,
                'today_unique' => $todayUnique,
                'month_unique' => $monthUnique,
                'today_views' => $todayViews,
                'total_views' => $totalViews,
                'top_page' => $topPage,
                'top_pages' => $topPages,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning("[TenantVisitorTrackingService] Erreur lecture stats tenant '{$tenantCode}': " . $e->getMessage());
            return [
                'online' => 0,
                'today_unique' => 0,
                'month_unique' => 0,
                'today_views' => 0,
                'total_views' => 0,
                'top_page' => '/',
                'top_pages' => [],
            ];
        }
    }

    /**
     * Récupère la liste ordonnée des pages les plus visitées pour un tenant.
     *
     * @return array<array{path: string, views: int}>
     */
    public function getTopPages(string $tenantCode, int $limit = 5): array
    {
        $redis = $this->getRedis();
        if (!$redis) {
            return [];
        }

        $cleanCode = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $tenantCode));
        $pagesKey = "tenant:traffic:{$cleanCode}:pages:total";

        try {
            $raw = $redis->zRevRange($pagesKey, 0, $limit - 1, true);
            $pages = [];
            if (is_array($raw)) {
                foreach ($raw as $page => $views) {
                    $pages[] = [
                        'path' => (string) $page,
                        'views' => (int) $views,
                    ];
                }
            }
            return $pages;
        } catch (\Throwable $e) {
            $this->logger->warning("[TenantVisitorTrackingService] Erreur getTopPages '{$tenantCode}': " . $e->getMessage());
            return [];
        }
    }

    /**
     * Rapport complet et détaillé de fréquentation pour un tenant unique.
     *
     * @return array{
     *     tenant_code: string,
     *     tenant_name: string,
     *     domain: string,
     *     online: int,
     *     today_unique: int,
     *     month_unique: int,
     *     today_views: int,
     *     total_views: int,
     *     top_page: string,
     *     top_pages: array<array{path: string, views: int, percentage: float}>,
     *     labels_7days: string,
     *     values_7days: string
     * }
     */
    public function getTenantDetailedReport(string $tenantCode, ?string $tenantName = null, ?string $domain = null): array
    {
        $cleanCode = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $tenantCode));
        $stats = $this->getTenantStats($cleanCode);

        // Historique des 7 derniers jours (Visiteurs uniques par jour)
        $redis = $this->getRedis();
        $labels7Days = [];
        $values7Days = [];

        for ($i = 6; $i >= 0; $i--) {
            $dateStr = date('Y-m-d', strtotime("-$i days"));
            $label = date('d M', strtotime("-$i days"));
            $labels7Days[] = $label;

            $count = 0;
            if ($redis) {
                try {
                    $count = (int) $redis->pfCount("tenant:traffic:{$cleanCode}:unique:{$dateStr}");
                } catch (\Throwable $e) {}
            }
            $values7Days[] = $count;
        }

        // Synchroniser le dernier jour (aujourd'hui) avec les stats directes
        $values7Days[6] = max($values7Days[6], $stats['today_unique']);

        // Calculer la part de chaque page consultée dans le Top
        $topPages = $stats['top_pages'] ?? [];
        $totalPageViews = max(1, (int) array_sum(array_column($topPages, 'views')));
        foreach ($topPages as &$p) {
            $p['percentage'] = round(($p['views'] / $totalPageViews) * 100, 1);
        }
        unset($p);

        return [
            'tenant_code' => $cleanCode,
            'tenant_name' => $tenantName ?: $cleanCode,
            'domain' => $domain ?: "{$cleanCode}.com",
            'online' => $stats['online'],
            'today_unique' => $stats['today_unique'],
            'month_unique' => $stats['month_unique'],
            'today_views' => $stats['today_views'],
            'total_views' => $stats['total_views'],
            'top_page' => $stats['top_page'],
            'top_pages' => $topPages,
            'labels_7days' => json_encode($labels7Days),
            'values_7days' => json_encode($values7Days),
        ];
    }

    /**
     * Calcule et consolide les statistiques de trafic pour tous les tenants fournis.
     *
     * @param array<array{id: int, code: string, name: string, dbname: string, custom_domain: ?string}> $tenants
     * @return array{
     *     total_online: int,
     *     total_today_unique: int,
     *     total_month_unique: int,
     *     total_today_views: int,
     *     top_tenant_name: string,
     *     top_tenant_visits: int,
     *     tenants_details: array,
     *     chart_labels: string,
     *     chart_series: string,
     *     chart_views_series: string
     * }
     */
    public function getGlobalTrafficMetrics(array $tenants): array
    {
        $totalOnline = 0;
        $totalTodayUnique = 0;
        $totalMonthUnique = 0;
        $totalTodayViews = 0;

        $tenantsDetails = [];
        $topTenantName = 'Aucun';
        $maxVisits = -1;

        $chartLabels = [];
        $chartVisitorsSeries = [];
        $chartViewsSeries = [];

        foreach ($tenants as $t) {
            $code = $t['code'];
            $name = !empty($t['name']) ? $t['name'] : $code;
            $domain = !empty($t['custom_domain']) ? $t['custom_domain'] : "{$code}.arkanoa-media.com";

            $stats = $this->getTenantStats($code);

            $totalOnline += $stats['online'];
            $totalTodayUnique += $stats['today_unique'];
            $totalMonthUnique += $stats['month_unique'];
            $totalTodayViews += $stats['today_views'];

            if ($stats['month_unique'] > $maxVisits) {
                $maxVisits = $stats['month_unique'];
                $topTenantName = $name;
            }

            $tenantsDetails[] = [
                'id' => $t['id'] ?? null,
                'code' => $code,
                'name' => $name,
                'domain' => $domain,
                'online' => $stats['online'],
                'today_unique' => $stats['today_unique'],
                'month_unique' => $stats['month_unique'],
                'today_views' => $stats['today_views'],
                'total_views' => $stats['total_views'],
                'top_page' => $stats['top_page'] ?? '/',
                'top_pages' => $stats['top_pages'] ?? [],
                'traffic_percentage' => 0, // Calculé ci-dessous
            ];

            $chartLabels[] = $name;
            $chartVisitorsSeries[] = $stats['month_unique'];
            $chartViewsSeries[] = $stats['today_views'];
        }

        // Calcul des pourcentages de répartition du trafic
        $basisTotal = $totalMonthUnique > 0 ? $totalMonthUnique : ($totalTodayViews > 0 ? $totalTodayViews : 0);
        foreach ($tenantsDetails as &$item) {
            if ($basisTotal > 0) {
                $value = $totalMonthUnique > 0 ? $item['month_unique'] : $item['today_views'];
                $item['traffic_percentage'] = round(($value / $basisTotal) * 100, 1);
            } else {
                $item['traffic_percentage'] = 0;
            }
        }
        unset($item);

        return [
            'total_online' => $totalOnline,
            'total_today_unique' => $totalTodayUnique,
            'total_month_unique' => $totalMonthUnique,
            'total_today_views' => $totalTodayViews,
            'top_tenant_name' => $topTenantName,
            'top_tenant_visits' => max(0, $maxVisits),
            'tenants_details' => $tenantsDetails,
            'chart_labels' => json_encode($chartLabels),
            'chart_series' => json_encode($chartVisitorsSeries),
            'chart_views_series' => json_encode($chartViewsSeries),
        ];
    }
}
