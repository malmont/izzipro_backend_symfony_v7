<?php

namespace App\Tests\Functional\Screens;

use App\Entity\User;
use App\Services\Agency\AgencyService;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\Screens\ScreenRegistry;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot AG-3a : `GET /api/me/contexts` rend au compte connecté son mode, les sites qu'il peut ouvrir et, pour chacun, ses pastilles, son
 * état, ses modules actifs et les écrans qu'un menu peut lui montrer.
 *
 * Le point de sécurité : un compte ne voit QUE les sites qu'il peut ouvrir. L'administrateur d'un site ne voit que son site, même
 * quand ce site est l'attache d'une agence ou qu'une agence le gère ; un compte d'agence voit les sites de SON agence, en construction
 * seulement ; le super administrateur voit tous les sites. Aucun droit ne change : un compte borné à un espace (agence, assistance)
 * lit ses contextes et rien de plus.
 */
class MeContextsApiTest extends WebTestCase
{
    private const PASSWORD = 'Un-mot-de-passe-solide-42';

    private KernelBrowser $client;
    private \PDO $master;
    private string $tag;
    /** @var array<string, array<string, mixed>> fiche d'origine de chaque site de test, remise à la fin */
    private array $sheets = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // les écrans suivent les modules ACTIFS en mode « live » ; en « demo », rien n'est refusé (essai plus bas)
        static::getContainer()->get(\App\Services\Platform\PlatformModeService::class)->setMode('live', 'test');
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->tag = bin2hex(random_bytes(3));
        $this->clean();
        foreach ([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB] as $db) {
            $this->sheets[$db] = $this->pdo($db)->query('SELECT id, ' . implode(', ', FeatureCatalog::GOVERNED) . ' FROM entreprise ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC) ?: [];
            $this->assertNotSame([], $this->sheets[$db], "le site de test $db a une fiche");
            $this->modules($db, array_fill_keys(array_keys(FeatureCatalog::GOVERNED), true));
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->sheets as $db => $sheet) {
            if ($sheet !== []) {
                $set = implode(', ', array_map(static fn (string $column): string => "$column = :$column", FeatureCatalog::GOVERNED));
                $stmt = $this->pdo($db)->prepare("UPDATE entreprise SET $set WHERE id = :id");
                foreach ($sheet as $column => $value) {
                    $stmt->bindValue(":$column", $column === 'id' ? (int) $value : in_array($value, [true, 't', 1, '1'], true), $column === 'id' ? \PDO::PARAM_INT : \PDO::PARAM_BOOL);
                }
                $stmt->execute();
            }
        }
        $this->clean();
        static::getContainer()->get(\App\Services\Platform\PlatformModeService::class)->setMode('demo', 'test');
        parent::tearDown();
    }

