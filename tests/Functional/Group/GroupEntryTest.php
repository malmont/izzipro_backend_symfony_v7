<?php

namespace App\Tests\Functional\Group;

use App\Entity\User;
use App\Security\Entry\EntryModeGuardSubscriber;
use App\Services\Group\GroupService;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot G-1b (18/11/2026, contrat validé par le chef) : le DIRECTEUR du groupe (un administrateur du siège) entre dans une boutique du groupe.
 * - Il y a tout le site, sauf Stripe, les remboursements, les gestes de module et le compte ; chaque écriture est tracée « Groupe X, prénom »
 *   et lisible par la boutique (« Accès extérieurs »).
 * - La boutique qui quitte le groupe ferme ses entrées tout de suite : la requête suivante reçoit 401 `ENTRY_ENDED`.
 * - Aucune route lue par le directeur ne répond par une panne (sonde de toutes les lectures sans paramètre).
 *
 * Le premier site de test est le siège ; le second, la boutique.
 */
class GroupEntryTest extends WebTestCase
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

    public function testTheDirectorEntersAShopOfTheGroupWithEverythingButPaymentRefundsAndTheAccount(): void
    {
        $director = $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'directeur', ['ROLE_ADMIN'], 'Hélène');
        $this->assertSame([403, 'ENTRY_FORBIDDEN'], $this->enterRefusal($director, MV_TEST_TENANT2_CODE), 'sans groupe');
        $this->group();
        $this->assertSame([403, 'ENTRY_FORBIDDEN'], $this->enterRefusal($this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'employe', ['ROLE_USER_POS'], 'Léo'), MV_TEST_TENANT2_CODE), 'un compte sans rôle d\'administrateur');
        $this->assertSame([403, 'ENTRY_FORBIDDEN'], $this->enterRefusal($director, 'site-inconnu-' . $this->tag));

        $contexts = json_decode((string) $this->request('GET', '/api/me/contexts', $director, MV_TEST_TENANT_HOST)->getContent(), true);
        $this->assertSame(['group', 'head', [true, false]], [$contexts['mode'], $contexts['group']['role'], array_map(static fn (array $s): bool => $s['home'], $contexts['sites'])]);
        $this->assertSame(MV_TEST_TENANT2_CODE, $contexts['sites'][1]['code']);

        $entered = $this->enter($director, MV_TEST_TENANT2_CODE);
        $this->assertSame(['group', 'Groupe Siège, Hélène'], [$entered['mode'], $entered['operator']]);
        $row = $this->master->query('SELECT mode, group_id, agency_id, agency_name FROM site_entries WHERE id = ' . (int) $entered['entry'])->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['group', true, null, 'Siège'], [$row['mode'], (int) $row['group_id'] > 0, $row['agency_id'], $row['agency_name']]);
        $token = (string) $entered['token'];

        // tout le site : une lecture d'administrateur, une écriture (tracée « Groupe Siège, Hélène »)
        $this->assertSame(200, $this->request('GET', '/api/contacts', $token)->getStatusCode());
        $brand = $this->request('POST', '/api/categories-marque', $token, MV_TEST_TENANT2_HOST, ['nom' => 'Marque g1b ' . $this->tag]);
        $this->assertSame(201, $brand->getStatusCode(), (string) $brand->getContent());
        $traced = $this->pdo(MV_TEST_TENANT2_DB)->query("SELECT user_identifier, after_state FROM content_audit_log WHERE resource = 'external_access' AND action = 'write' ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Groupe Siège, Hélène', $traced['user_identifier']);
        $this->assertSame(['POST', '/api/categories-marque', 'group'], [json_decode((string) $traced['after_state'], true)['method'], json_decode((string) $traced['after_state'], true)['path'], json_decode((string) $traced['after_state'], true)['mode']]);
        $lines = $this->journalOn(MV_TEST_TENANT2_CODE, MV_TEST_TENANT2_DB);
        $this->assertContains(['Groupe Siège, Hélène', 'entry_group'], $lines, 'l\'entrée, lue par « Accès extérieurs »');
        $this->assertContains(['Groupe Siège, Hélène', 'group_write'], $lines, 'l\'écriture, lue par « Accès extérieurs »');

        // fermés : Stripe, remboursements, modules, compte ; le groupe lui-même ; la vérification des taxes (geste du commerçant)
        foreach ([['GET', '/api/pos/terminal/payments', 'payment'], ['POST', '/api/pos/returns', 'refund'], ['POST', '/api/pos/terminal/payments/1/refund', 'payment'],
            ['POST', '/api/order/cancel/1', 'refund'], ['POST', '/api/site/features/module.boutique/disable', 'modules'], ['GET', '/api/profile', 'account'],
            ['POST', '/api/me/contexts/enter', 'account'],
            // relecture neuve : un identifiant mal formé ne passe plus à côté de la règle ; les comptes et les cartes-cadeaux
            ['POST', '/api/order/cancel/5', 'refund'], ['POST', '/api/support/agents', 'accounts'],
            ['POST', '/api/pos/gift-cards/ABC/cash-out', 'refund'],
            // relecture neuve, passe 2 : l'assistant IA du site, les clés des applications, le domaine personnalisé
            ['POST', '/api/site/assistant', 'assistant'], ['POST', '/api/site/assistant/actions/abcdef0123456789/confirm', 'assistant'], ['GET', '/api/app-keys', 'security'],
            ['POST', '/api/site/domain', 'security'],
            // décisions du chef : IA fermée (unités de la boutique), import des clients finaux fermé
            ['POST', '/api/gestion/console/messages', 'ai'], ['POST', '/api/gestion/conversations/1/messages', 'ai'], ['POST', '/api/products/ai/sheet', 'ai'],
            ['POST', '/api/site/imports', 'customers'], ['POST', '/api/site/imports/1/invitations', 'customers']] as [$method, $path, $reason]) {
            $this->assertSame([403, $reason], $this->reason($method, $path, $token), "$method $path");
        }
        // formes déformées d'une route d'argent : jamais servies (refus de la garde ou route introuvable)
        foreach (['/api/order/cancel/5%20', '/api/order/cancel/+5', '/api/order/cancel/5.', '//api/order/cancel/5', '/api/order/cancel//5', '/api/order/cancel/5%2F', '/api/order//cancel/5'] as $odd) {
            $this->assertContains($this->request('POST', $odd, $token, MV_TEST_TENANT2_HOST, [])->getStatusCode(), [403, 404, 405], $odd);
        }
        $this->assertSame([403, 'GROUP_FORBIDDEN'], $this->reason('POST', '/api/group/leave', $token, null, 'code'), 'le directeur ne retire pas la boutique depuis la boutique');
        // la fiche du site : ni l'adresse qui reçoit les commandes, ni la devise ; le reste s'écrit
        $sheet = (int) $this->pdo(MV_TEST_TENANT2_DB)->query('SELECT id FROM entreprise ORDER BY id LIMIT 1')->fetchColumn();
        $this->assertSame([403, 'rights'], $this->reason('PUT', "/api/entreprise/$sheet", $token, ['email' => 'detourne@example.invalid']));
        $this->assertSame([403, 'payment'], $this->reason('PUT', "/api/entreprise/$sheet", $token, ['currency' => 'EUR']));
        // le point unique des unités IA refuse le directeur, quel que soit l'outil
        static::getContainer()->get('security.token_storage')->setToken(new \Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken(
            new \App\Security\Entry\GroupOperator(1, 1, 'Siège', 'Hélène', MV_TEST_TENANT_CODE), 'main', ['ROLE_ADMIN']));
        try {
            static::getContainer()->get(\App\Services\LandingAiService\LandingAiQuotaService::class)->reserve('assistant', 'essai', 1, 'Groupe Siège, Hélène', 'essai');
            $this->fail('une réservation d\'unités par le directeur doit être refusée');
        } catch (\App\Services\LandingAiService\LandingAiException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            static::getContainer()->get('security.token_storage')->setToken(null);
        }
        $this->assertSame([403, 'TAXES_VERIFY_FORBIDDEN'], $this->reason('POST', '/api/site/taxes/verify', $token, null, 'code'));

        // la boutique quitte le groupe : la requête suivante du directeur est refusée
        $shopAdmin = $this->account(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'boutique', ['ROLE_ADMIN'], 'Marc');
        $this->assertSame(200, $this->request('POST', '/api/group/leave', $shopAdmin)->getStatusCode());
        $ended = $this->request('GET', '/api/contacts', $token);
        $this->assertSame([401, 'ENTRY_ENDED'], [$ended->getStatusCode(), json_decode((string) $ended->getContent(), true)['error'] ?? null]);
        $this->assertSame('left', $this->master->query('SELECT ended_reason FROM site_entries WHERE id = ' . (int) $entered['entry'])->fetchColumn());
        $this->assertSame([403, 'ENTRY_FORBIDDEN'], $this->enterRefusal($director, MV_TEST_TENANT2_CODE), 'plus dans le groupe : plus d\'entrée');
    }

    public function testTheEntryEndsWhenTheModuleOrTheRoleOfTheDirectorGoes(): void
    {
        $director = $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'directeur', ['ROLE_ADMIN'], 'Hélène');
        $this->group();
        $entered = $this->enter($director, MV_TEST_TENANT2_CODE);
        $this->assertSame(200, $this->request('GET', '/api/contacts', (string) $entered['token'])->getStatusCode());
        $this->pdo(MV_TEST_TENANT_DB)->exec('UPDATE entreprise SET is_multi_shop_active = FALSE');
        static::getContainer()->get('cache.app')->delete('site_entry_' . (int) $entered['entry']);
        $this->assertSame(401, $this->request('GET', '/api/contacts', (string) $entered['token'])->getStatusCode(), 'module éteint au siège');
        $this->assertSame('module', $this->master->query('SELECT ended_reason FROM site_entries WHERE id = ' . (int) $entered['entry'])->fetchColumn());

        $this->module(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, true);
        $again = $this->enter($director, MV_TEST_TENANT2_CODE);
        $this->pdo(MV_TEST_TENANT_DB)->exec("UPDATE \"user\" SET roles = '[\"ROLE_USER_POS\"]' WHERE email = 'g1b-directeur-{$this->tag}@example.invalid'");
        static::getContainer()->get('cache.app')->delete('site_entry_' . (int) $again['entry']);
        $this->assertSame(401, $this->request('GET', '/api/contacts', (string) $again['token'])->getStatusCode(), 'plus administrateur du siège');
        $this->assertSame('role', $this->master->query('SELECT ended_reason FROM site_entries WHERE id = ' . (int) $again['entry'])->fetchColumn());
    }

    public function testTheDirectorEntersFromEasyAdminSeesEveryScreenAndNotTheRefund(): void
    {
        $this->group();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $email = "g1b-ea-{$this->tag}@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Hélène')->setLastname('Essai')->setRoles(['ROLE_ADMIN'])->setPassword('inutilisable')->setIsVerified(true)->setOtpEnabled(true);
        $em->persist($user);
        $em->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        \App\Tests\Functional\Support\OtpTestHelper::validateIfRequired($this->client, $user, MV_TEST_TENANT_HOST, MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        $this->assertStringContainsString('Les boutiques du groupe', $this->page('GET', '/admin', MV_TEST_TENANT_HOST));
        $selector = $this->page('GET', '/admin/contextes', MV_TEST_TENANT_HOST);
        $this->assertStringContainsString(MV_TEST_TENANT2_CODE, $selector);
        $csrf = (string) (new \Symfony\Component\DomCrawler\Crawler($selector))->filter('form[action="/admin/contextes/entrer"] input[name="_token"]')->attr('value');
        $this->page('POST', '/admin/contextes/entrer', MV_TEST_TENANT_HOST, ['_token' => $csrf, 'site' => MV_TEST_TENANT2_CODE]);
        $pass = (string) $this->client->getCrawler()->filter('form#entree input[name="pass"]')->attr('value');
        $this->assertSame('group', $this->master->query('SELECT mode FROM site_entries ORDER BY id DESC LIMIT 1')->fetchColumn());
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree?tenant=' . MV_TEST_TENANT2_CODE, ['pass' => $pass], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), mb_substr((string) $this->client->getResponse()->getContent(), 0, 400));

        $welcome = $this->page('GET', '/admin', MV_TEST_TENANT2_HOST);
        $this->assertStringNotContainsString('Les boutiques du groupe', $welcome, 'pas de sélecteur pendant une entrée');
        // chaque écran de la boutique s'ouvre au directeur, sans panne
        $failures = [];
        foreach ($this->siteScreens() as $controller) {
            try {
                $this->client->request('GET', 'https://' . MV_TEST_TENANT2_HOST . '/admin?' . http_build_query(['crudAction' => 'index', 'crudControllerFqcn' => $controller]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
                $status = $this->client->getResponse()->getStatusCode();
            } catch (\Throwable $e) {
                $status = 500;
            }
            if ($status >= 500) {
                $failures[] = "$status $controller";
            }
        }
        $this->assertSame([], $failures, 'un écran d\'EasyAdmin ne tombe jamais en panne pour le directeur');
        // relecture neuve : fermés en page HTML, les comptes, Mémoires Vivantes, les gestes de module, un abonnement, une action propre à un écran
        foreach (['GET /admin?crudAction=index&crudControllerFqcn=' . rawurlencode('App\\Controller\\Admin\\UserCrudController'),
            'GET /admin?crudAction=new&crudControllerFqcn=' . rawurlencode('App\\Controller\\Admin\\UserCrudController'),
            'GET /admin?crudAction=index&crudControllerFqcn=' . rawurlencode('App\\Controller\\Admin\\BookCrudController'),
            'GET /admin?crudAction=refundLatest&entityId=1&crudControllerFqcn=' . rawurlencode('App\\Controller\\Admin\\SubscriptionCrudController'),
            'GET /admin?crudAction=uneActionPropre&entityId=1&crudControllerFqcn=' . rawurlencode('App\\Controller\\Admin\\ProductCrudController'),
            'GET /admin/memoires/ai-quotas', 'POST /admin/mon-site/module/module.boutique/off',
            'GET /admin?crudAction=edit&entityId=1&crudControllerFqcn=' . rawurlencode('App\\Controller\\Admin\\CashRegisterCrudController'),
            'GET /admin?crudAction=new&crudControllerFqcn=' . rawurlencode('App\\Controller\\Admin\\CashRegisterCrudController'),
            'POST /admin/mon-site/assistant', 'POST /admin/mon-site/domaine'] as $line) {
            [$method, $url] = explode(' ', $line, 2);
            $this->client->request($method, 'https://' . MV_TEST_TENANT2_HOST . $url, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), $line);
        }
        // le remboursement d'une commande : refusé, en page HTML
        $this->client->request('GET', 'https://' . MV_TEST_TENANT2_HOST . '/admin/orders/1/rembourser', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        // quitter ferme l'entrée
        $this->client->request('GET', 'https://' . MV_TEST_TENANT2_HOST . '/admin', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $quit = (new \Symfony\Component\DomCrawler\Crawler((string) $this->client->getResponse()->getContent()))->filter('form[action="/admin/entree/quitter"] input[name="_token"]');
        $this->assertSame(1, $quit->count(), 'le bandeau de l\'entrée porte « Quitter »');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/admin/entree/quitter', ['_token' => (string) $quit->attr('value')], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST]);
        $this->assertSame('left', $this->master->query('SELECT ended_reason FROM site_entries ORDER BY id DESC LIMIT 1')->fetchColumn());
        // lot RETOUR : le directeur revient au siège, toujours connecté (même domaine, comme en production)
        $this->assertSame('/admin?tenant=' . MV_TEST_TENANT_CODE, $this->client->getResponse()->headers->get('Location'));
        $this->client->request('GET', 'https://' . MV_TEST_TENANT2_HOST . '/admin?tenant=' . MV_TEST_TENANT_CODE);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Les boutiques du groupe', (string) $this->client->getResponse()->getContent(), 'de retour au siège, avec son sélecteur');
    }

    /** @return list<class-string> les écrans CRUD d'un site */
    private function siteScreens(): array
    {
        $root = dirname(__DIR__, 3) . '/src/';
        $classes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . 'Controller/Admin', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = 'App\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($root), -4));
            if (class_exists($class) && is_subclass_of($class, \App\Controller\Admin\BaseTenantCrudController::class) && !(new \ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }

    private function page(string $method, string $path, string $host, array $body = []): string
    {
        $this->client->request($method, 'https://' . $host . $path, $body, [], ['HTTP_X_TENANT_HOST' => $host]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $method . ' ' . $path . ' : ' . mb_substr((string) $this->client->getResponse()->getContent(), 0, 400));

        return (string) $this->client->getResponse()->getContent();
    }

    public function testNoReadOfTheDirectorEndsInAFailure(): void
    {
        $director = $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'directeur', ['ROLE_ADMIN'], 'Hélène');
        $this->group();
        $token = (string) $this->enter($director, MV_TEST_TENANT2_CODE)['token'];
        $failures = [];
        $count = 0;
        foreach (static::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            $path = $route->getPath();
            if (str_contains($path, '{') || !str_starts_with($path, '/api/') || ($route->getMethods() !== [] && !in_array('GET', $route->getMethods(), true))
                || EntryModeGuardSubscriber::groupDecision('GET', $path) !== null) {
                continue;
            }
            $count++;
            try {
                $status = $this->request('GET', $path, $token)->getStatusCode();
            } catch (\Throwable $e) {
                $status = 500;
                $path .= ' : ' . $e::class . ' ' . mb_substr($e->getMessage(), 0, 160);
            }
            if ($status >= 500) {
                $failures[] = "$status GET $path ($name)";
            }
        }
        $this->assertGreaterThan(100, $count);
        $this->assertSame([], $failures, 'une lecture du directeur ne tombe jamais en panne');
    }

    public function testTheClosedRoutesOfTheDirectorAreWrittenOutHere(): void
    {
        $closed = [];
        foreach (static::getContainer()->get('router')->getRouteCollection() as $route) {
            $path = preg_replace('/\.1$/', '', (string) preg_replace('/\{[^}]+\}/', '1', $route->getPath()));
            foreach ($route->getMethods() ?: ['GET', 'POST'] as $method) {
                $reason = EntryModeGuardSubscriber::groupDecision($method, $path);
                if ($reason !== null && $reason !== 'account') {
                    $closed[] = "$reason $method $path";
                }
            }
        }
        $closed = array_values(array_unique($closed));
        sort($closed);
        if (is_string(getenv('REGENERER_GROUPE')) && getenv('REGENERER_GROUPE') !== '') {
            file_put_contents(getenv('REGENERER_GROUPE'), json_encode($closed, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");
            $this->markTestIncomplete('Liste écrite dans ' . getenv('REGENERER_GROUPE') . ' : la relire, puis la copier dans tests/Fixtures/ecrans/entree-groupe-fermees.json');
        }
        $this->assertSame(json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/ecrans/entree-groupe-fermees.json'), true), $closed,
            'routes fermées au directeur (hors compte) : tests/Fixtures/ecrans/entree-groupe-fermees.json, à régénérer et RELIRE');
        // la décision se prend sur le chemin décodé, casse comprise
        $this->assertSame('payment', EntryModeGuardSubscriber::groupDecision('POST', '/api/Stripe/create-intent'));
        $this->assertSame('refund', EntryModeGuardSubscriber::groupDecision('GET', '/admin/orders/12/rembourser'));
        $this->assertSame('refund', EntryModeGuardSubscriber::groupDecision('POST', '/api/pos/returns/lookup'), 'une écriture sous les retours de la caisse');
        $this->assertNull(EntryModeGuardSubscriber::groupDecision('GET', '/api/pos/returns/lookup'), 'la recherche d\'une vente à rendre se lit');
        $this->assertSame('refund', EntryModeGuardSubscriber::groupDecision('POST', '/api/order/cancel/5 '));
        $this->assertSame('accounts', EntryModeGuardSubscriber::groupDecision('GET', '/admin', 'admin.utilisateurs'));
        $this->assertSame('security', EntryModeGuardSubscriber::groupDecision('GET', '/admin', 'admin.courriel'));
    }

    /** Le groupe : siège = premier site de test, boutique = second, module actif au siège */
    private function group(): void
    {
        $this->module(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, true);
        $id = (int) $this->master->query("INSERT INTO shop_groups (code, name, head_tenant_code) VALUES ('groupe-g1b-{$this->tag}', 'Siège', '" . MV_TEST_TENANT_CODE . "') RETURNING id")->fetchColumn();
        $this->master->exec("INSERT INTO shop_group_sites (group_id, tenant_code, origin) VALUES ($id, '" . MV_TEST_TENANT_CODE . "', 'head'), ($id, '" . MV_TEST_TENANT2_CODE . "', 'associated')");
    }

    private function module(string $db, string $code, bool $on): void
    {
        $this->pdo($db)->exec('UPDATE entreprise SET is_multi_shop_active = ' . ($on ? 'TRUE' : 'FALSE'));
        static::getContainer()->get(TenantCacheService::class)->invalidateSite($code, $db);
    }

    /** @return array<string, mixed> */
    private function enter(string $token, string $site): array
    {
        $response = $this->request('POST', '/api/me/contexts/enter', $token, MV_TEST_TENANT_HOST, ['site' => $site]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true);
    }

    /** @return array{0: int, 1: ?string} */
    private function enterRefusal(string $token, string $site): array
    {
        $response = $this->request('POST', '/api/me/contexts/enter', $token, MV_TEST_TENANT_HOST, ['site' => $site]);

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)['code'] ?? null];
    }

    /** @return array{0: int, 1: ?string} */
    private function reason(string $method, string $path, string $token, ?array $body = null, string $key = 'reason'): array
    {
        $response = $this->request($method, $path, $token, MV_TEST_TENANT2_HOST, $body ?? ($method === 'POST' ? [] : null));

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)[$key] ?? null];
    }

    private function request(string $method, string $path, ?string $token, string $host = MV_TEST_TENANT2_HOST, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $server = ['HTTP_X_TENANT_HOST' => $host, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.92.' . random_int(1, 254) . '.' . random_int(1, 254)]
            + ($token !== null ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $token] : []);
        $this->client->request($method, 'https://' . $host . $path, [], [], $server, $body !== null ? (string) json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function account(string $db, string $code, string $name, array $roles, string $firstName): string
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $em = $provider->getEntityManager();
        $email = "g1b-$name-{$this->tag}@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname($firstName)->setLastname('Essai')->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();
        $em->getConnection()->executeStatement("INSERT INTO refresh_tokens (id, refresh_token, username, valid) VALUES (nextval('refresh_tokens_id_seq'), ?, ?, NOW() + INTERVAL '1 day')", ['g1b-' . bin2hex(random_bytes(30)), $email]);
        $token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $em->clear();
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $token;
    }

    /** @return list<array{0: string, 1: string}> [auteur, canal] des lignes de « Accès extérieurs » du site */
    private function journalOn(string $code, string $db): array
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $journal = static::getContainer()->get(\App\Services\Platform\PlatformAccessJournal::class);
        $lines = [...$journal->entries(), ...$journal->groupWrites()];
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return array_map(static fn (array $l): array => [$l['account'], $l['channel']], $lines);
    }

    private function clean(): void
    {
        $this->master->exec("DELETE FROM site_entries WHERE mode = 'group'");
        foreach (['shop_group_events', 'shop_group_invitations', 'shop_group_sites', 'shop_groups'] as $table) {
            $this->master->exec("DELETE FROM $table");
        }
        foreach ([[MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE], [MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE]] as [$db, $code]) {
            $this->pdo($db)->exec("DELETE FROM refresh_tokens WHERE username LIKE 'g1b-%@example.invalid'");
            $this->pdo($db)->exec("DELETE FROM \"user\" WHERE email LIKE 'g1b-%@example.invalid'");
            $this->pdo($db)->exec("DELETE FROM content_audit_log WHERE resource = 'external_access'");
            $this->pdo($db)->exec("DELETE FROM categorie_marque WHERE nom LIKE 'Marque g1b %'");
            $this->pdo($db)->exec('UPDATE entreprise SET is_multi_shop_active = FALSE');
            static::getContainer()->get(TenantCacheService::class)->invalidateSite($code, $db);
        }
    }

    private function pdo(string $db): \PDO
    {
        return static::getContainer()->get(TenantDatabaseConnector::class)->pdo($db);
    }
}
