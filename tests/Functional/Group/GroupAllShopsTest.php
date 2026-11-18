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
        static::getContainer()->get(PlatformModeService::class)->setMode('demo', 'test');
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
        $this->assertSame([400, GroupScope::UNSUPPORTED], $this->refusal('GET', '/api/gestion/statistiques/annee', $director), 'une route qui ne sait pas réunir');
        $this->assertSame([400, GroupScope::UNSUPPORTED], $this->refusal('GET', '/api/gestion/tresorerie/soldes', $director));
        $this->assertSame([400, GroupScope::UNSUPPORTED], $this->refusal('GET', '/api/contacts', $director));
        $this->assertSame([400, GroupScope::UNSUPPORTED], $this->refusal('GET', '/api/gestion/statistiques?sections=payments', $director), 'une section');
        $this->assertSame([400, GroupScope::UNSUPPORTED], $this->refusal('GET', '/api/gestion/statistiques?periodId=1', $director), 'une période est propre à un site');
        // l'administrateur d'une boutique du groupe, chez lui : pas le directeur
        $this->assertSame([403, GroupScope::FORBIDDEN], $this->refusal('GET', '/api/gestion/journee', $shopAdmin, MV_TEST_TENANT2_HOST));
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
        $this->assertSame(['/api/gestion/statistiques', '/api/gestion/journee', '/api/gestion/pertes'], $contexts['group']['allShops']);
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
        $this->master->exec("INSERT INTO shop_group_sites (group_id, tenant_code, origin) VALUES ($groupId, '$broken', 'associated'), ($groupId, '" . MV_TEST_TENANT2_CODE . "', 'associated')");
        $director = $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'directeur', ['ROLE_ADMIN']);
        $baseline = $this->json('GET', '/api/gestion/journee', $director, MV_TEST_TENANT_HOST, null);

        $all = $this->json('GET', '/api/gestion/journee', $director, MV_TEST_TENANT_HOST, GroupScope::VALUE);
        $this->assertSame([[MV_TEST_TENANT_CODE, 'ok'], [$broken, 'error'], [MV_TEST_TENANT2_CODE, 'ok']], array_map(static fn (array $s): array => [$s['site'], $s['status']], $all['shops']));
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
        $this->group();
        $director = $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'directeur', ['ROLE_ADMIN']);
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->module(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'is_management_app_active', true);
        $this->module(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'is_management_app_active', false);
        try {
            $all = $this->json('GET', '/api/gestion/journee', $director, MV_TEST_TENANT_HOST, GroupScope::VALUE);
            $this->assertSame([GroupScope::OK, GroupScope::MODULE_OFF], array_column($all['shops'], 'status'));
            $this->assertArrayNotHasKey('data', $all['shops'][1]);
            $this->assertSame([[MV_TEST_TENANT_CODE]], array_column($all['totals'], 'shops'), 'les totaux ne portent que les boutiques lues');
        } finally {
            $this->module(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'is_management_app_active', true);
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

        $read = $reader(10_000, 200)->read($group, 'gestion_pertes', '/api/gestion/pertes', [], $slow);
        $this->assertSame([GroupScope::TIMEOUT, GroupScope::TIMEOUT], array_column($read['shops'], 'status'), 'au-delà du délai d\'une boutique');
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
        // une écriture tentée pendant une lecture de boutique échoue : jamais rien d'écrit
        $write = fn (): array => ['data' => ['n' => $provider->getEntityManager()->getConnection()->executeStatement("UPDATE entreprise SET name = name || ' g2'")]];
        $read = $reader(10_000, 3000)->read($group, 'gestion_pertes', '/api/gestion/pertes', [], $write);
        $this->assertSame([GroupScope::ERROR, GroupScope::ERROR], array_column($read['shops'], 'status'));
        $this->assertStringEndsNotWith(' g2', (string) $this->pdo(MV_TEST_TENANT2_DB)->query('SELECT name FROM entreprise ORDER BY id LIMIT 1')->fetchColumn());
        $this->assertSame(MV_TEST_TENANT_DB, $provider->getEntityManager()->getConnection()->getParams()['dbname']);
    }

    /** Champs datés de l'instant de la lecture : hors de la comparaison */
    private static function stable(array $data): array
    {
        unset($data['generatedAt']);
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
        $this->master->exec("DELETE FROM tenants WHERE code LIKE 'g2-casse-%'");
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
