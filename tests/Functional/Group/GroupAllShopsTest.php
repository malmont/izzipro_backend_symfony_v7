<?php

namespace App\Tests\Functional\Group;

use App\Entity\User;
use App\Services\Group\AllShops\AllShopsMerger;
use App\Services\Group\AllShops\AllShopsReader;
use App\Services\Group\AllShops\GroupScope;
use App\Services\Platform\PlatformModeService;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot G-2 (18/11/2026, contrat validé par le chef) : « Toutes les boutiques ». Le directeur d'un groupe envoie `X-Group-Scope: all` sur le
 * site du siège ; le serveur lit chaque boutique et réunit les réponses par devise, en lecture seule.
 * - Refus : valeur inconnue, écriture, route qui ne sait pas fusionner, paramètre propre à un site, compte qui n'est pas le directeur
 *   (administrateur d'une boutique, directeur ENTRÉ dans une boutique, module Multi-boutiques éteint).
 * - Identité : la part de chaque boutique est exactement la route lue seule sur cette boutique ; les totaux sont ses sommes.
 * - Une boutique en panne au milieu : listée en erreur, les autres répondent, et le site du siège est repris (même requête, et la suivante).
 * - Pertes : jamais d'analyse lancée, aucun nom de salarié (liste blanche des champs).
 * - Cache : une écriture dans une table lue périme la part de la boutique ; délai par boutique et budget de la réponse.
 *
 * Premier site de test : le siège ; second : la boutique du groupe.
 */
class GroupAllShopsTest extends WebTestCase
{
    private KernelBrowser $client;
    private \PDO $master;
    private string $tag;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->tag = bin2hex(random_bytes(3));
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    public function testOnlyTheDirectorReadsAllShopsAndOnlyForReading(): void
    {
        $director = $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'directeur', ['ROLE_ADMIN']);
        $this->assertSame([403, GroupScope::FORBIDDEN], $this->refusal('GET', '/api/gestion/journee', $director), 'sans groupe');
        $this->group();
        $shopAdmin = $this->account(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'boutique', ['ROLE_ADMIN']);

        // sans l'en-tête : la route répond seule, comme avant
        $alone = $this->json('GET', '/api/gestion/journee', $director, MV_TEST_TENANT_HOST, null);
        $this->assertArrayNotHasKey('scope', $alone);
        $this->assertArrayHasKey('sales', $alone);

        $this->assertSame([400, GroupScope::INVALID], $this->refusal('GET', '/api/gestion/journee', $director, MV_TEST_TENANT_HOST, 'toutes'));
        $this->assertSame([403, GroupScope::READ_ONLY], $this->refusal('POST', '/api/gestion/pertes/analyse', $director));
        $this->assertSame([403, GroupScope::READ_ONLY], $this->refusal('PUT', '/api/gestion/pertes/regles/EXIT_WITHOUT_SALE', $director));
        $this->assertSame([400, GroupScope::UNSUPPORTED], $this->refusal('GET', '/api/gestion/tresorerie', $director), 'une route qui ne sait pas réunir (la projection reste à la boutique)');
        $this->assertSame([400, GroupScope::UNSUPPORTED], $this->refusal('GET', '/api/gestion/tresorerie/rapprochements', $director));
        $this->assertSame([400, GroupScope::UNSUPPORTED], $this->refusal('GET', '/api/contacts', $director));
        $this->assertSame([400, GroupScope::UNSUPPORTED], $this->refusal('GET', '/api/gestion/statistiques?sections=payments', $director), 'une section');
        $this->assertSame([400, GroupScope::UNSUPPORTED], $this->refusal('GET', '/api/gestion/statistiques?periodId=1', $director), 'une période est propre à un site');
        // l'administrateur d'une boutique du groupe, chez lui : pas le directeur, même si sa boutique a le module
        $this->assertSame([403, GroupScope::FORBIDDEN], $this->refusal('GET', '/api/gestion/journee', $shopAdmin, MV_TEST_TENANT2_HOST));
        $this->module(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'is_multi_shop_active', true);
        $this->assertSame([403, GroupScope::FORBIDDEN], $this->refusal('GET', '/api/gestion/journee', $shopAdmin, MV_TEST_TENANT2_HOST));
        $this->module(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'is_multi_shop_active', false);
        // le directeur ENTRÉ dans la boutique : un opérateur, jamais « toutes les boutiques »
        $entered = $this->json('POST', '/api/me/contexts/enter', $director, MV_TEST_TENANT_HOST, null, ['site' => MV_TEST_TENANT2_CODE]);
        $this->assertSame([403, GroupScope::FORBIDDEN], $this->refusal('GET', '/api/gestion/journee', (string) $entered['token'], MV_TEST_TENANT2_HOST));
        // un compte du siège sans rôle d'administrateur : refusé avant (accès à la gestion) ; module éteint au siège : plus de directeur
        $this->assertContains($this->refusal('GET', '/api/gestion/journee', $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'caissier', ['ROLE_USER_POS']))[0], [401, 403]);
        $this->module(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'is_multi_shop_active', false);
        $this->assertSame([403, GroupScope::FORBIDDEN], $this->refusal('GET', '/api/gestion/journee', $director));

        // les contextes du directeur nomment les routes qui savent réunir
        $this->module(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'is_multi_shop_active', true);
        $contexts = $this->json('GET', '/api/me/contexts', $director, MV_TEST_TENANT_HOST, null);
        $this->assertSame(['/api/gestion/statistiques', '/api/gestion/journee', '/api/gestion/pertes', '/api/commandes/lignes/a-recevoir', '/api/gestion/tresorerie/soldes',
            '/api/gestion/tresorerie/mouvements', '/api/gestion/statistiques/annee', '/api/gestion/groupe/resultat'], $contexts['group']['allShops']);
    }

    public function testEachShopPartIsTheRouteReadAloneAndTheTotalsAreTheirSums(): void
    {
        $this->group();
        $director = $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'directeur', ['ROLE_ADMIN']);
        $shopAdmin = $this->account(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'boutique', ['ROLE_ADMIN']);
        $alone = [MV_TEST_TENANT_CODE => [$director, MV_TEST_TENANT_HOST], MV_TEST_TENANT2_CODE => [$shopAdmin, MV_TEST_TENANT2_HOST]];

        foreach (['/api/gestion/statistiques?range=month', '/api/gestion/statistiques?range=year&source=pos', '/api/gestion/journee', '/api/gestion/pertes'] as $path) {
            $all = $this->json('GET', $path, $director, MV_TEST_TENANT_HOST, GroupScope::VALUE);
            $this->assertSame('group', $all['scope'], $path);
            $this->assertSame([MV_TEST_TENANT_CODE, MV_TEST_TENANT2_CODE], array_column($all['shops'], 'site'), $path);
            $this->assertSame([GroupScope::OK, GroupScope::OK], array_column($all['shops'], 'status'), $path);
            foreach ($all['shops'] as $shop) {
                $this->assertArrayNotHasKey('sums', $shop, 'les sommes internes ne sortent pas');
                [$token, $host] = $alone[$shop['site']];
                $own = $this->json('GET', str_starts_with($path, '/api/gestion/pertes') ? '/api/gestion/pertes?analyse=0' : $path, $token, $host, null);
                if (str_starts_with($path, '/api/gestion/pertes')) {
                    $own = json_decode((string) json_encode(AllShopsMerger::lossPart($own)), true);
                }
                $this->assertSame(self::stable($own), self::stable($shop['data']), "$path : la part de {$shop['site']} est la route lue seule");
            }
            // totaux : par devise, sommes des parts
            foreach ($all['totals'] as $total) {
                $parts = array_values(array_filter($all['shops'], static fn (array $s): bool => in_array($s['site'], $total['shops'], true)));
                $this->assertSame(array_unique(array_column($parts, 'currency')), [$total['currency']]);
                if (str_starts_with($path, '/api/gestion/statistiques')) {
                    foreach (['revenue', 'grossMargin', 'orders'] as $k) {
                        $this->assertSame(array_sum(array_map(static fn (array $p) => $p['data']['indicators'][$k]['value'], $parts)), $total['indicators'][$k]['value'], "$path $k");
                    }
                    $this->assertSame(array_sum(array_map(static fn (array $p) => $p['data']['indicators']['stockValue']['value'], $parts)), $total['indicators']['stockValue']['value']);
                } elseif ($path === '/api/gestion/journee') {
                    $this->assertSame(array_sum(array_map(static fn (array $p) => $p['data']['sales']['total'], $parts)), $total['sales']['total']);
                    $this->assertSame(array_sum(array_map(static fn (array $p) => $p['data']['stock']['lowCount'], $parts)), $total['stock']['lowCount']);
                } else {
                    $this->assertSame(array_sum(array_map(static fn (array $p) => $p['data']['counts']['open'], $parts)), $total['counts']['open']);
                    $this->assertArrayNotHasKey('byRegister', $total['counts'], 'les postes d\'une boutique ne se réunissent pas');
                }
            }
        }
    }

    public function testTheLossPartNeverRunsTheAnalysisAndNamesNobody(): void
    {
        $this->group();
        $director = $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'directeur', ['ROLE_ADMIN']);
        $before = $this->analysisMark(MV_TEST_TENANT2_DB);
        $all = $this->json('GET', '/api/gestion/pertes?analyse=1', $director, MV_TEST_TENANT_HOST, GroupScope::VALUE);
        $this->assertSame($before, $this->analysisMark(MV_TEST_TENANT2_DB), 'aucune analyse lancée, même demandée');
        foreach ($all['shops'] as $shop) {
            $this->assertSame(['period', 'counts', 'amountAtStake', 'currency', 'cashDesk', 'lastAnalysis', 'caveats'], array_keys($shop['data']));
            $this->assertSame(['open', 'watch', 'new', 'active', 'byStatus', 'byRule', 'byRegister'], array_keys($shop['data']['counts']));
            $this->assertSame(['at', 'pending', 'blocked'], array_keys($shop['data']['lastAnalysis']));
            foreach ($shop['data']['counts']['byRegister'] as $register) {
                $this->assertSame(['id', 'name', 'open', 'total'], array_keys($register), 'par poste, jamais par personne');
            }
        }
    }

    public function testABrokenShopInTheMiddleIsListedAndTheHeadIsReadAgainAfterIt(): void
    {
        $this->client->disableReboot();
        $groupId = $this->group(false);
        $broken = 'g2-casse-' . $this->tag;
        $this->master->exec("INSERT INTO tenants (code, name, dbname) VALUES ('$broken', 'Boutique en panne', 'db_absente_{$this->tag}')");
        $alias = 'g2-alias-' . $this->tag; // un second code de site sur la base de la boutique : lue et comptée une fois
        $this->master->exec("INSERT INTO tenants (code, name, dbname) VALUES ('$alias', 'Alias', '" . MV_TEST_TENANT2_DB . "')");
        $this->master->exec("INSERT INTO shop_group_sites (group_id, tenant_code, origin) VALUES ($groupId, '$broken', 'associated'), ($groupId, '" . MV_TEST_TENANT2_CODE . "', 'associated'), ($groupId, '$alias', 'associated')");
        $director = $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'directeur', ['ROLE_ADMIN']);
        $baseline = $this->json('GET', '/api/gestion/journee', $director, MV_TEST_TENANT_HOST, null);

        $all = $this->json('GET', '/api/gestion/journee', $director, MV_TEST_TENANT_HOST, GroupScope::VALUE);
        $this->assertSame([[MV_TEST_TENANT_CODE, 'ok'], [$broken, 'error'], [MV_TEST_TENANT2_CODE, 'ok'], [$alias, 'duplicate']], array_map(static fn (array $s): array => [$s['site'], $s['status']], $all['shops']));
        $this->assertSame(MV_TEST_TENANT2_CODE, $all['shops'][3]['sameAs']);
        $this->assertSame([MV_TEST_TENANT_CODE, MV_TEST_TENANT2_CODE], array_merge(...array_column($all['totals'], 'shops')));
        $this->assertSame(['site', 'name', 'head', 'status', 'code'], array_keys($all['shops'][1]), 'en panne : le code seul, jamais le détail');
        $this->assertSame('SHOP_UNAVAILABLE', $all['shops'][1]['code']);
        $this->assertStringNotContainsString('db_absente', (string) json_encode($all));
        // même processus : la connexion est revenue au siège
        $container = static::getContainer();
        $this->assertSame(MV_TEST_TENANT_CODE, $container->get(TenantConnectionProvider::class)->getTenantCode());
        $em = $container->get(TenantEntityManagerProvider::class)->getEntityManager();
        $this->assertSame(MV_TEST_TENANT_DB, $em->getConnection()->getParams()['dbname']);
        $this->assertFalse($em->getConnection()->isTransactionActive(), 'aucune transaction laissée ouverte');
        // la requête suivante, sur le siège : le siège, avec sa devise, son fuseau et sa fiche
        $after = $this->json('GET', '/api/gestion/journee', $director, MV_TEST_TENANT_HOST, null);
        $this->assertSame([$baseline['currency'], $baseline['timezone']], [$after['currency'], $after['timezone']]);
        $this->assertSame(self::stable($baseline), self::stable($after));
        $this->assertSame((string) $this->pdo(MV_TEST_TENANT_DB)->query('SELECT name FROM entreprise ORDER BY id LIMIT 1')->fetchColumn(),
            (string) $em->getConnection()->fetchOne('SELECT name FROM entreprise ORDER BY id LIMIT 1'));
    }

    public function testAShopWithoutTheModuleIsListedAsSuchInLiveMode(): void
    {
        // La plateforme entière n'est PAS passée en mode réel (cela changerait les droits de module des autres essais) : le lecteur reçoit
        // une plateforme simulée « réelle », et la boutique perd le module de gestion le temps de l'essai.
        $groupId = $this->group();
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $live = new class ($container->get(TenantConnectionManager::class), $container->get(\Symfony\Contracts\Cache\CacheInterface::class), $container->get(\Psr\Log\LoggerInterface::class)) extends PlatformModeService {
            public function isDemo(): bool
            {
                return false;
            }
        };
        $reader = new AllShopsReader($provider, $container->get(TenantConnectionProvider::class), $container->get(\App\Services\Group\GroupRepository::class),
            $container->get(\App\Services\FeatureAccess\ModuleAccessService::class), $live, $container->get(\App\Services\CaisseService\StoreClock::class),
            $container->get(\App\Services\BoutiqueSettingsService\TenantCurrencyProvider::class), $container->get(\Symfony\Contracts\Cache\TagAwareCacheInterface::class),
            $container->get(\Psr\Log\LoggerInterface::class));
        $read = static fn (): array => ['data' => ['n' => 1]];
        $columns = 'is_management_app_active';
        $before = [$this->pdo(MV_TEST_TENANT_DB)->query("SELECT $columns FROM entreprise ORDER BY id LIMIT 1")->fetchColumn(), $this->pdo(MV_TEST_TENANT2_DB)->query("SELECT $columns FROM entreprise ORDER BY id LIMIT 1")->fetchColumn()];
        try {
            $this->module(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, $columns, true);
            $this->module(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, $columns, false);
            $out = $reader->read(['id' => $groupId, 'name' => 'Siège'], 'gestion_journee', '/api/gestion/journee', ['essai' => $this->tag], $read);
            $this->assertSame([GroupScope::OK, GroupScope::MODULE_OFF], array_column($out['shops'], 'status'));
            $this->assertArrayNotHasKey('data', $out['shops'][1]);
            $this->assertSame([[MV_TEST_TENANT_CODE]], array_column(AllShopsMerger::losses(array_map(static fn (array $s): array => $s + ($s['status'] === GroupScope::OK
                ? ['data' => AllShopsMerger::lossPart([])] : []), $out['shops'])), 'shops'), 'les totaux ne portent que les boutiques lues');
        } finally {
            $this->module(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, $columns, in_array($before[0], [true, 1, '1', 't'], true));
            $this->module(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, $columns, in_array($before[1], [true, 1, '1', 't'], true));
        }
    }

    public function testAWriteInATableReadExpiresTheCachedPartOfThatShop(): void
    {
        $this->group();
        $director = $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'directeur', ['ROLE_ADMIN']);
        $shop = $this->pdo(MV_TEST_TENANT2_DB);
        $shop->exec("DELETE FROM gestion_cash_balance WHERE note = 'g2-{$this->tag}'");
        $cash = fn (): mixed => $this->json('GET', '/api/gestion/journee', $director, MV_TEST_TENANT_HOST, GroupScope::VALUE)['shops'][1]['data']['cash'];
        $first = $cash();
        $this->assertSame($first, $cash(), 'deux lectures de suite : la même part');
        try {
            $shop->exec("INSERT INTO gestion_cash_balance (balance_date, amount_cents, note, created_by, created_at, updated_at) VALUES (CURRENT_DATE - 1, 123456, 'g2-{$this->tag}', 'essai', NOW(), NOW())");
            // la connexion d'essai reste ouverte : ses compteurs partiraient au plus tard en 10 s ; une requête PHP, elle, ferme la sienne
            $shop->query('SELECT pg_stat_force_next_flush()');
            $shop->query('SELECT 1');
            $seen = null;
            for ($i = 0; $i < 20 && ($seen === null || $seen === $first); ++$i) { // les compteurs de PostgreSQL : quelques instants au plus
                usleep(150_000);
                $seen = $cash();
            }
            $this->assertNotSame($first, $seen, 'la part de la boutique relit la trésorerie après l\'écriture');
        } finally {
            $shop->exec("DELETE FROM gestion_cash_balance WHERE note = 'g2-{$this->tag}'");
        }
    }

    public function testAShopPastItsDelayTimesOutAndTheRestPastTheBudgetIsSkipped(): void
    {
        $groupId = $this->group();
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $reader = fn (int $budget, int $delay): AllShopsReader => new AllShopsReader($provider, $container->get(TenantConnectionProvider::class),
            $container->get(\App\Services\Group\GroupRepository::class), $container->get(\App\Services\FeatureAccess\ModuleAccessService::class),
            $container->get(PlatformModeService::class), $container->get(\App\Services\CaisseService\StoreClock::class),
            $container->get(\App\Services\BoutiqueSettingsService\TenantCurrencyProvider::class), $container->get(\Symfony\Contracts\Cache\TagAwareCacheInterface::class),
            $container->get(\Psr\Log\LoggerInterface::class), $budget, $delay);
        $group = ['id' => $groupId, 'name' => 'Siège'];
        $slow = fn (): array => ['data' => ['slept' => $provider->getEntityManager()->getConnection()->fetchOne('SELECT pg_sleep(2)')]];

        $started = hrtime(true);
        $read = $reader(10_000, 200)->read($group, 'gestion_pertes', '/api/gestion/pertes', [], $slow);
        $this->assertSame([GroupScope::TIMEOUT, GroupScope::TIMEOUT], array_column($read['shops'], 'status'), 'au-delà du délai d\'une boutique');
        $this->assertLessThan(1500, (hrtime(true) - $started) / 1e6, 'la requête lente est coupée au délai, pas attendue');
        // le délai vaut pour la boutique ENTIÈRE : beaucoup de requêtes courtes (chacune sous le délai) sont arrêtées à l'échéance
        $many = function () use ($provider): array {
            for ($i = 0; $i < 40; ++$i) {
                $provider->getEntityManager()->getConnection()->fetchOne('SELECT pg_sleep(0.05)');
            }

            return ['data' => ['done' => true]];
        };
        $started = hrtime(true);
        $read = $reader(10_000, 300)->read($group, 'gestion_pertes', '/api/gestion/pertes', [], $many);
        $this->assertSame([GroupScope::TIMEOUT, GroupScope::TIMEOUT], array_column($read['shops'], 'status'));
        $this->assertLessThan(1500, (hrtime(true) - $started) / 1e6, 'deux boutiques de 300 ms au plus');
        // un service qui avale l'annulation et rend des chiffres : la boutique reste en `timeout`, jamais servie à moitié
        $swallow = function () use ($provider): array {
            for ($i = 0; $i < 40; ++$i) {
                try {
                    $provider->getEntityManager()->getConnection()->fetchOne('SELECT pg_sleep(0.05)');
                } catch (\Throwable) {
                }
            }

            return ['data' => ['partial' => true]];
        };
        $read = $reader(10_000, 300)->read($group, 'gestion_pertes', '/api/gestion/pertes', [], $swallow);
        $this->assertSame([GroupScope::TIMEOUT, GroupScope::TIMEOUT], array_column($read['shops'], 'status'));
        $this->assertArrayNotHasKey('data', $read['shops'][0]);
        $read = $reader(1, 1000)->read($group, 'gestion_pertes', '/api/gestion/pertes', [], $slow);
        $this->assertSame([GroupScope::TIMEOUT, GroupScope::SKIPPED], array_column($read['shops'], 'status'), 'budget épuisé : la suivante n\'est pas lue');
        // une part en cache n'est calculée qu'une fois par boutique ; une autre clé (paramètres) la recalcule
        $calls = 0;
        $counted = function () use (&$calls): array {
            ++$calls;

            return ['data' => ['n' => 1]];
        };
        $reader(10_000, 3000)->read($group, 'gestion_journee', '/api/gestion/journee', ['essai' => $this->tag], $counted);
        $reader(10_000, 3000)->read($group, 'gestion_journee', '/api/gestion/journee', ['essai' => $this->tag], $counted);
        $this->assertSame(2, $calls, 'deux boutiques, lues une fois chacune');
        $reader(10_000, 3000)->read($group, 'gestion_journee', '/api/gestion/journee', ['essai' => $this->tag . '-autre'], $counted);
        $this->assertSame(4, $calls);
        // une table verrouillée dans une boutique (une migration en cours) : la lecture n'attend pas, elle passe en `timeout` dans le délai
        $locker = $this->pdo(MV_TEST_TENANT2_DB);
        $locker->exec('BEGIN');
        try {
            $locker->exec('LOCK TABLE entreprise IN ACCESS EXCLUSIVE MODE');
            $started = hrtime(true);
            $read = $reader(10_000, 300)->read($group, 'gestion_pertes', '/api/gestion/pertes', [], fn (): array => ['data' => ['n' => 1]]);
            $this->assertSame([GroupScope::OK, GroupScope::TIMEOUT], array_column($read['shops'], 'status'));
            $this->assertLessThan(1500, (hrtime(true) - $started) / 1e6, 'aucune attente sans fin du verrou');
        } finally {
            $locker->exec('ROLLBACK');
        }
        // une écriture tentée pendant une lecture de boutique échoue : jamais rien d'écrit
        $write = fn (): array => ['data' => ['n' => $provider->getEntityManager()->getConnection()->executeStatement("UPDATE entreprise SET name = name || ' g2'")]];
        $read = $reader(10_000, 3000)->read($group, 'gestion_pertes', '/api/gestion/pertes', [], $write);
        $this->assertSame([GroupScope::ERROR, GroupScope::ERROR], array_column($read['shops'], 'status'));
        $this->assertStringEndsNotWith(' g2', (string) $this->pdo(MV_TEST_TENANT2_DB)->query('SELECT name FROM entreprise ORDER BY id LIMIT 1')->fetchColumn());
        $this->assertSame(MV_TEST_TENANT_DB, $provider->getEntityManager()->getConnection()->getParams()['dbname']);
    }

    public function testTheLossReadNeverStartsTheAnalysisEvenWhenItIsDue(): void
    {
        $this->group();
        $director = $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'directeur', ['ROLE_ADMIN']);
        $spy = new class extends \App\LossPrevention\Services\LossAnalysisService {
            public int $runs = 0;

            public function __construct()
            {
            }

            public function isStale(): bool
            {
                return true;
            }

            public function run(int $limit = self::BATCH): array
            {
                ++$this->runs;

                return ['status' => 'done', 'read' => 0, 'opened' => 0, 'completed' => 0, 'skipped' => 0];
            }

            public function state(): array
            {
                return ['at' => null, 'pending' => true, 'blocked' => null, 'skipped' => null];
            }
        };
        $this->client->disableReboot();
        static::getContainer()->set(\App\LossPrevention\Services\LossAnalysisService::class, $spy);
        $this->json('GET', '/api/gestion/pertes?analyse=1', $director, MV_TEST_TENANT_HOST, GroupScope::VALUE);
        $this->assertSame(0, $spy->runs, 'toutes les boutiques : l\'analyse due n\'est jamais lancée, même demandée');
        $this->json('GET', '/api/gestion/pertes?analyse=1', $director, MV_TEST_TENANT_HOST, null);
        $this->assertSame(1, $spy->runs, 'contrôle : la route seule la lance');
    }

    public function testAnOperatorEnteredIntoTheHeadIsNeverTheDirector(): void
    {
        $this->group();
        $container = static::getContainer();
        $container->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $guard = $container->get(\App\Services\Group\AllShops\GroupScopeSubscriber::class);
        $tokens = $container->get('security.token_storage');
        $decide = function (?\Symfony\Component\Security\Core\User\UserInterface $user) use ($guard, $tokens): ?int {
            $tokens->setToken($user === null ? null : new \Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken($user, 'main', ['ROLE_ADMIN']));
            $request = \Symfony\Component\HttpFoundation\Request::create('https://' . MV_TEST_TENANT_HOST . '/api/gestion/journee', 'GET', [], [], [], ['HTTP_X_GROUP_SCOPE' => GroupScope::VALUE]);
            $request->attributes->set('_route', 'gestion_journee');
            $event = new \Symfony\Component\HttpKernel\Event\RequestEvent(static::$kernel, $request, \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST);
            $guard->onKernelRequest($event);

            return $event->getResponse()?->getStatusCode() ?? ($request->attributes->has(GroupScope::ATTRIBUTE) ? 200 : null);
        };
        try {
            // un opérateur entré dans le siège (groupe, agence, plateforme) : jamais « toutes les boutiques », même avec le rôle d'administrateur
            $this->assertSame(403, $decide(new \App\Security\Entry\GroupOperator(1, 1, 'Siège', 'Hélène', MV_TEST_TENANT_CODE)));
            $this->assertSame(403, $decide(null));
            // contrôle : un administrateur du siège, avec son compte
            $admin = (new User())->setEmail('g2-temoin@example.invalid')->setUsername('g2-temoin@example.invalid')->setRoles(['ROLE_ADMIN']);
            $this->assertSame(200, $decide($admin));
        } finally {
            $tokens->setToken(null);
        }
    }

    public function testTheTotalsRecomputeRatesFromTheSumsOfEveryShop(): void
    {
        $sums = static fn (int $sales, int $cogs, int $orders): array => ['sales_amount' => $sales, 'returns_amount' => 0, 'unknown_revenue' => 0, 'cogs' => $cogs,
            'orders_sold' => $orders, 'orders_returned' => 0, 'rentals' => 0, 'subscriptions' => 0];
        $part = static fn (string $site, array $current): array => ['site' => $site, 'status' => GroupScope::OK, 'currency' => 'CAD',
            'data' => ['previous' => null, 'range' => ['from' => '2026-11-01', 'to' => '2026-11-18', 'type' => 'month', 'granularity' => 'day'], 'caveats' => []],
            'sums' => ['current' => $current, 'previous' => null, 'lastYear' => null, 'archive' => false,
                'points' => [['key' => '2026-11-18', 'from' => '2026-11-18', 'to' => '2026-11-18'] + $current + ['lastYear' => $sums(0, 0, 0)]],
                'stock' => ['now' => 100, 'cost' => 40, 'previous' => null, 'lastYear' => 7]]];
        $totals = AllShopsMerger::statistics([$part('a', $sums(10000, 4000, 2)), $part('b', $sums(30000, 15000, 3)), ['site' => 'c', 'status' => GroupScope::ERROR]]);
        $this->assertCount(1, $totals);
        $i = $totals[0]['indicators'];
        $this->assertSame([40000, 21000, 52.5, 5, 8000], [$i['revenue']['value'], $i['grossMargin']['value'], $i['grossMarginRate']['value'], $i['orders']['value'], $i['averageBasket']['value']]);
        $this->assertSame([200, 80, 14], [$i['stockValue']['value'], $i['stockValue']['costValue'], $i['stockValue']['lastYear']]);
        $this->assertSame([40000], array_column($totals[0]['chart']['points'], 'revenue'));
        $this->assertSame(['a', 'b'], $totals[0]['shops']);

        // journée : une boutique sans historique (moyenne nulle) ne gonfle pas la comparaison au même jour de semaine
        $day = static fn (string $site, int $total, int $average): array => ['site' => $site, 'status' => GroupScope::OK, 'currency' => 'CAD', 'data' => [
            'sales' => ['total' => $total, 'orders' => 1, 'byChannel' => [], 'vsSameWeekdayAverage' => ['percent' => null, 'average' => $average, 'weeks' => 4, 'asOf' => '10:00']],
            'toShip' => ['count' => 1, 'oldestAt' => $site === 'a' ? '2026-11-10T10:00:00+00:00' : '2026-11-12T10:00:00+00:00'], 'cash' => null, 'stock' => ['lowCount' => 2], 'screens' => []]];
        $merged = AllShopsMerger::day([$day('a', 1000, 500), $day('b', 3000, 0)])[0];
        $this->assertSame([4000, 100.0, 500, ['a']], [$merged['sales']['total'], $merged['sales']['vsSameWeekdayAverage']['percent'], $merged['sales']['vsSameWeekdayAverage']['average'],
            $merged['sales']['vsSameWeekdayAverage']['shops']]);
        $this->assertSame(['2026-11-10T10:00:00+00:00', 2, 4], [$merged['toShip']['oldestAt'], $merged['toShip']['count'], $merged['stock']['lowCount']]);
    }

    public function testG3ListsAreMergedWithTheirShopAndTheToReceiveDepthIsCapped(): void
    {
        $this->group();
        $director = $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'directeur', ['ROLE_ADMIN']);
        $shopAdmin = $this->account(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'boutique', ['ROLE_ADMIN']);
        $alone = [MV_TEST_TENANT_CODE => [$director, MV_TEST_TENANT_HOST], MV_TEST_TENANT2_CODE => [$shopAdmin, MV_TEST_TENANT2_HOST]];

        // à recevoir : au plus 500 lignes lues par boutique
        $this->assertSame([400, GroupScope::PAGE_TOO_DEEP], $this->refusal('GET', '/api/commandes/lignes/a-recevoir?page=3&limit=200', $director));
        $response = $this->request('GET', '/api/commandes/lignes/a-recevoir?page=1&limit=50', $director, MV_TEST_TENANT_HOST, GroupScope::VALUE, null);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $all = json_decode((string) $response->getContent(), true);
        $totals = 0;
        foreach ($all['shops'] as $shop) {
            [$token, $host] = $alone[$shop['site']];
            $own = $this->request('GET', '/api/commandes/lignes/a-recevoir?page=1&limit=1', $token, $host, null, null);
            $this->assertSame((int) $own->headers->get('X-Total-Count'), $shop['total'], $shop['site']);
            $this->assertArrayNotHasKey('data', $shop, 'les lignes ne sont rendues qu\'une fois, réunies');
            $totals += $shop['total'];
        }
        $this->assertSame([$totals, (string) $totals], [$all['total'], $response->headers->get('X-Total-Count')]);
        $this->assertLessThanOrEqual(50, count($all['rows']));
        $dates = array_map(static fn (array $r) => (string) ($r['commande']['date'] ?? ''), $all['rows']);
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates, 'triées par date de commande');
        foreach ($all['rows'] as $row) {
            $this->assertArrayHasKey('site', $row);
        }

        // soldes : le dernier solde de chaque boutique additionné par devise
        $this->pdo(MV_TEST_TENANT2_DB)->exec("DELETE FROM gestion_cash_balance WHERE note = 'g3-{$this->tag}'");
        $this->pdo(MV_TEST_TENANT2_DB)->exec("INSERT INTO gestion_cash_balance (balance_date, amount_cents, note, created_by, created_at, updated_at) VALUES (CURRENT_DATE - 1, 77700, 'g3-{$this->tag}', 'essai', NOW(), NOW())");
        try {
            $balances = $this->json('GET', '/api/gestion/tresorerie/soldes', $director, MV_TEST_TENANT_HOST, GroupScope::VALUE);
            $latest = 0;
            foreach ($balances['shops'] as $shop) {
                $this->assertArrayNotHasKey('data', $shop, 'les soldes ne sont rendus qu\'une fois, dans les totaux');
                [$token, $host] = $alone[$shop['site']];
                $latest += $this->json('GET', '/api/gestion/tresorerie/soldes', $token, $host, null)[0]['amount'] ?? 0;
            }
            $this->assertSame($latest, array_sum(array_column($balances['totals'], 'balance')));
            $this->assertContains(MV_TEST_TENANT2_CODE, array_column(array_merge(...array_column($balances['totals'], 'rows')), 'site'));
            foreach (array_merge(...array_column($balances['totals'], 'rows')) as $row) {
                $this->assertArrayNotHasKey('note', $row, 'texte libre de la boutique : jamais au directeur');
            }
        } finally {
            $this->pdo(MV_TEST_TENANT2_DB)->exec("DELETE FROM gestion_cash_balance WHERE note = 'g3-{$this->tag}'");
        }

        // mouvements : réunis avec leur boutique ; les salaires toujours regroupés (aucun nom), même quand la boutique en saisit un nominatif
        $shopPdo = $this->pdo(MV_TEST_TENANT2_DB);
        $shopPdo->exec("DELETE FROM gestion_cash_movement WHERE notes = 'g3-{$this->tag}'");
        $shopPdo->exec("INSERT INTO gestion_cash_movement (label, category, direction, amount_cents, start_date, recurrence, end_date, notes, fixed_cost, created_by, created_at, updated_at)
            VALUES ('Salaire de Jeanne Témoin', 'salaries', 'out', 250000, CURRENT_DATE, 'monthly', NULL, 'g3-{$this->tag}', TRUE, 'essai', NOW(), NOW())");
        try {
            $movements = $this->json('GET', '/api/gestion/tresorerie/mouvements', $director, MV_TEST_TENANT_HOST, GroupScope::VALUE);
            $this->assertStringNotContainsString('Jeanne', (string) json_encode($movements, JSON_UNESCAPED_UNICODE), 'aucun nom de salarié au directeur');
            $own = $this->json('GET', '/api/gestion/tresorerie/mouvements', $shopAdmin, MV_TEST_TENANT2_HOST, null);
            $this->assertStringContainsString('Jeanne', (string) json_encode($own, JSON_UNESCAPED_UNICODE), 'contrôle : la boutique, elle, voit le détail');
        } finally {
            $shopPdo->exec("DELETE FROM gestion_cash_movement WHERE notes = 'g3-{$this->tag}'");
        }
        $salaries = array_values(array_filter(array_merge(...array_column($movements['totals'], 'rows')), static fn (array $r): bool => ($r['category'] ?? null) === 'salaries'));
        $this->assertNotSame([], $salaries, 'le salaire saisi est compté');
        foreach ($salaries as $row) {
            $this->assertTrue($row['grouped'] ?? false, 'salaires regroupés');
        }

        // l'année : chaque part est la route lue seule ; les mois réunis par mois civil
        $this->assertSame([400, GroupScope::UNSUPPORTED], $this->refusal('GET', '/api/gestion/statistiques/annee?pricePercent=10', $director));
        $this->assertSame([400, GroupScope::UNSUPPORTED], $this->refusal('GET', '/api/gestion/statistiques/annee?year=2025', $director), 'une année n\'existe pas dans tous les exercices');
        $year = $this->json('GET', '/api/gestion/statistiques/annee', $director, MV_TEST_TENANT_HOST, GroupScope::VALUE);
        foreach ($year['shops'] as $shop) {
            [$token, $host] = $alone[$shop['site']];
            $this->assertSame(self::stable($this->json('GET', '/api/gestion/statistiques/annee', $token, $host, null)), self::stable($shop['data']), $shop['site']);
        }
        foreach ($year['totals'] as $total) {
            $parts = array_values(array_filter($year['shops'], static fn (array $s): bool => in_array($s['site'], $total['shops'], true)));
            $this->assertSame(array_sum(array_map(static fn (array $p) => (int) $p['data']['totals']['sales'], $parts)), $total['totals']['sales']);
        }
    }

    public function testG3TheGroupResultOverDatesWritesNothingInAnyShop(): void
    {
        $this->group();
        $director = $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'directeur', ['ROLE_ADMIN']);
        $from = (new \DateTimeImmutable('first day of last month'))->format('Y-m-d');
        $to = (new \DateTimeImmutable('last day of last month'))->format('Y-m-d');
        $response = $this->request('GET', "/api/gestion/groupe/resultat?from=$from&to=$to", $director, MV_TEST_TENANT_HOST, null, null);
        $this->assertSame([400, GroupScope::REQUIRED], [$response->getStatusCode(), json_decode((string) $response->getContent(), true)['code'] ?? null], 'route du groupe seulement');
        $this->assertSame(422, $this->request('GET', "/api/gestion/groupe/resultat?from=$to&to=$from", $director, MV_TEST_TENANT_HOST, GroupScope::VALUE, null)->getStatusCode());
        $this->assertSame(422, $this->request('GET', '/api/gestion/groupe/resultat?from=2026-02-30&to=2026-03-01', $director, MV_TEST_TENANT_HOST, GroupScope::VALUE, null)->getStatusCode());

        // une note de frais qui nomme un salarié, recoupée par un frais fixe : ni son nom ni son texte ne remontent au directeur
        $shopPdo = $this->pdo(MV_TEST_TENANT2_DB);
        $shopPdo->exec("INSERT INTO gestion_cash_movement (label, category, direction, amount_cents, start_date, recurrence, end_date, notes, fixed_cost, created_by, created_at, updated_at)
            VALUES ('Loyer', 'rent', 'out', 100000, '$from', 'monthly', NULL, 'g3-{$this->tag}', TRUE, 'essai', NOW(), NOW())");
        $shopPdo->exec("INSERT INTO note_de_frais (id, name, description, montant, date) VALUES (nextval('note_de_frais_id_seq'), 'Salaire Marie Témoin', 'g3-{$this->tag}', 5000, '$from')");
        $count = fn (string $db): array => array_map(fn (string $t): int => (int) $this->pdo($db)->query("SELECT COUNT(*) FROM $t")->fetchColumn(), ['collections' => 'collections', 'period_result' => 'period_result', 'gestion_stock_snapshot' => 'gestion_stock_snapshot']);
        try {
            $before = [$count(MV_TEST_TENANT_DB), $count(MV_TEST_TENANT2_DB)];
            $all = $this->json('GET', "/api/gestion/groupe/resultat?from=$from&to=$to", $director, MV_TEST_TENANT_HOST, GroupScope::VALUE);
            $this->json('GET', '/api/gestion/statistiques/annee', $director, MV_TEST_TENANT_HOST, GroupScope::VALUE);
            $this->assertSame($before, [$count(MV_TEST_TENANT_DB), $count(MV_TEST_TENANT2_DB)], 'aucune période, aucun résultat figé, aucun instantané');
        } finally {
            $shopPdo->exec("DELETE FROM gestion_cash_movement WHERE notes = 'g3-{$this->tag}'");
            $shopPdo->exec("DELETE FROM note_de_frais WHERE description = 'g3-{$this->tag}'");
        }
        $this->assertSame([GroupScope::OK, GroupScope::OK], array_column($all['shops'], 'status'));
        $this->assertStringNotContainsString('Marie', (string) json_encode($all, JSON_UNESCAPED_UNICODE), 'aucun nom de note de frais au directeur');
        $this->assertContains(['code' => 'FIXED_COSTS_MAY_OVERLAP_NOTES', 'text' => null, 'count' => 1], $all['shops'][1]['data']['fixedCosts']['caveats']);
        foreach ($all['shops'] as $shop) {
            $this->assertSame([$from, $to, null], [$shop['data']['period']['start'], $shop['data']['period']['end'], $shop['data']['period']['id']]);
        }
        foreach ($all['totals'] as $total) {
            $parts = array_values(array_filter($all['shops'], static fn (array $s): bool => in_array($s['site'], $total['shops'], true)));
            $this->assertSame(array_sum(array_map(static fn (array $p) => $p['data']['sales']['revenue'], $parts)), $total['sales']['revenue']);
            $this->assertSame(array_sum(array_map(static fn (array $p) => $p['data']['result']['net'], $parts)), $total['result']['net']);
        }
    }

    public function testG3MergesAddEveryShopAndKeepTheOrder(): void
    {
        $ok = static fn (string $site, mixed $data, string $currency = 'CAD'): array => ['site' => $site, 'status' => GroupScope::OK, 'currency' => $currency, 'data' => $data];
        // à recevoir : totaux additionnés, lignes retriées par date de commande sur toutes les boutiques, puis la page
        $line = static fn (int $id, string $date): array => ['id' => $id, 'commande' => ['date' => $date]];
        $merged = \App\Services\Group\AllShops\AllShopsListMerger::toReceive([
            $ok('a', ['rows' => [$line(1, '2026-11-03 10:00:00'), $line(2, '2026-11-05 10:00:00')], 'total' => 7]),
            $ok('b', ['rows' => [$line(9, '2026-11-01 10:00:00'), $line(8, '2026-11-04 10:00:00')], 'total' => 5]),
            ['site' => 'c', 'status' => GroupScope::ERROR],
        ], 1, 3);
        $this->assertSame([12, [['b', 9], ['a', 1], ['b', 8]]], [$merged['total'], array_map(static fn (array $r): array => [$r['site'], $r['id']], $merged['rows'])]);
        $usd = \App\Services\Group\AllShops\AllShopsListMerger::toReceive([$ok('a', ['rows' => [['id' => 1, 'currency' => 'USD', 'commande' => ['date' => '2026-11-01']]], 'total' => 1], 'EUR')], 1, 10)['rows'][0];
        $this->assertSame(['USD', 'EUR'], [$usd['currency'], $usd['siteCurrency']], 'la devise de la commande n\'est jamais remplacée par celle de la boutique');
        // soldes : le dernier solde de chaque boutique additionné
        $balance = static fn (int $id, string $date, int $amount): array => ['id' => $id, 'date' => $date, 'amount' => $amount];
        $b = \App\Services\Group\AllShops\AllShopsListMerger::balances([$ok('a', [$balance(1, '2026-11-10', 1000), $balance(2, '2026-11-01', 50)]), $ok('b', [$balance(3, '2026-11-12', 300)])], 10)[0];
        $this->assertSame([1300, 2, '2026-11-10', [3, 1, 2]], [$b['balance'], $b['balanceShops'], $b['oldestBalanceDate'], array_column($b['rows'], 'id')]);
        // année : mois réunis par mois civil, ventes additionnées, taux de marque recalculé, cumul
        $month = static fn (int $y, int $m, ?int $sales, ?int $gross, int $unknown = 0): array => ['year' => $y, 'month' => $m, 'from' => sprintf('%d-%02d-01', $y, $m), 'to' => sprintf('%d-%02d-28', $y, $m),
            'elapsed' => $sales !== null, 'sales' => $sales, 'grossMargin' => $gross, 'unknownCostRevenue' => $sales === null ? null : $unknown, 'otherRevenue' => ['rentals' => 0, 'subscriptions' => 0, 'total' => 0],
            'variableCosts' => ['total' => 0], 'fixedCosts' => 0, 'resultBeforeOtherRevenue' => $gross, 'result' => $gross];
        $year = static fn (int $start, array $months): array => ['fiscalYear' => ['startMonth' => $start], 'months' => $months,
            'totals' => ['from' => '2026-01-01', 'to' => '2026-11-18'] + array_diff_key($month(2026, 1, (int) array_sum(array_column($months, 'sales')), (int) array_sum(array_column($months, 'grossMargin'))), ['year' => 1, 'month' => 1, 'from' => 1, 'to' => 1, 'elapsed' => 1])];
        $y = \App\Services\Group\AllShops\AllShopsListMerger::year([$ok('a', $year(1, [$month(2026, 10, 1000, 400), $month(2026, 11, 2000, 800, 1000)])), $ok('b', $year(4, [$month(2026, 11, 3000, 900), $month(2026, 12, null, null)]))])[0];
        $this->assertSame(['2026-10', '2026-11', '2026-12'], array_column($y['months'], 'key'));
        $this->assertSame([5000, 1700, 42.5], [$y['months'][1]['sales'], $y['months'][1]['grossMargin'], $y['months'][1]['grossMarginRate']]);
        $this->assertSame([['a'], ['a', 'b'], ['b']], array_column($y['months'], 'shops'), 'chaque mois dit quelles boutiques il couvre');
        $this->assertSame([6000, 2100], [$y['totals']['sales'], $y['totals']['grossMargin']]);
        $this->assertSame([1000, 6000, null], array_column($y['cumulative'], 'sales'));
        $this->assertSame('FISCAL_YEARS_DIFFER', $y['caveats'][0]['code']);
    }

    /** Champs datés de l'instant de la lecture : hors de la comparaison */
    private static function stable(array $data): array
    {
        unset($data['generatedAt'], $data['today']);
        if (isset($data['sales']['vsSameWeekdayAverage'])) {
            unset($data['sales']['vsSameWeekdayAverage']['asOf'], $data['receipts']['outstandingStoreCredit']['asOf'], $data['receipts']['outstandingGiftCards']['asOf']);
        }

        return $data;
    }

    private function analysisMark(string $db): string
    {
        return (string) json_encode($this->pdo($db)->query("SELECT COALESCE(MAX(id), 0) FROM loss_finding")->fetchColumn())
            . '/' . (string) json_encode($this->pdo($db)->query('SELECT cursor_sequence, last_analysis_at FROM loss_setting')->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function group(bool $withShop = true): int
    {
        $this->module(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'is_multi_shop_active', true);
        $id = (int) $this->master->query("INSERT INTO shop_groups (code, name, head_tenant_code) VALUES ('groupe-g2-{$this->tag}', 'Siège', '" . MV_TEST_TENANT_CODE . "') RETURNING id")->fetchColumn();
        $this->master->exec("INSERT INTO shop_group_sites (group_id, tenant_code, origin) VALUES ($id, '" . MV_TEST_TENANT_CODE . "', 'head')");
        if ($withShop) {
            $this->master->exec("INSERT INTO shop_group_sites (group_id, tenant_code, origin) VALUES ($id, '" . MV_TEST_TENANT2_CODE . "', 'associated')");
        }

        return $id;
    }

    private function module(string $db, string $code, string $column, bool $on): void
    {
        $this->pdo($db)->exec("UPDATE entreprise SET $column = " . ($on ? 'TRUE' : 'FALSE'));
        static::getContainer()->get(TenantCacheService::class)->invalidateSite($code, $db);
    }

    /** @return array{0: int, 1: ?string} */
    private function refusal(string $method, string $path, string $token, string $host = MV_TEST_TENANT_HOST, string $scope = GroupScope::VALUE): array
    {
        $response = $this->request($method, $path, $token, $host, $scope, $method === 'GET' ? null : []);

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)['code'] ?? null];
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $path, string $token, string $host, ?string $scope, ?array $body = null): array
    {
        $response = $this->request($method, $path, $token, $host, $scope, $body);
        $this->assertSame(200, $response->getStatusCode(), "$method $path : " . substr((string) $response->getContent(), 0, 500));

        return json_decode((string) $response->getContent(), true);
    }

    private function request(string $method, string $path, string $token, string $host, ?string $scope, ?array $body): Response
    {
        $this->client->getCookieJar()->clear();
        $server = ['HTTP_X_TENANT_HOST' => $host, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'REMOTE_ADDR' => '10.93.' . random_int(1, 254) . '.' . random_int(1, 254)] + ($scope !== null ? ['HTTP_X_GROUP_SCOPE' => $scope] : []);
        $this->client->request($method, 'https://' . $host . $path, [], [], $server, $body !== null ? (string) json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function account(string $db, string $code, string $name, array $roles): string
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $em = $provider->getEntityManager();
        $email = "g2-$name-{$this->tag}@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Prénom')->setLastname('Essai')->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();
        $em->getConnection()->executeStatement("INSERT INTO refresh_tokens (id, refresh_token, username, valid) VALUES (nextval('refresh_tokens_id_seq'), ?, ?, NOW() + INTERVAL '1 day')", ['g2-' . bin2hex(random_bytes(30)), $email]);
        $token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $em->clear();
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $token;
    }

    private function clean(): void
    {
        $this->master->exec("DELETE FROM site_entries WHERE mode = 'group'");
        foreach (['shop_group_events', 'shop_group_invitations', 'shop_group_sites', 'shop_groups'] as $table) {
            $this->master->exec("DELETE FROM $table");
        }
        $this->master->exec("DELETE FROM tenants WHERE code LIKE 'g2-casse-%' OR code LIKE 'g2-alias-%'");
        foreach ([[MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE], [MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE]] as [$db, $code]) {
            $this->pdo($db)->exec("DELETE FROM refresh_tokens WHERE username LIKE 'g2-%@example.invalid'");
            $this->pdo($db)->exec("DELETE FROM \"user\" WHERE email LIKE 'g2-%@example.invalid'");
            $this->pdo($db)->exec("DELETE FROM content_audit_log WHERE resource = 'external_access'");
            $this->pdo($db)->exec('UPDATE entreprise SET is_multi_shop_active = FALSE');
            static::getContainer()->get(TenantCacheService::class)->invalidateSite($code, $db);
        }
    }

    private function pdo(string $db): \PDO
    {
        return static::getContainer()->get(TenantDatabaseConnector::class)->pdo($db);
    }
}
