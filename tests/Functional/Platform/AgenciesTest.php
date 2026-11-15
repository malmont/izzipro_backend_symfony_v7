<?php

namespace App\Tests\Functional\Platform;

use App\Entity\User;
use App\Security\AgencyRoles;
use App\Security\AgencyScopeSubscriber;
use App\Security\AppFamilies;
use App\Security\RoleAssignmentPolicy;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Agences, lot AG-1 (15/11/2026, `docs/agences-et-import.md` § 3). Base maître seule : une agence a un site d'attache, gère des sites
 * (un site n'a qu'une agence, 3 au plus pendant l'essai), se suspend ; les rôles d'agence se donnent à des comptes du site d'attache,
 * par le super administrateur seulement ; l'agence lit son espace depuis son site d'attache, jamais par un paramètre ; un compte
 * d'agence seul n'atteint que `/api/agency`. Les sites d'essai sont les deux sites de test, plus des sites fictifs de la base maître
 * de test, retirés à la fin.
 */
class AgenciesTest extends WebTestCase
{
    private const PASSWORD = 'Un-mot-de-passe-solide-42';

    private KernelBrowser $client;
    private \PDO $master;
    private string $tag;
    private string $ip;
    /** @var array<string, string> jetons par clé */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->tag = bin2hex(random_bytes(3));
        $this->ip = '10.85.' . random_int(1, 254) . '.' . random_int(1, 254);
        $this->clean();
        // sites fictifs de la base maître de test : trois sites à part, un site sur la base du second site de test, et un site « demo »
        $insert = $this->master->prepare('INSERT INTO tenants (code, name, dbname) VALUES (?, ?, ?)');
        foreach ([['agfict-a', 'Fictif A', 'db_agfict_a'], ['agfict-b', 'Fictif B', 'db_agfict_b'], ['agfict-c', 'Fictif C', 'db_agfict_c'], ['agfict-d', 'Fictif D', 'db_agfict_d'],
            ['agfict-jumeau', 'Jumeau du second site', MV_TEST_TENANT2_DB], ['demo', 'Site de contrôle', 'db_agfict_demo']] as $row) {
            $insert->execute($row);
        }
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    public function testThePlatformCreatesAnAgencyAttachesSitesWithinItsLimitAndSuspendsIt(): void
    {
        // Droits : la plateforme seulement. Ni anonyme, ni administrateur de site
        $this->assertSame(401, $this->api('GET', '/api/platform/agencies', null, null)->getStatusCode());
        $this->assertSame(403, $this->api('POST', '/api/platform/agencies', ['code' => 'x', 'name' => 'X', 'homeTenantCode' => MV_TEST_TENANT_CODE], 'admin')->getStatusCode());
        $this->assertSame(0, (int) $this->master->query('SELECT COUNT(*) FROM agencies')->fetchColumn());

        // Créations refusées : champs, site inconnu, site réservé
        $refused = $this->json('POST', '/api/platform/agencies', ['code' => 'A', 'name' => '', 'homeTenantCode' => 'inconnu', 'contactEmail' => 'pas-une-adresse', 'test' => 'oui'], 'super', 422);
        $this->assertSame(['VALIDATION_FAILED', ['code', 'name', 'homeTenantCode', 'contactEmail', 'test']], [$refused['code'], array_column($refused['errors'], 'path')]);
        foreach (['demo', 'gmasuite', 'tenantdefaut'] as $reserved) {
            $this->master->prepare('INSERT INTO tenants (code, name, dbname) SELECT ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM tenants WHERE code = ?)')->execute([$reserved, 'Réservé', 'db_agfict_' . $reserved, $reserved]);
            $this->assertSame('AGENCY_SITE_RESERVED', $this->json('POST', '/api/platform/agencies', ['code' => 'agence-test', 'name' => 'Agence', 'homeTenantCode' => $reserved], 'super', 409)['code'], $reserved);
        }
        $this->assertSame(0, (int) $this->master->query('SELECT COUNT(*) FROM agencies')->fetchColumn());

        // Création : en essai, 60 jours, 3 sites ; le site d'attache est le site de test
        $created = $this->json('POST', '/api/platform/agencies', ['code' => 'Agence-Test', 'name' => 'Agence de test', 'homeTenantCode' => MV_TEST_TENANT_CODE, 'contactEmail' => 'Contact@Example.invalid', 'test' => true], 'super', 201)['agency'];
        $id = (int) $created['id'];
        $this->assertSame(['agence-test', 'Agence de test', 'trial', true, 3, 0, 'essai', 'contact@example.invalid', false, MV_TEST_TENANT_CODE, MV_TEST_TENANT_HOST],
            [$created['code'], $created['name'], $created['status'], $created['isTest'], $created['maxSites'], $created['sitesCount'], $created['planCode'], $created['contactEmail'], $created['trialEnded'],
                $created['homeSite']['code'], $created['homeSite']['host']]);
        $this->assertEqualsWithDelta(time() + 60 * 86400, strtotime((string) $created['trialEndsAt']), 120, 'essai de 60 jours, en UTC');
        $this->assertStringEndsWith('+00:00', (string) $created['trialEndsAt']);
        // un code, un site d'attache : une seule agence chacun ; le site d'attache d'une agence n'est pas un site géré
        $this->assertSame('AGENCY_CODE_TAKEN', $this->json('POST', '/api/platform/agencies', ['code' => 'agence-test', 'name' => 'Autre', 'homeTenantCode' => 'agfict-a'], 'super', 409)['code']);
        $this->assertSame('AGENCY_HOME_TAKEN', $this->json('POST', '/api/platform/agencies', ['code' => 'agence-bis', 'name' => 'Autre', 'homeTenantCode' => MV_TEST_TENANT_CODE], 'super', 409)['code']);
        $this->assertSame('AGENCY_SITE_TAKEN', $this->json('POST', "/api/platform/agencies/$id/sites", ['tenantCode' => MV_TEST_TENANT_CODE], 'super', 409)['code']);

        // Rattacher : site inconnu, réservé, puis trois sites ; le quatrième dépasse la limite de l'essai
        $this->assertSame(['tenantCode'], array_column($this->json('POST', "/api/platform/agencies/$id/sites", ['tenantCode' => 'inconnu'], 'super', 422)['errors'], 'path'));
        $this->assertSame('AGENCY_SITE_RESERVED', $this->json('POST', "/api/platform/agencies/$id/sites", ['tenantCode' => 'demo'], 'super', 409)['code']);
        $this->assertSame(404, $this->api('POST', '/api/platform/agencies/999999/sites', ['tenantCode' => MV_TEST_TENANT2_CODE], 'super')->getStatusCode());
        $attached = $this->json('POST', "/api/platform/agencies/$id/sites", ['tenantCode' => MV_TEST_TENANT2_CODE], 'super', 201);
        $this->assertSame([1, [MV_TEST_TENANT2_CODE], 'Second tenant de test', MV_TEST_TENANT2_HOST, 'attached'], [$attached['agency']['sitesCount'], array_column($attached['sites'], 'code'), $attached['sites'][0]['name'],
            $attached['sites'][0]['host'], $attached['sites'][0]['origin']]);
        // deux codes pour une même base : la base du second site de test est déjà confiée
        $this->assertSame('AGENCY_SITE_SHARES_DATABASE', $this->json('POST', "/api/platform/agencies/$id/sites", ['tenantCode' => 'agfict-jumeau'], 'super', 409)['code']);
        $this->assertSame('AGENCY_SITE_TAKEN', $this->json('POST', "/api/platform/agencies/$id/sites", ['tenantCode' => MV_TEST_TENANT2_CODE], 'super', 409)['code'], 'un site ne se rattache pas deux fois');
        $this->json('POST', "/api/platform/agencies/$id/sites", ['tenantCode' => 'agfict-a'], 'super', 201);
        $this->json('POST', "/api/platform/agencies/$id/sites", ['tenantCode' => 'agfict-b'], 'super', 201);
        $limit = $this->json('POST', "/api/platform/agencies/$id/sites", ['tenantCode' => 'agfict-c'], 'super', 409);
        $this->assertSame(['AGENCY_SITE_LIMIT', 3], [$limit['code'], $limit['maxSites']]);
        $this->assertSame(3, (int) $this->master->query('SELECT COUNT(*) FROM agency_sites WHERE detached_at IS NULL')->fetchColumn());

        // Un site n'a qu'une agence : une seconde agence ne peut ni le prendre, ni prendre pour site d'attache un site géré
        $second = (int) $this->json('POST', '/api/platform/agencies', ['code' => 'agence-deux', 'name' => 'Seconde agence', 'homeTenantCode' => 'agfict-c'], 'super', 201)['agency']['id'];
        $this->assertSame('AGENCY_SITE_TAKEN', $this->json('POST', "/api/platform/agencies/$second/sites", ['tenantCode' => 'agfict-a'], 'super', 409)['code']);
        $this->assertSame('AGENCY_HOME_TAKEN', $this->json('POST', '/api/platform/agencies', ['code' => 'agence-trois', 'name' => 'Troisième', 'homeTenantCode' => 'agfict-a'], 'super', 409)['code']);
        // … la base elle-même le garantit, quelles que soient les demandes simultanées
        try {
            $this->master->prepare('INSERT INTO agency_sites (agency_id, tenant_code) VALUES (?, ?)')->execute([$second, 'agfict-a']);
            $this->fail('la base accepte deux agences pour un site');
        } catch (\PDOException $e) {
            $this->assertSame('23505', $e->getCode());
        }

        // Détacher : le site est libre, la place aussi ; un site jamais rattaché : 404
        $this->assertSame('AGENCY_SITE_NOT_ATTACHED', $this->json('DELETE', "/api/platform/agencies/$id/sites/agfict-d", null, 'super', 404)['code']);
        $detached = $this->json('DELETE', "/api/platform/agencies/$id/sites/agfict-a", null, 'super', 200);
        $this->assertSame([2, [MV_TEST_TENANT2_CODE, 'agfict-b']], [$detached['agency']['sitesCount'], array_column($detached['sites'], 'code')]);
        $this->json('POST', "/api/platform/agencies/$second/sites", ['tenantCode' => 'agfict-a'], 'super', 201);

        // Suspendre : l'agence ne reçoit plus de site ; reprendre : elle revient à l'essai (sa fin n'est pas passée)
        $suspended = $this->json('POST', "/api/platform/agencies/$id/suspend", ['reason' => 'Impayé du relevé'], 'super', 200)['agency'];
        $this->assertSame('suspended', $suspended['status']);
        $this->assertNotNull($suspended['suspendedAt']);
        $this->assertSame('AGENCY_NOT_OPEN', $this->json('POST', "/api/platform/agencies/$id/sites", ['tenantCode' => 'agfict-d'], 'super', 409)['code']);
        $this->assertSame('AGENCY_STATE', $this->json('POST', "/api/platform/agencies/$id/suspend", [], 'super', 409)['code'], 'déjà suspendue');
        $resumed = $this->json('POST', "/api/platform/agencies/$id/resume", null, 'super', 200)['agency'];
        $this->assertSame(['trial', null], [$resumed['status'], $resumed['suspendedAt']]);
        $this->assertSame('AGENCY_STATE', $this->json('POST', "/api/platform/agencies/$id/resume", null, 'super', 409)['code']);
        // une agence dont l'essai est passé revient « active » ; la fin de l'essai est AFFICHÉE, rien ne se ferme tout seul
        $this->master->prepare("UPDATE agencies SET trial_ends_at = NOW() - INTERVAL '1 day' WHERE id = ?")->execute([$id]);
        $ended = $this->json('GET', "/api/platform/agencies/$id", null, 'super', 200);
        $this->assertSame(['trial', true], [$ended['agency']['status'], $ended['agency']['trialEnded']]);
        $this->json('POST', "/api/platform/agencies/$id/suspend", [], 'super', 200);
        $this->assertSame('active', $this->json('POST', "/api/platform/agencies/$id/resume", null, 'super', 200)['agency']['status']);

        // Lecture : la liste, le détail, le journal (du plus récent au plus ancien), sans donnée personnelle
        $this->assertSame(['agence-test', 'agence-deux'], array_column($this->json('GET', '/api/platform/agencies', null, 'super', 200)['agencies'], 'code'));
        $detail = $this->json('GET', "/api/platform/agencies/$id", null, 'super', 200);
        $this->assertSame([[], [MV_TEST_TENANT2_CODE, 'agfict-b']], [$detail['members'], array_column($detail['sites'], 'code')]);
        $this->assertSame(['resumed', 'suspended', 'resumed', 'suspended', 'site_detached', 'site_attached', 'site_attached', 'site_attached', 'created'], array_column($detail['events'], 'event'));
        $this->assertSame(['super-agences@example.invalid', 'agfict-a', 'Impayé du relevé'], [$detail['events'][8]['actor'], $detail['events'][4]['tenantCode'], $detail['events'][3]['payload']['reason']]);

        // Aucun droit d'usage, aucun site : rattacher ne change rien pour un site
        $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM tenant_entitlements WHERE source = 'agency' OR tenant_code LIKE 'agfict-%'")->fetchColumn());
    }

    public function testAgencyRolesAreGivenByThePlatformOnlyAndAnAgencyAccountOnlyReachesItsAgencySpace(): void
    {
        $id = (int) $this->json('POST', '/api/platform/agencies', ['code' => 'agence-test', 'name' => 'Agence de test', 'homeTenantCode' => MV_TEST_TENANT_CODE, 'test' => true], 'super', 201)['agency']['id'];
        $this->json('POST', "/api/platform/agencies/$id/sites", ['tenantCode' => MV_TEST_TENANT2_CODE], 'super', 201);
        $manager = $this->account("agence-resp-{$this->tag}@example.invalid", ['ROLE_ADMIN'], MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $member = $this->account("agence-collab-{$this->tag}@example.invalid", [], MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $roles = fn (int $userId, string $db = MV_TEST_TENANT_DB): array => json_decode((string) $this->pdo($db)->query('SELECT roles FROM "user" WHERE id = ' . $userId)->fetchColumn(), true);

        // Donner un rôle : la plateforme seulement ; champs contrôlés ; le compte doit être du site d'attache
        $this->assertSame(403, $this->api('PUT', "/api/platform/agencies/$id/members", ['email' => "agence-collab-{$this->tag}@example.invalid", 'role' => 'member'], 'admin')->getStatusCode());
        $this->assertSame(['email', 'role'], array_column($this->json('PUT', "/api/platform/agencies/$id/members", ['email' => 'x', 'role' => 'chef'], 'super', 422)['errors'], 'path'));
        $this->assertSame('AGENCY_MEMBER_NOT_FOUND', $this->json('PUT', "/api/platform/agencies/$id/members", ['email' => 'inconnu@example.invalid', 'role' => 'member'], 'super', 404)['code']);
        $granted = $this->json('PUT', "/api/platform/agencies/$id/members", ['email' => 'AGENCE-collab-' . $this->tag . '@example.invalid', 'role' => 'member'], 'super', 200);
        $this->assertSame([['userId' => $member, 'email' => "agence-collab-{$this->tag}@example.invalid", 'role' => 'member', 'changed' => true], ['member']], [$granted['member'], array_column($granted['members'], 'role')]);
        $this->assertSame(['ROLE_AGENCY_MEMBER'], $roles($member));
        // le responsable garde ses autres rôles ; un compte ne porte qu'un rôle d'agence à la fois ; sans changement, rien n'est écrit
        $this->json('PUT', "/api/platform/agencies/$id/members", ['email' => "agence-resp-{$this->tag}@example.invalid", 'role' => 'member'], 'super', 200);
        $this->json('PUT', "/api/platform/agencies/$id/members", ['email' => "agence-resp-{$this->tag}@example.invalid", 'role' => 'manager'], 'super', 200);
        $this->assertSame(['ROLE_ADMIN', 'ROLE_AGENCY'], $roles($manager));
        $events = fn (): int => (int) $this->master->query("SELECT COUNT(*) FROM agency_events WHERE event LIKE 'role_%'")->fetchColumn();
        $before = $events();
        $this->assertFalse($this->json('PUT', "/api/platform/agencies/$id/members", ['email' => "agence-resp-{$this->tag}@example.invalid", 'role' => 'manager'], 'super', 200)['member']['changed']);
        $this->assertSame([3, $before], [$before, $events()]);
        $journal = $this->master->query("SELECT payload FROM agency_events WHERE event = 'role_granted' ORDER BY id LIMIT 1")->fetchColumn();
        $this->assertEquals(['userId' => $member, 'role' => 'member', 'was' => null], json_decode((string) $journal, true), 'le journal de la base maître ne porte pas l\'adresse du compte');
        $this->assertStringNotContainsString('@', (string) $this->master->query("SELECT string_agg(payload::text, ' ') FROM agency_events")->fetchColumn());

        // Un administrateur de site ne peut ni donner ni retirer un rôle d'agence par l'écran des utilisateurs, même en forgeant le formulaire
        $policy = static::getContainer()->get(RoleAssignmentPolicy::class);
        $this->assertNotContains('ROLE_AGENCY', $policy->apply(['ROLE_ADMIN', 'ROLE_AGENCY', 'ROLE_AGENCY_MEMBER'], ['ROLE_ADMIN'], false));
        $this->assertContains('ROLE_AGENCY', $policy->apply(['ROLE_ADMIN'], ['ROLE_ADMIN', 'ROLE_AGENCY'], false), 'un rôle d\'agence déjà présent est conservé');
        $this->assertNotContains('ROLE_AGENCY', $policy->apply(['ROLE_ADMIN', 'ROLE_AGENCY'], ['ROLE_ADMIN'], true), 'même le super administrateur ne le donne pas par cet écran : par la page des agences seulement');
        // un compte d'agence est un compte d'équipe, pas un client
        $this->assertSame([true, true, true, false], [AppFamilies::isTeam(['ROLE_AGENCY_MEMBER']), AgencyScopeSubscriber::agencyOnly(['ROLE_AGENCY_MEMBER', 'ROLE_USER']),
            AgencyScopeSubscriber::agencyOnly(['ROLE_AGENCY', 'ROLE_SUPPORT']), AgencyScopeSubscriber::agencyOnly(['ROLE_AGENCY', 'ROLE_ADMIN'])]);

        // Le collaborateur entre dans l'espace d'administration du web (et seulement là), et lit son agence : jamais d'un paramètre
        $this->assertSame(403, $this->login("agence-collab-{$this->tag}@example.invalid", 'web', MV_TEST_TENANT_HOST)->getStatusCode(), 'pas un client');
        $this->assertSame(403, $this->login("agence-collab-{$this->tag}@example.invalid", 'admin', MV_TEST_TENANT_HOST)->getStatusCode(), 'pas l\'application de gestion mobile');
        $login = $this->login("agence-collab-{$this->tag}@example.invalid", 'webadmin', MV_TEST_TENANT_HOST);
        $this->assertSame(200, $login->getStatusCode(), (string) $login->getContent());
        $this->tokens['member'] = $this->tokenOf($login, MV_TEST_TENANT_CODE);
        $me = $this->json('GET', '/api/agency/me', null, 'member', 200);
        $this->assertSame(['agence-test', 'trial', 1, 3, MV_TEST_TENANT_CODE, 'member'], [$me['agency']['code'], $me['agency']['status'], $me['agency']['sitesCount'], $me['agency']['maxSites'], $me['agency']['homeSite']['code'], $me['role']]);
        $sites = $this->json('GET', '/api/agency/sites?agency=999&tenant=agfict-a&agencyId=2', null, 'member', 200);
        $this->assertSame([[MV_TEST_TENANT2_CODE], 3, 1], [array_column($sites['sites'], 'code'), $sites['maxSites'], $sites['used']]);

        // … et rien d'autre : ni la gestion, ni le site, ni les routes qui ne demandent qu'un compte connecté, ni la plateforme
        foreach (['/api/gestion/statistiques', '/api/site/imports', '/api/gestion/rapports', '/api/platform/agencies', '/api/support/me', '/api/account/memberships'] as $route) {
            $this->assertSame(403, $this->api('GET', $route, null, 'member')->getStatusCode(), $route);
        }
        $scoped = $this->api('GET', '/api/memoires/team', null, 'member');
        $this->assertSame([403, AgencyScopeSubscriber::CODE], [$scoped->getStatusCode(), json_decode((string) $scoped->getContent(), true)['code']], 'une route ouverte à tout compte connecté lui est fermée');
        $this->assertSame(403, $this->api('POST', '/api/site/imports', [], 'member')->getStatusCode());

        // Le périmètre : le jeton d'un site ne vaut pas sur un autre ; un site qui n'est pas un site d'attache n'a pas d'espace d'agence, même
        // pour un compte qui porterait le rôle ; un administrateur sans rôle d'agence, et le super administrateur, n'y entrent pas
        $this->assertSame(401, $this->api('GET', '/api/agency/me', null, 'member', MV_TEST_TENANT2_HOST)->getStatusCode());
        $stray = $this->account("agence-ailleurs-{$this->tag}@example.invalid", ['ROLE_AGENCY'], MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE);
        $login = $this->login("agence-ailleurs-{$this->tag}@example.invalid", 'webadmin', MV_TEST_TENANT2_HOST);
        $this->assertSame(200, $login->getStatusCode(), (string) $login->getContent());
        $this->tokens['stray'] = $this->tokenOf($login, MV_TEST_TENANT2_CODE);
        foreach (['/api/agency/me', '/api/agency/sites', '/api/agency/me?agencyId=' . $id . '&tenant=' . MV_TEST_TENANT_CODE] as $route) {
            $response = $this->api('GET', $route, null, 'stray', MV_TEST_TENANT2_HOST);
            $this->assertSame([403, 'AGENCY_SCOPE'], [$response->getStatusCode(), json_decode((string) $response->getContent(), true)['code']], $route);
        }
        $this->assertSame(403, $this->api('GET', '/api/agency/me', null, 'admin')->getStatusCode());
        $this->assertSame(403, $this->api('GET', '/api/agency/me', null, 'super')->getStatusCode());
        $this->assertSame(401, $this->api('GET', '/api/agency/me', null, null)->getStatusCode());
        $this->assertGreaterThan(0, $stray);

        // Une agence suspendue lit encore son espace, avec son état ; retirer le rôle ferme l'espace tout de suite (les rôles sont relus en base)
        $this->json('POST', "/api/platform/agencies/$id/suspend", [], 'super', 200);
        $this->assertSame('suspended', $this->json('GET', '/api/agency/me', null, 'member', 200)['agency']['status']);
        $revoked = $this->json('PUT', "/api/platform/agencies/$id/members", ['email' => "agence-collab-{$this->tag}@example.invalid", 'role' => 'none'], 'super', 200);
        $this->assertSame([null, true, ['manager']], [$revoked['member']['role'], $revoked['member']['changed'], array_column($revoked['members'], 'role')]);
        $this->assertSame([], $roles($member));
        $this->assertSame(403, $this->api('GET', '/api/agency/me', null, 'member')->getStatusCode());
    }

    public function testTheSuperAdministratorManagesAgenciesFromTheAdministrationPage(): void
    {
        $url = 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/agences';
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST];
        // Un administrateur de site n'ouvre pas la page
        $this->loginAs(['ROLE_ADMIN']);
        $this->client->request('GET', $url, [], [], $server);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', $url . '/creer', ['_token' => 'x', 'code' => 'agence-test', 'name' => 'Agence', 'homeTenantCode' => MV_TEST_TENANT_CODE], [], $server);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $crawler = $this->client->request('GET', $url, [], [], $server);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 600));
        $token = $crawler->filter('form.agences-creer input[name="_token"]')->attr('value');
        $this->assertStringContainsString('Aucune agence.', (string) $this->client->getResponse()->getContent());
        // les sites réservés ne sont pas proposés comme site d'attache
        $this->assertSame(0, $crawler->filter('form.agences-creer select[name="homeTenantCode"] option[value="demo"]')->count());

        // Un jeton faux ne crée rien
        $this->client->request('POST', $url . '/creer', ['_token' => 'faux', 'code' => 'agence-test', 'name' => 'Agence', 'homeTenantCode' => MV_TEST_TENANT_CODE], [], $server);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, (int) $this->master->query('SELECT COUNT(*) FROM agencies')->fetchColumn());

        // Créer, rattacher, donner un rôle, suspendre : les mêmes services que l'API ; chaque geste revient à la page habillée
        $this->client->request('POST', $url . '/creer', ['_token' => $token, 'code' => 'agence-test', 'name' => 'Agence de test', 'homeTenantCode' => MV_TEST_TENANT_CODE, 'test' => '1'], [], $server);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('routeName=admin_plateforme_agences', (string) $this->client->getResponse()->headers->get('Location'));
        $agency = $this->master->query('SELECT id, status, is_test FROM agencies')->fetch(\PDO::FETCH_ASSOC);
        $id = (int) $agency['id'];
        $this->assertSame(['trial', true], [$agency['status'], in_array($agency['is_test'], [true, 't', 1, '1'], true)]);
        $this->client->request('POST', "$url/$id/rattacher", ['_token' => $token, 'agence' => $id, 'tenantCode' => MV_TEST_TENANT2_CODE], [], $server);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1, (int) $this->master->query('SELECT COUNT(*) FROM agency_sites WHERE detached_at IS NULL')->fetchColumn());
        $member = $this->account("agence-page-{$this->tag}@example.invalid", [], MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->client->request('POST', "$url/$id/role", ['_token' => $token, 'agence' => $id, 'email' => "agence-page-{$this->tag}@example.invalid", 'role' => 'manager'], [], $server);
        $this->assertSame(['ROLE_AGENCY'], json_decode((string) $this->pdo(MV_TEST_TENANT_DB)->query('SELECT roles FROM "user" WHERE id = ' . $member)->fetchColumn(), true));
        // un refus métier devient un message, rien n'est écrit
        $this->client->request('POST', "$url/$id/rattacher", ['_token' => $token, 'agence' => $id, 'tenantCode' => 'demo'], [], $server);
        $this->assertSame(1, (int) $this->master->query('SELECT COUNT(*) FROM agency_sites WHERE detached_at IS NULL')->fetchColumn());

        // La page de l'agence : ses sites, ses comptes, son journal
        $crawler = $this->client->request('GET', $url . '?agence=' . $id, [], [], $server);
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString(htmlspecialchars('Ce site ne peut pas être confié à une agence.', ENT_QUOTES), $html, 'le refus du rattachement de demo est dit sur la page');
        $token = $crawler->filter('form.agences-etat input[name="_token"]')->attr('value');
        foreach (['Agence « Agence de test »', MV_TEST_TENANT2_CODE, "agence-page-{$this->tag}@example.invalid", 'Responsable', 'site_attached', 'role_granted'] as $expected) {
            $this->assertStringContainsString(htmlspecialchars($expected, ENT_QUOTES), $html, $expected);
        }
        $this->client->request('POST', "$url/$id/etat/suspend", ['_token' => $token, 'agence' => $id, 'reason' => 'Essai'], [], $server);
        $this->assertSame('suspended', $this->master->query('SELECT status FROM agencies')->fetchColumn());
        $this->client->request('POST', "$url/$id/detacher", ['_token' => $token, 'agence' => $id, 'tenantCode' => MV_TEST_TENANT2_CODE], [], $server);
        $this->assertSame(0, (int) $this->master->query('SELECT COUNT(*) FROM agency_sites WHERE detached_at IS NULL')->fetchColumn());
        // l'entrée du menu, pour le super administrateur
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin', [], [], $server);
        $this->assertStringContainsString('Plateforme : agences', (string) $this->client->getResponse()->getContent());
    }

    /** Retire de la base maître de test et des deux sites de test tout ce que ces essais ont écrit */
    private function clean(): void
    {
        foreach (['agency_events', 'agency_sites'] as $table) {
            $this->master->exec("DELETE FROM $table");
        }
        $this->master->exec('DELETE FROM agency_plans WHERE agency_id IS NOT NULL');
        $this->master->exec('DELETE FROM agencies');
        $this->master->exec("DELETE FROM tenants WHERE code LIKE 'agfict-%' OR code IN ('demo', 'gmasuite', 'tenantdefaut') AND dbname LIKE 'db_agfict_%'");
        foreach ([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB] as $db) {
            $this->pdo($db)->exec("DELETE FROM \"user\" WHERE email LIKE 'agence-%@example.invalid' OR email = 'super-agences@example.invalid' OR email = 'admin-agences@example.invalid'");
        }
    }

    /** Une connexion directe à la base d'un site de test (celle de la requête en cours n'est pas touchée) */
    private function pdo(string $db): \PDO
    {
        return static::getContainer()->get(\App\Services\Platform\TenantDatabaseConnector::class)->pdo($db);
    }

    /** Un compte d'un site, avec un mot de passe ; rend son identifiant */
    private function account(string $email, array $roles, string $db, string $code): int
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Agathe')->setLastname('Agence')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $id = (int) $user->getId();
        $provider->getEntityManager()->clear();
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $id;
    }

    private function login(string $email, string $platform, string $host): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . $host . '/api/login', [], [], ['HTTP_X_TENANT_HOST' => $host, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $this->ip],
            json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => $platform]));

        return $this->client->getResponse();
    }

    /** Le jeton d'accès d'une connexion à l'espace d'administration du web : il ne vit que dans un cookie protégé, jamais dans le corps */
    private function tokenOf(Response $login, string $code): string
    {
        foreach ($login->headers->getCookies() as $cookie) {
            if ($cookie->getName() === "auth_token_$code") {
                return (string) $cookie->getValue();
            }
        }
        $this->fail('aucun jeton d\'accès dans la réponse de la connexion');
    }

    private function loginAs(array $roles): void
    {
        $email = 'agence-ecran-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Écran')->setLastname('Agences')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }

    /** @return array<string, mixed> le corps d'une réponse dont le statut est celui attendu */
    private function json(string $method, string $path, ?array $body, ?string $as, int $expected): array
    {
        $response = $this->api($method, $path, $body, $as);
        $this->assertSame($expected, $response->getStatusCode(), "$method $path : " . substr((string) $response->getContent(), 0, 500));

        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function api(string $method, string $path, ?array $body, ?string $as, string $host = MV_TEST_TENANT_HOST): Response
    {
        $this->client->getCookieJar()->clear();
        $server = ['HTTP_X_TENANT_HOST' => $host, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test'];
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token($as);
        }
        $this->client->request($method, 'https://' . $host . $path, [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    /** Jeton d'un compte du site de test : `super` (super administrateur), `admin` (administrateur du site), ou un jeton gardé par une connexion */
    private function token(string $as): string
    {
        if (isset($this->tokens[$as])) {
            return $this->tokens[$as];
        }
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $email = $as === 'super' ? 'super-agences@example.invalid' : 'admin-agences@example.invalid';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Agences')->setLastname('Test')->setRoles($as === 'super' ? ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] : ['ROLE_ADMIN'])->setPassword('inutilisable')->setIsVerified(true);
            $em->persist($user);
            $em->flush();
        }

        return $this->tokens[$as] = $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