    public function testAnAccountSeesOnlyTheSitesItCanOpenWithTheScreensOfItsModeItsModulesAndItsRoles(): void
    {
        $this->assertSame(401, $this->get(null)->getStatusCode());

        // L'ADMINISTRATEUR D'UN SITE : mode « boutique seule », son site et lui seul, tous les écrans de ses modules
        $admin = $this->token('admin', ['ROLE_ADMIN']);
        $response = $this->get($admin);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $single = json_decode((string) $response->getContent(), true);
        $this->assertSame(['single', MV_TEST_TENANT_CODE, null, null], [$single['mode'], $single['site'], $single['agency'], $single['all']]);
        $this->assertSame([MV_TEST_TENANT_CODE], array_column($single['sites'], 'code'), 'un administrateur de site ne voit que son site');
        $site = $single['sites'][0];
        $this->assertSame([true, [['type' => 'independent']]], [$site['current'], $site['badges']]);
        $this->assertContains($site['state'], ['open', 'construction']);
        $this->assertEqualsCanonicalizing(array_keys(FeatureCatalog::GOVERNED), $site['modules']);
        foreach (['catalogue', 'pages', 'site.reglages', 'gestion.home', 'statistiques', 'pertes.home', 'assistance', 'assistance-reglages', 'clients.demandes', 'admin.catalogue', 'admin.commandes', 'memoires.console'] as $code) {
            $this->assertContains($code, $site['screens'], $code);
        }
        foreach ($site['screens'] as $code) {
            $this->assertNotContains(ScreenRegistry::familyOf($code), [ScreenRegistry::PLATEFORME, ScreenRegistry::AGENCE], "$code : ni la plateforme ni l'espace d'une agence");
        }
        // un module éteint cache ses écrans, et eux seuls
        $this->modules(MV_TEST_TENANT_DB, ['app.management' => false, 'module.loss_prevention' => false]);
        $without = $this->contexts($admin)['sites'][0];
        $this->assertNotContains('app.management', $without['modules']);
        foreach ($without['screens'] as $code) {
            $this->assertNotContains(ScreenRegistry::screen($code)['module'], ['app.management', 'module.loss_prevention'], $code);
        }
        $this->assertContains('catalogue', $without['screens']);
        $this->assertContains('assistance', $without['screens']);
        $this->modules(MV_TEST_TENANT_DB, ['app.management' => true, 'module.loss_prevention' => true]);

        // un compte client : son site, aucun écran d'administration
        // … et rien que le site public ne dise déjà : ni ses modules (assistance, pertes, IA…), ni ses liens d'agence
        $customerToken = $this->token('client', ['ROLE_USER_INTERNET']);
        $customer = $this->contexts($customerToken);
        $this->assertSame(['single', [MV_TEST_TENANT_CODE], []], [$customer['mode'], array_column($customer['sites'], 'code'), $customer['sites'][0]['screens']]);
        $this->assertSame([[], []], [$customer['sites'][0]['modules'], $customer['sites'][0]['badges']]);

        // un agent d'assistance SEUL lit ses contextes (deux écrans), et rien d'autre ne s'ouvre à lui
        $support = $this->token('assistance', ['ROLE_SUPPORT']);
        $this->assertSame(['assistance', 'assistance.demande'], $this->contexts($support)['sites'][0]['screens']);
        $this->assertSame([403, 'SUPPORT_SCOPE_ONLY'], $this->refusal('/api/profile', $support));
        $this->modules(MV_TEST_TENANT_DB, ['module.support' => false]);
        $this->assertSame([], $this->contexts($support)['sites'][0]['screens'], 'module Assistance éteint : plus d\'écran');
        // en mode « demo » de la plateforme, comme la garde des modules qui n'y refuse rien : l'écran reste (17/11/2026)
        static::getContainer()->get(\App\Services\Platform\PlatformModeService::class)->setMode('demo', 'test');
        $this->assertSame(['assistance', 'assistance.demande'], $this->contexts($support)['sites'][0]['screens'], 'mode demo : module éteint mais servi');
        static::getContainer()->get(\App\Services\Platform\PlatformModeService::class)->setMode('live', 'test');
        $this->modules(MV_TEST_TENANT_DB, ['module.support' => true]);

        // UNE AGENCE : le premier site de test est son attache, elle gère le second
        $agencies = static::getContainer()->get(AgencyService::class);
        $agencyId = (int) $agencies->create(['code' => 'agence-ctx-' . $this->tag, 'name' => 'Agence Contextes', 'homeTenantCode' => MV_TEST_TENANT_CODE, 'test' => true], 'essai')['id'];
        $agencies->attach($agencyId, MV_TEST_TENANT2_CODE, 'essai');
        // (le compte client n'apprend pas que son site est celui d'une agence)
        $this->assertSame([[], []], [$this->contexts($customerToken)['sites'][0]['badges'], $this->contexts($customerToken)['sites'][0]['modules']]);
        // … l'administrateur du site d'attache, SANS rôle d'agence, ne voit toujours que son site (qui porte maintenant la pastille de l'agence)
        $still = $this->contexts($admin);
        $this->assertSame(['single', [MV_TEST_TENANT_CODE], 'agency_home'], [$still['mode'], array_column($still['sites'], 'code'), $still['sites'][0]['badges'][0]['type']]);
        // … l'administrateur du site GÉRÉ ne voit que le sien : il ne lit ni l'attache de l'agence ni ses autres sites
        $clientAdmin = $this->loginOn(MV_TEST_TENANT2_HOST, MV_TEST_TENANT2_CODE, MV_TEST_TENANT2_DB, ['ROLE_ADMIN']);
        $theirs = $this->contexts($clientAdmin, MV_TEST_TENANT2_HOST);
        $this->assertSame(['single', MV_TEST_TENANT2_CODE, [MV_TEST_TENANT2_CODE], null], [$theirs['mode'], $theirs['site'], array_column($theirs['sites'], 'code'), $theirs['agency']]);
        $this->assertSame('agency_managed', $theirs['sites'][0]['badges'][0]['type']);
        $this->assertContains('gestion.home', $theirs['sites'][0]['screens'], 'chez lui, il garde tous ses écrans');

        // … un compte d'agence SEUL : mode agence ; chez son client, la construction et rien d'autre
        $member = $this->token('agence', ['ROLE_AGENCY_MEMBER']);
        $agency = $this->contexts($member);
        $this->assertSame(['agency', MV_TEST_TENANT_CODE, ['id' => $agencyId, 'code' => 'agence-ctx-' . $this->tag, 'name' => 'Agence Contextes', 'status' => 'trial']], [$agency['mode'], $agency['site'], $agency['agency']]);
        $this->assertSame([[MV_TEST_TENANT_CODE, true, true], [MV_TEST_TENANT2_CODE, false, false]], array_map(static fn (array $s): array => [$s['code'], $s['home'], $s['current']], $agency['sites']));
        $this->assertSame([], $agency['sites'][0]['screens'], 'sur le site d\'attache, un compte sans rôle d\'administrateur n\'a aucun écran de site');
        $client = $agency['sites'][1];
        $this->assertSame('agency_managed', $client['badges'][0]['type']);
        $this->assertNotSame([], $client['screens']);
        foreach ($client['screens'] as $code) {
            $this->assertSame(ScreenRegistry::CONSTRUCTION, ScreenRegistry::familyOf($code), "$code : chez un client, la construction seulement");
        }
        foreach (['catalogue', 'pages', 'site.reglages', 'site.ouverture', 'imports', 'pages.ia', 'admin.catalogue'] as $code) {
            $this->assertContains($code, $client['screens'], $code);
        }
        foreach (['site.assistant', 'site.journal', 'imports.clients', 'imports.commandes', 'commandes', 'statistiques', 'console', 'pertes.home', 'assistance', 'clients.demandes', 'admin.commandes', 'admin.utilisateurs', 'admin.assistant'] as $code) {
            $this->assertNotContains($code, $client['screens'], $code);
        }
        $this->assertSame(['key' => 'clients', 'screens' => ['agence.clients']], $agency['all']);
        // un module éteint chez le client cache ses écrans chez l'agence aussi
        $this->modules(MV_TEST_TENANT2_DB, ['module.boutique' => false]);
        $this->assertNotContains('catalogue', $this->contexts($member)['sites'][1]['screens']);
        $this->modules(MV_TEST_TENANT2_DB, ['module.boutique' => true]);
        // aucun droit n'a changé : hors de l'espace de l'agence et de ses contextes, ce compte est toujours refusé
        $this->assertSame([403, 'AGENCY_SCOPE_ONLY'], $this->refusal('/api/memoires/books/1/invitations', $member), 'une route ouverte à tout compte connecté lui reste fermée');
        $this->assertSame(403, $this->refusal('/api/profile', $member)[0]);
        $this->assertSame(403, $this->refusal('/api/me/contexts/', $member)[0], 'seul le chemin exact est ouvert');
        // … le responsable qui administre aussi le site d'attache y garde ses écrans de site ; chez le client, toujours la construction
        $manager = $this->contexts($this->token('responsable', ['ROLE_ADMIN', 'ROLE_AGENCY']));
        $this->assertSame('agency', $manager['mode']);
        $this->assertContains('gestion.home', $manager['sites'][0]['screens']);
        $this->assertNotContains('gestion.home', $manager['sites'][1]['screens']);
        $this->assertSame($client['screens'], $manager['sites'][1]['screens']);
        // … une agence SUSPENDUE garde la liste de ses clients, sans aucun écran chez eux ; son site d'attache reste le sien
        $this->master->exec("UPDATE agencies SET status = 'suspended' WHERE id = $agencyId");
        $suspended = $this->contexts($this->token('responsable-suspendu', ['ROLE_ADMIN', 'ROLE_AGENCY']));
        $this->assertSame(['agency', 'suspended', [MV_TEST_TENANT_CODE, MV_TEST_TENANT2_CODE], []], [$suspended['mode'], $suspended['agency']['status'], array_column($suspended['sites'], 'code'), $suspended['sites'][1]['screens']]);
        $this->assertContains('gestion.home', $suspended['sites'][0]['screens']);
        $this->master->exec("UPDATE agencies SET status = 'trial' WHERE id = $agencyId");
        // … le numéro d'une demande de création à compléter n'est rendu qu'à la plateforme
        $this->master->exec("INSERT INTO platform_site_requests (type, tenant_code, name, admin_email, options, steps, status) VALUES ('normal', '" . MV_TEST_TENANT_CODE . "', 'Contextes', 'contextes-demande@example.invalid', '{}', '{}', 'incomplete')");
        $incomplete = array_column($this->contexts($admin)['sites'][0]['badges'], null, 'type')['creation_incomplete'];
        $this->assertSame(['type' => 'creation_incomplete'], $incomplete);
        // … un site détaché sort de la liste ; une agence fermée n'ouvre plus le mode agence
        $agencies->detach($agencyId, MV_TEST_TENANT2_CODE, 'essai');
        $this->assertSame([MV_TEST_TENANT_CODE], array_column($this->contexts($member)['sites'], 'code'));
        $this->master->exec("UPDATE agencies SET status = 'closed' WHERE id = $agencyId");
        $closed = $this->contexts($member);
        $this->assertSame(['single', [MV_TEST_TENANT_CODE], null], [$closed['mode'], array_column($closed['sites'], 'code'), $closed['all']]);

        // LE SUPER ADMINISTRATEUR : tous les sites, et les écrans de la plateforme à part
        $super = $this->contexts($this->token('super', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']));
        $this->assertSame('super_admin', $super['mode']);
        $this->assertSame($this->master->query('SELECT code FROM tenants ORDER BY code')->fetchAll(\PDO::FETCH_COLUMN), array_column($super['sites'], 'code'));
        $this->assertGreaterThan(1, count($super['sites']));
        $this->assertSame(['key' => 'sites', 'screens' => ['plateforme.pilotage', 'plateforme.agences', 'plateforme.sites', 'admin.maintenance']], $super['all']);
        $own = array_column($super['sites'], null, 'code')[MV_TEST_TENANT_CODE];
        $this->assertContains('gestion.home', $own['screens']);
        $this->assertNotContains('plateforme.sites', $own['screens'], 'les écrans de la plateforme ne sont pas ceux d\'un site');
        $this->assertArrayHasKey('requestId', array_column($own['badges'], null, 'type')['creation_incomplete'], 'la plateforme, elle, lit le numéro de la demande');
        // les exemples de la fiche (`docs/ecrans-et-contextes.md` § 3) sortent d'ici : EXEMPLES_CONTEXTES=/tmp/contextes.json
        if (is_string(getenv('EXEMPLES_CONTEXTES')) && getenv('EXEMPLES_CONTEXTES') !== '') {
            file_put_contents(getenv('EXEMPLES_CONTEXTES'), json_encode(['single' => $single, 'agency' => $agency, 'super_admin' => ['mode' => $super['mode'], 'site' => $super['site'], 'agency' => null,
                'sites' => [$own], 'all' => $super['all']]], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        }
    }

    /** @return array<string, mixed> */
    private function contexts(string $token, string $host = MV_TEST_TENANT_HOST): array
    {
        $response = $this->get($token, '/api/me/contexts', $host);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true);
    }

    /** @return array{0: int, 1: ?string} */
    private function refusal(string $path, string $token): array
    {
        $response = $this->get($token, $path);

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)['code'] ?? null];
    }

    private function get(?string $token, string $path = '/api/me/contexts', string $host = MV_TEST_TENANT_HOST): Response
    {
        $this->client->getCookieJar()->clear();
        $server = ['HTTP_X_TENANT_HOST' => $host, 'HTTP_ACCEPT' => 'application/json'] + ($token !== null ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $token] : []);
        $this->client->request('GET', 'https://' . $host . $path, [], [], $server);

        return $this->client->getResponse();
    }

