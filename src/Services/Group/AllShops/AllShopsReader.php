<?php

namespace App\Services\Group\AllShops;

use App\Gestion\Services\GestionValidationException;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\CaisseService\StoreClock;
use App\Services\FeatureAccess\ModuleAccessService;
use App\Services\Group\GroupRepository;
use App\Services\Platform\PlatformModeService;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Exception\DriverException;
use App\Services\Http\RequestPath;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Lot G-2 : lit une route dans chaque boutique du groupe, tour à tour, au sein de la requête du directeur (`GroupScope`).
 *
 * - **Retour au siège quoi qu'il arrive** : le site de départ est repris dans un `finally`, même si une boutique lève une exception.
 * - **Aucune écriture** : chaque boutique est lue dans une transaction `READ ONLY`, annulée à la fin ; une lecture qui tenterait d'écrire
 *   échoue (statut `error`), elle n'écrit jamais.
 * - **Délai** : `statement_timeout` local à la transaction (3 s, ou ce qui reste du budget) ; une boutique plus lente passe en `timeout`.
 *   Budget de la réponse : 15 s, les boutiques restantes passent en `skipped`.
 * - **Cache court** : la part d'une boutique (5 min) sous les étiquettes du site et de sa base (toute invalidation du site la vide), et
 *   dans la clé l'EMPREINTE des tables lues (compteurs d'écritures de PostgreSQL) et le jour de la boutique : une écriture dans une table
 *   lue, par Doctrine ou en SQL, la périme dès que PostgreSQL l'a comptée (quelques secondes au plus).
 * - Une boutique sans le module de la route (et de la gestion) : `module_off` ; en panne : `error`, code seul (le détail au journal).
 *   Une erreur de validation de la requête (422) ou un refus HTTP remonte tel quel : la même requête est fausse pour toutes.
 */