    /** @param array<string, bool> $flags clé de module => actif */
    private function modules(string $db, array $flags): void
    {
        $pdo = $this->pdo($db);
        foreach ($flags as $key => $active) {
            $pdo->exec('UPDATE entreprise SET ' . FeatureCatalog::GOVERNED[$key] . ' = ' . ($active ? 'true' : 'false'));
        }
    }

    /** Jeton d'un compte du premier site de test */
    private function token(string $name, array $roles): string
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $email = "contextes-$name-{$this->tag}@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Contextes')->setLastname($name)->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    /** Un compte d'un AUTRE site, connecté par la vraie route de connexion de ce site */
    private function loginOn(string $host, string $code, string $db, array $roles): string
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $email = "contextes-ailleurs-{$this->tag}@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Contextes')->setLastname('Ailleurs')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $provider->getEntityManager()->clear();
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . $host . '/api/login', [], [], ['HTTP_X_TENANT_HOST' => $host, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '10.84.' . random_int(1, 254) . '.' . random_int(1, 254)], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'webadmin']));
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === "auth_token_$code") {
                return (string) $cookie->getValue();
            }
        }
        $this->fail('connexion refusée sur ' . $host . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));
    }

    private function clean(): void
    {
        foreach (['agency_events', 'agency_sites'] as $table) {
            $this->master->exec("DELETE FROM $table");
        }
        $this->master->exec('DELETE FROM agency_plans WHERE agency_id IS NOT NULL');
        $this->master->exec('DELETE FROM agencies');
        $this->master->exec("DELETE FROM platform_site_requests WHERE admin_email = 'contextes-demande@example.invalid'");
        foreach ([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB] as $db) {
            $this->pdo($db)->exec("DELETE FROM refresh_tokens WHERE username LIKE 'contextes-%@example.invalid'");
            $this->pdo($db)->exec("DELETE FROM \"user\" WHERE email LIKE 'contextes-%@example.invalid'");
        }
    }

    private function pdo(string $db): \PDO
    {
        return static::getContainer()->get(TenantDatabaseConnector::class)->pdo($db);
    }
}