class AllShopsReader
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenants,
        private readonly GroupRepository $groups,
        private readonly ModuleAccessService $modules,
        private readonly PlatformModeService $platform,
        private readonly StoreClock $clock,
        private readonly TenantCurrencyProvider $currency,
        private readonly TagAwareCacheInterface $cache,
        private readonly LoggerInterface $logger,
        // réglables pour les essais seulement (le conteneur garde les valeurs de `GroupScope`)
        private readonly int $budgetMs = GroupScope::BUDGET_MS,
        private readonly int $shopTimeoutMs = GroupScope::SHOP_TIMEOUT_MS,
    ) {
    }

    /**
     * Point d'entrée des contrôleurs : null sans « Toutes les boutiques » (la route répond seule) ; sinon la réponse réunie.
     *
     * @param array<string, mixed> $keyParts
     * @param callable(): array{data: array<string, mixed>, sums?: array<string, mixed>} $one
     * @param callable(list<array<string, mixed>>): list<array<string, mixed>> $merge
     * @return array<string, mixed>|null
     */
    public function forRequest(Request $request, string $route, array $keyParts, callable $one, callable $merge): ?array
    {
        $group = $request->attributes->get(GroupScope::ATTRIBUTE);
        if (!is_array($group)) {
            return null;
        }
        if ($request->attributes->get('_route') !== $route) {
            throw new \LogicException('Toutes les boutiques : la route lue n\'est pas celle de la requête');
        }

        return AllShopsMerger::response($group, $this->read($group, $route, RequestPath::of($request), $keyParts, $one), $merge);
    }

    /**
     * @param array<string, mixed> $group le groupe relu par la garde
     * @param string $path le chemin de la route (le module de la route est celui de la garde commune)
     * @param array<string, mixed> $keyParts ce qui distingue la lecture (paramètres, langue) : partie de la clé du cache
     * @param callable(): array{data: array<string, mixed>, sums?: array<string, mixed>} $one la lecture d'UNE boutique, dans le site courant
     * @return array{shops: list<array<string, mixed>>, truncated: bool}
     */
    public function read(array $group, string $route, string $path, array $keyParts, callable $one): array
    {
        $config = GroupScope::ROUTES[$route] ?? throw new \LogicException('Route sans fusion : ' . $route);
        $homeDb = (string) ($this->emProvider->getEntityManager()->getConnection()->getParams()['dbname'] ?? '');
        $homeCode = $this->tenants->getTenantCode();
        $shops = $this->groups->shopDatabases((int) $group['id']);
        $truncated = count($shops) > GroupScope::MAX_SHOPS;
        $shops = array_slice($shops, 0, GroupScope::MAX_SHOPS);
        $start = hrtime(true);
        $parts = [];
        try {
            foreach ($shops as $shop) {
                $remaining = $this->budgetMs - self::since($start);
                if ($remaining <= 0) {
                    $parts[] = self::part($shop, GroupScope::SKIPPED);
                    continue;
                }
                $parts[] = $this->readShop($shop, $route, $path, $config, $keyParts, $one, (int) min($this->shopTimeoutMs, $remaining));
            }
        } finally {
            // le siège, quoi qu'il arrive : la suite de la requête (réponse, journal, cache) lit le site de départ
            $this->emProvider->switchTenant($homeDb, $homeCode);
        }

        return ['shops' => $parts, 'truncated' => $truncated];
    }

    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $config
     * @param array<string, mixed> $keyParts
     * @return array<string, mixed>
     */
    private function readShop(array $shop, string $route, string $path, array $config, array $keyParts, callable $one, int $timeoutMs): array
    {
        $started = hrtime(true);
        try {
            if (($shop['dbname'] ?? null) === null || $shop['dbname'] === '') {
                throw new \RuntimeException('boutique sans base connue');
            }
            $this->emProvider->switchTenant((string) $shop['dbname'], (string) $shop['tenant_code']);
            $identity = ['currency' => $this->currency->code(), 'timezone' => $this->clock->timezone()->getName()];
            if (!$this->modulesOn($path, $config)) {
                return self::part($shop, GroupScope::MODULE_OFF) + $identity;
            }
            $read = fn (): array => $this->readOnly($one, $timeoutMs);
            $result = $config['cache'] ? $this->cached($shop, $route, $keyParts, $read) : $read();
            if (self::since($started) > $this->shopTimeoutMs) {
                return self::part($shop, GroupScope::TIMEOUT) + $identity; // trop lente cette fois : la part reste en cache pour la suivante
            }

            return self::part($shop, GroupScope::OK) + $identity + $result;
        } catch (GestionValidationException|HttpExceptionInterface $e) {
            throw $e; // la requête elle-même est fausse : 422 ou refus, comme la route seule
        } catch (\Throwable $e) {
            if ($e instanceof DriverException && $e->getSQLState() === '57014') {
                return self::part($shop, GroupScope::TIMEOUT);
            }
            $this->logger->error(sprintf('Toutes les boutiques : %s illisible pour %s (%s : %s)', (string) $shop['tenant_code'], $route, $e::class, $e->getMessage()));

            return self::part($shop, GroupScope::ERROR) + ['code' => 'SHOP_UNAVAILABLE'];
        }
    }

    /** @param array<string, mixed> $config */
    private function modulesOn(string $path, array $config): bool
    {
        if ($this->platform->isDemo()) {
            return true; // mode demo de la plateforme : la garde commune n'en refuse aucun, la fusion non plus
        }
        foreach (array_filter([ModuleAccessService::moduleForPath($path, 'GET'), $config['module']]) as $module) {
            if (!$this->modules->isEnabled($module)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function readOnly(callable $one, int $timeoutMs): array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $conn->beginTransaction();
        try {
            $conn->executeStatement('SET TRANSACTION READ ONLY');
            $conn->executeStatement('SET LOCAL statement_timeout = ' . max(1, $timeoutMs));

            return $one();
        } finally {
            try {
                $conn->rollBack();
            } catch (\Throwable) {
                // transaction déjà close : rien à annuler
            }
        }
    }

    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $keyParts
     * @return array<string, mixed>
     */
    private function cached(array $shop, string $route, array $keyParts, callable $read): array
    {
        $code = (string) $shop['tenant_code'];
        $db = (string) $shop['dbname'];
        $key = 'group_part_' . md5((string) json_encode([$code, $db, $route, $keyParts, $this->fingerprint($route),
            (new \DateTimeImmutable('now', $this->clock->timezone()))->format('Y-m-d')]));

        return $this->cache->get($key, static function (ItemInterface $item) use ($read, $code, $db): array {
            $item->expiresAfter(GroupScope::CACHE_TTL);
            $item->tag([TenantCacheService::tenantTag($code), TenantCacheService::databaseTag($db)]);

            return $read();
        });
    }

    /** Compteurs d'écritures (insertions, modifications, suppressions) des tables que lit la route, dans la base du site courant */
    private function fingerprint(string $route): string
    {
        $tables = GroupScope::TABLES[$route] ?? [];

        return (string) $this->emProvider->getEntityManager()->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(n_tup_ins + n_tup_upd + n_tup_del), 0)::text || \'.\' || COUNT(*)::text FROM pg_stat_user_tables WHERE schemaname = current_schema() AND relname IN (?)',
            [$tables], [\Doctrine\DBAL\ArrayParameterType::STRING]);
    }

    /**
     * @param array<string, mixed> $shop
     * @return array<string, mixed>
     */
    private static function part(array $shop, string $status): array
    {
        return ['site' => (string) $shop['tenant_code'], 'name' => $shop['name'] ?? null, 'head' => ($shop['origin'] ?? null) === 'head', 'status' => $status];
    }

    private static function since(int $start): int
    {
        return (int) ((hrtime(true) - $start) / 1_000_000);
    }
}
