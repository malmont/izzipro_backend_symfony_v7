<?php

namespace App\Tests\Functional\Support;

use App\Entity\User;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformModeService;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot SUPPORT-WEB (14/11/2026, `docs/assistance.md`) : ce qu'il faut au web pour afficher l'Assistance à un compte ROLE_SUPPORT seul.
 * Plateforme « webadmin » (les rôles de « admin », la session par cookies de « web ») ; `GET /api/support/me` (le compte et l'état du
 * module) ; filtres « en retard » et « attend une réponse » avec les compteurs de la boîte ; `GET /api/support/agents` ; une commande par
 * sa référence à la création et par `GET /api/support/orders/lookup`. Le compte Assistance seul ne voit toujours rien d'autre du site.
 */
class SupportWebApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private const TAG = 'SUPWEB-TEST';
    private KernelBrowser $client;
    /** Adresse d'appel propre à chaque test : les limites de débit par adresse (connexion, mot de passe oublié) sont celles de ce test seul */
    private string $ip = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->ip = '10.85.' . random_int(1, 254) . '.' . random_int(1, 254);
        $this->clean();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(PlatformModeService::class)->setMode('demo', 'test');
        $this->clean();
        parent::tearDown();
    }

    public function testTheAdminSpaceOfTheWebOpensTheBrowserSessionForAdministrationAccountsOnly(): void
    {
        $tenant = MV_TEST_TENANT_CODE;
        $agent = $this->user(['ROLE_SUPPORT']);
        // Compte Assistance SEUL : la session du navigateur (cookies du jeton, du renouvellement, jeton anti-CSRF), sans ROLE_USER_INTERNET
        $login = $this->send('POST', '/api/login', ['username' => $agent->getEmail(), 'password' => self::PASSWORD, 'platform' => 'webadmin']);
        $this->assertSame(200, $login->getStatusCode(), (string) $login->getContent());
        $cookies = $this->cookies($login);
        $body = json_decode((string) $login->getContent(), true);
        $this->assertSame(["auth_token_$tenant", "refresh_token_$tenant", "XSRF-TOKEN_$tenant"], array_keys($cookies));
        // Le corps ne rend QUE le jeton anti-CSRF : le jeton d'accès et le jeton de renouvellement ne vivent que dans les cookies
        // protégés (HttpOnly, Secure), hors de portée d'un script de la page
        $this->assertSame(['csrf_token' => $cookies["XSRF-TOKEN_$tenant"]], $body);
        $this->assertStringNotContainsString($cookies["auth_token_$tenant"], (string) $login->getContent());
        $this->assertStringNotContainsString($cookies["refresh_token_$tenant"], (string) $login->getContent());
        $flags = [];
        foreach ($login->headers->getCookies() as $cookie) {
            $flags[$cookie->getName()] = [$cookie->isHttpOnly(), $cookie->isSecure()];
        }
        $this->assertSame(["auth_token_$tenant" => [true, true], "refresh_token_$tenant" => [true, true], "XSRF-TOKEN_$tenant" => [false, true]], $flags);
        $roles = json_decode(base64_decode(strtr(explode('.', $cookies["auth_token_$tenant"])[1], '-_', '+/')), true)['roles'];
        $this->assertSame(['ROLE_SUPPORT', 'ROLE_USER'], [...array_values(array_diff($roles, ['ROLE_USER'])), 'ROLE_USER']);
        // Par les cookies seuls : le module répond, le reste du site reste fermé
        $me = $this->send('GET', '/api/support/me', null, $cookies);
        $this->assertSame([200, true, ['ROLE_SUPPORT']], [$me->getStatusCode(), json_decode((string) $me->getContent(), true)['supportOnly'] ?? null, json_decode((string) $me->getContent(), true)['roles'] ?? null]);
        // (refus par les règles d'accès quand la route exige un rôle, par la garde de l'Assistance quand elle n'exige qu'un compte connecté)
        foreach (['/api/profile', '/api/validate-token', '/api/site/features', '/api/gestion/statistiques', '/api/boussole/company'] as $closed) {
            $r = $this->send('GET', $closed, null, $cookies);
            $this->assertSame(403, $r->getStatusCode(), $closed);
            $this->assertContains(json_decode((string) $r->getContent(), true)['code'] ?? null, ['SUPPORT_SCOPE_ONLY', 'FORBIDDEN'], $closed);
        }
        $r = $this->send('GET', '/api/boussole/company', null, $cookies);
        $this->assertSame('SUPPORT_SCOPE_ONLY', json_decode((string) $r->getContent(), true)['code'] ?? null);
        // Une écriture sans l'en-tête anti-CSRF est refusée ; avec lui, elle passe
        $ticket = ['subject' => 'Colis abîmé', 'body' => 'Le colis est arrivé ouvert.', 'customer' => ['name' => 'Cliente Test']];
        $this->assertSame(403, $this->send('POST', '/api/support/tickets', $ticket, $cookies)->getStatusCode());
        $this->assertSame(201, $this->send('POST', '/api/support/tickets', $ticket, $cookies, ['HTTP_X_XSRF_TOKEN' => $body['csrf_token']])->getStatusCode());
        // Renouvellement et déconnexion par les cookies
        $refresh = $this->send('POST', '/api/token/refresh', [], $cookies, ['HTTP_X_XSRF_TOKEN' => $body['csrf_token']]);
        $this->assertSame(200, $refresh->getStatusCode(), (string) $refresh->getContent());
        $this->assertArrayHasKey("auth_token_$tenant", $this->cookies($refresh));
        $this->assertSame(200, $this->send('POST', '/api/logout', [], $cookies)->getStatusCode());

        // Administrateur du site sans ROLE_USER_INTERNET : « webadmin » oui, « web » non (inchangé)
        $admin = $this->user(['ROLE_ADMIN']);
        $ok = $this->send('POST', '/api/login', ['username' => $admin->getEmail(), 'password' => self::PASSWORD, 'platform' => 'webadmin']);
        $this->assertSame([200, 3], [$ok->getStatusCode(), count($this->cookies($ok))]);
        $web = $this->send('POST', '/api/login', ['username' => $admin->getEmail(), 'password' => self::PASSWORD, 'platform' => 'web']);
        $this->assertSame([403, 'PLATFORM_NOT_ALLOWED'], [$web->getStatusCode(), json_decode((string) $web->getContent(), true)['code'] ?? null]);
        // Compte client : refusé sur « webadmin », aucun cookie ; « web » lui reste ouvert
        $customer = $this->user(['ROLE_USER_INTERNET']);
        $refused = $this->send('POST', '/api/login', ['username' => $customer->getEmail(), 'password' => self::PASSWORD, 'platform' => 'webadmin']);
        $this->assertSame([403, 'PLATFORM_NOT_ALLOWED', []], [$refused->getStatusCode(), json_decode((string) $refused->getContent(), true)['code'] ?? null, $this->cookies($refused)]);
        // « web » : même règle, les jetons ne sont que dans les cookies ; le corps ne rend que le jeton anti-CSRF
        $shop = $this->send('POST', '/api/login', ['username' => $customer->getEmail(), 'password' => self::PASSWORD, 'platform' => 'web']);
        $this->assertSame([200, ['csrf_token'], 3], [$shop->getStatusCode(), array_keys(json_decode((string) $shop->getContent(), true)), count($this->cookies($shop))]);
        // L'application mobile de gestion (« admin ») ne change pas : jetons dans le corps, aucun cookie, aucun jeton anti-CSRF
        $mobile = $this->send('POST', '/api/login', ['username' => $agent->getEmail(), 'password' => self::PASSWORD, 'platform' => 'admin']);
        $this->assertSame([200, [], ['token', 'refresh_token']], [$mobile->getStatusCode(), $this->cookies($mobile), array_keys(json_decode((string) $mobile->getContent(), true))]);
        // Propriétaire de la plateforme : « webadmin » lui est ouvert (code OTP imposé) ; « admin » ne l'était pas et ne l'est toujours pas
        $owner = $this->user(['ROLE_SUPER_ADMIN']);
        $otp = $this->send('POST', '/api/login', ['username' => $owner->getEmail(), 'password' => self::PASSWORD, 'platform' => 'webadmin']);
        $this->assertSame([200, true], [$otp->getStatusCode(), json_decode((string) $otp->getContent(), true)['otp_required'] ?? null]);
        $this->db()->executeStatement("INSERT INTO otp_code (id, user_otp_id, code, expiration) VALUES (nextval('otp_code_id_seq'), ?, '424242', NOW() + INTERVAL '5 minutes')", [$owner->getId()]);
        $verified = $this->send('POST', '/api/otp-verify', ['username' => $owner->getEmail(), 'otp' => '424242', 'platform' => 'webadmin']);
        $this->assertSame([200, 3], [$verified->getStatusCode(), count($this->cookies($verified))], (string) $verified->getContent());
        // Après le code aussi, le corps ne rend que le jeton anti-CSRF
        $this->assertSame(['csrf_token' => $this->cookies($verified)["XSRF-TOKEN_$tenant"]], json_decode((string) $verified->getContent(), true));
        $this->assertSame(403, $this->send('POST', '/api/login', ['username' => $owner->getEmail(), 'password' => self::PASSWORD, 'platform' => 'admin'])->getStatusCode());
        // Un compte client ne passe pas non plus à l'étape du code sur « webadmin »
        $this->db()->executeStatement("INSERT INTO otp_code (id, user_otp_id, code, expiration) VALUES (nextval('otp_code_id_seq'), ?, '424242', NOW() + INTERVAL '5 minutes')", [$customer->getId()]);
        $this->assertSame(403, $this->send('POST', '/api/otp-verify', ['username' => $customer->getEmail(), 'otp' => '424242', 'platform' => 'webadmin'])->getStatusCode());
    }

    public function testMeGivesTheAccountAndTheStateOfTheModuleAndNothingElseOfTheSite(): void
    {
        $agent = $this->user(['ROLE_SUPPORT'], 'Aline', 'Agent');
        $admin = $this->user(['ROLE_ADMIN', 'ROLE_USER_INTERNET'], 'Gabriel', 'Gérant');
        $me = $this->json('GET', '/api/support/me', null, $agent);
        $this->assertSame(['id', 'name', 'email', 'roles', 'supportOnly', 'site', 'module', 'screens', 'canEditSettings'], array_keys($me));
        $this->assertSame([(int) $agent->getId(), 'Aline Agent', $agent->getEmail(), ['ROLE_SUPPORT'], true, false, ['assistance', 'assistance-reglages']],
            [$me['id'], $me['name'], $me['email'], $me['roles'], $me['supportOnly'], $me['canEditSettings'], $me['screens']]);
        $this->assertSame([MV_TEST_TENANT_CODE, 'Tenant de test'], [$me['site']['code'], $me['site']['name']]);
        $this->assertContains($me['site']['locale'], ['fr', 'en']);
        $this->assertNotSame('', $me['site']['timezone']);
        // Module : sans le droit, en mode « demo » de la plateforme (les routes répondent quand même : le mode le dit)
        $this->assertSame(['key' => 'module.support', 'state' => 'inactive', 'endsAt' => null, 'platformMode' => 'demo'], $me['module']);
        // Essai en cours, puis droit accordé
        $rights = static::getContainer()->get(EntitlementService::class);
        $rights->trial(MV_TEST_TENANT_CODE, 'module.support', 30, 'test');
        static::getContainer()->get(TenantCacheService::class)->invalidateTenant();
        $trial = $this->json('GET', '/api/support/me', null, $agent)['module'];
        $this->assertSame(['trial', 'demo'], [$trial['state'], $trial['platformMode']]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $trial['endsAt']);
        $rights->grant(MV_TEST_TENANT_CODE, 'module.support', 'manual', null, 'test', 'test', null, null, false);
        static::getContainer()->get(TenantCacheService::class)->invalidateTenant();
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->assertSame(['key' => 'module.support', 'state' => 'active', 'endsAt' => null, 'platformMode' => 'live'], $this->json('GET', '/api/support/me', null, $agent)['module']);
        // Administrateur du site : pas « Assistance seul », il peut changer les réglages
        $boss = $this->json('GET', '/api/support/me', null, $admin);
        $this->assertSame([false, true, 'Gabriel Gérant'], [$boss['supportOnly'], $boss['canEditSettings'], $boss['name']]);
        $this->assertContains('ROLE_ADMIN', $boss['roles']);
        // Un agent qui porte AUSSI un autre rôle d'accès n'est pas « Assistance seul » (le reste du site ne lui est pas fermé par la garde)
        $mixed = $this->json('GET', '/api/support/me', null, $this->user(['ROLE_SUPPORT', 'ROLE_USER_INTERNET']));
        $this->assertSame([false, false], [$mixed['supportOnly'], $mixed['canEditSettings']]);
        // Sans jeton ; compte client
        $this->assertSame(401, $this->call('GET', '/api/support/me', null, null)->getStatusCode());
        $this->assertSame(403, $this->call('GET', '/api/support/me', null, $this->user(['ROLE_USER_INTERNET']))->getStatusCode());
    }

    public function testListFiltersOverdueAndAwaitingWithTheCountsOfTheWholeBox(): void
    {
        $agent = $this->user(['ROLE_SUPPORT']);
        $other = $this->user(['ROLE_SUPPORT']);
        $ids = [];
        foreach (['retard' => 'En retard', 'attente' => 'À répondre', 'repondu' => 'Répondu', 'ferme' => 'Fermé'] as $key => $subject) {
            $ids[$key] = $this->json('POST', '/api/support/tickets', ['subject' => self::TAG . ' ' . $subject, 'body' => 'Message.', 'customer' => ['name' => 'Cliente Test']], $agent, 201)['id'];
        }
        $db = $this->db();
        // en retard (délai dépassé, le dernier mot est au client) ; à répondre (dans le délai) ; répondu (l'équipe a eu le dernier mot) ; fermé
        $db->executeStatement("UPDATE support_ticket SET due_at = NOW() - INTERVAL '2 days' WHERE id = ?", [$ids['retard']]);
        $db->executeStatement("UPDATE support_ticket SET first_response_at = NOW() + INTERVAL '1 minute', status = 'waiting_customer', assigned_user_id = ? WHERE id = ?", [$other->getId(), $ids['repondu']]);
        $db->executeStatement("UPDATE support_ticket SET status = 'closed', closed_at = NOW(), due_at = NOW() - INTERVAL '5 days' WHERE id = ?", [$ids['ferme']]);
        $list = fn (string $query) => $this->json('GET', '/api/support/tickets?q=' . self::TAG . $query, null, $agent);
        $found = fn (array $r) => array_values(array_intersect_key(array_flip($ids), array_flip(array_column($r['items'], 'id'))));
        $all = $list('');
        $this->assertSame(['retard', 'attente', 'repondu', 'ferme'], array_keys(array_intersect($ids, array_column($all['items'], 'id'))));
        $this->assertSame(['retard'], $found($list('&overdue=1')));
        $this->assertSame(['retard', 'attente'], $found($list('&awaiting=1')));
        $this->assertSame(['retard'], $found($list('&overdue=1&awaiting=1')));
        $this->assertSame([], $found($list('&overdue=1&status=closed')), 'une demande fermée n\'est jamais en retard');
        // Les champs de chaque demande disent la même chose que les filtres
        $rows = array_column($all['items'], null, 'id');
        $this->assertSame([[true, true], [false, true], [false, false], [false, false]],
            array_map(fn (int $id) => [$rows[$id]['overdue'], $rows[$id]['awaitingReply']], array_values($ids)));
        // Compteurs : toute la boîte, quels que soient les filtres et la page
        $this->assertSame(['overdue' => 1, 'awaiting' => 2, 'unassigned' => 2], $all['counts']);
        $this->assertSame($all['counts'], $list('&overdue=1&pageSize=1')['counts']);
        // Seule la valeur 1 est admise
        foreach (['overdue=0', 'overdue=oui', 'awaiting=true'] as $bad) {
            $r = $this->call('GET', '/api/support/tickets?' . $bad, null, $agent);
            $this->assertSame([422, 'INVALID_VALUE'], [$r->getStatusCode(), json_decode((string) $r->getContent(), true)['fields'][explode('=', $bad)[0]] ?? null], $bad);
        }
    }

    public function testAgentsAreTheAccountsATicketCanBeAssignedToByNameOnly(): void
    {
        $me = $this->user(['ROLE_SUPPORT'], 'Zoé', 'Agent');
        $admin = $this->user(['ROLE_ADMIN', 'ROLE_USER_INTERNET'], 'Alain', 'Gérant');
        $unnamed = $this->user(['ROLE_SUPPORT'], '', '');
        $owner = $this->user(['ROLE_SUPER_ADMIN', 'ROLE_ADMIN'], 'Propriétaire', 'Plateforme');
        $customer = $this->user(['ROLE_USER_INTERNET'], 'Cliente', 'Test');
        $pending = $this->user(['ROLE_SUPPORT'], 'Invité', 'Nonvérifié');
        $this->db()->executeStatement('UPDATE "user" SET is_verified = false WHERE id = ?', [$pending->getId()]);
        $body = $this->call('GET', '/api/support/agents', null, $me);
        $this->assertSame(200, $body->getStatusCode());
        $this->assertStringNotContainsString('@', (string) $body->getContent(), 'aucun courriel dans la liste');
        $mine = array_column(array_filter(json_decode((string) $body->getContent(), true)['agents'], fn (array $a) => in_array($a['id'], [$me->getId(), $admin->getId(), $unnamed->getId(), $owner->getId(), $customer->getId(), $pending->getId()], true)), null, 'id');
        $this->assertSame([
            (int) $admin->getId() => ['id' => (int) $admin->getId(), 'name' => 'Alain Gérant', 'role' => 'admin', 'me' => false],
            (int) $unnamed->getId() => ['id' => (int) $unnamed->getId(), 'name' => 'Compte n° ' . $unnamed->getId(), 'role' => 'support', 'me' => false],
            (int) $me->getId() => ['id' => (int) $me->getId(), 'name' => 'Zoé Agent', 'role' => 'support', 'me' => true],
        ], $mine, 'par nom ; ni le propriétaire de la plateforme, ni un client, ni un compte non vérifié');

        // La liste et l'attribution acceptent exactement les mêmes comptes : ceux de la liste passent, les autres sont refusés
        $ticket = json_decode((string) $this->call('POST', '/api/support/tickets', ['subject' => 'Colis', 'body' => 'Message.', 'customer' => ['name' => 'Cliente']], $me)->getContent(), true)['id'];
        $assign = fn (User $to, User $by): array => [($r = $this->call('PATCH', "/api/support/tickets/$ticket", ['assignedTo' => (int) $to->getId()], $by))->getStatusCode(),
            json_decode((string) $r->getContent(), true)['fields']['assignedTo'] ?? json_decode((string) $r->getContent(), true)['assignedTo']['id'] ?? null];
        foreach ([$admin, $unnamed, $me] as $listed) {
            $this->assertSame([200, (int) $listed->getId()], $assign($listed, $me));
        }
        foreach ([$owner, $customer, $pending] as $hidden) {
            $this->assertSame([422, 'INVALID_AGENT'], $assign($hidden, $me), 'hors de la liste : jamais attribué par un autre');
        }
        // Le propriétaire de la plateforme, connecté, est dans SA liste (me : true) et peut s'attribuer la demande ; il reste absent de
        // celle des autres
        $own = array_column(json_decode((string) $this->call('GET', '/api/support/agents', null, $owner)->getContent(), true)['agents'], null, 'id');
        $this->assertSame(['id' => (int) $owner->getId(), 'name' => 'Propriétaire Plateforme', 'role' => 'admin', 'me' => true], $own[(int) $owner->getId()] ?? null);
        $this->assertSame(1, count(array_filter($own, fn (array $a) => $a['me'])), 'un seul « moi »');
        $this->assertSame([200, (int) $owner->getId()], $assign($owner, $owner));
        $this->assertSame([422, 'INVALID_AGENT'], $assign($pending, $owner));
    }

    public function testAnOrderIsGivenByItsReferenceForBothRoles(): void
    {
        $agent = $this->user(['ROLE_SUPPORT']);
        $admin = $this->user(['ROLE_ADMIN']);
        $customer = $this->user(['ROLE_USER_INTERNET'], 'Carole', 'Cliente');
        $db = $this->db();
        $order = (int) $db->fetchOne("INSERT INTO \"order\" (id, reference, order_date, order_type_id, status_id, order_source_id, user_id_id, total_amount) VALUES (nextval('order_id_seq'), ?, NOW(), 1, 3, 1, ?, 4500) RETURNING id", [self::TAG . '-A1', $customer->getId()]);
        foreach ([1, 2] as $n) {
            $db->executeStatement("INSERT INTO \"order\" (id, reference, order_date, order_type_id, status_id, order_source_id, total_amount) VALUES (nextval('order_id_seq'), ?, NOW(), 1, 3, 1, 1000)", [self::TAG . '-DOUBLE']);
        }
        $base = ['subject' => 'Article manquant', 'body' => 'Il manque un article.'];
        // Par la référence, pour l'agent comme pour l'administrateur : la commande est liée et le client repris d'elle
        foreach ([$agent, $admin] as $who) {
            $t = $this->json('POST', '/api/support/tickets', $base + ['orderReference' => ' ' . self::TAG . '-A1 '], $who, 201);
            $this->assertSame([['id' => $order, 'reference' => self::TAG . '-A1'], (int) $customer->getId(), 'Carole Cliente', $customer->getEmail()],
                [$t['order'], $t['customer']['userId'], $t['customer']['name'], $t['customer']['email']]);
        }
        // Un client donné l'emporte sur celui de la commande
        $t = $this->json('POST', '/api/support/tickets', $base + ['orderReference' => self::TAG . '-A1', 'customer' => ['name' => 'Autre Personne', 'email' => 'autre@example.invalid']], $agent, 201);
        $this->assertSame(['Autre Personne', 'autre@example.invalid'], [$t['customer']['name'], $t['customer']['email']]);
        // Refus : rien n'est créé
        $count = fn () => (int) $db->fetchOne('SELECT COUNT(*) FROM support_ticket');
        $before = $count();
        $r = $this->call('POST', '/api/support/tickets', $base + ['customer' => ['name' => 'X'], 'orderReference' => self::TAG . '-INCONNUE'], $agent);
        $this->assertSame([404, 'ORDER_REFERENCE_NOT_FOUND', self::TAG . '-INCONNUE'], [$r->getStatusCode(), json_decode((string) $r->getContent(), true)['code'], json_decode((string) $r->getContent(), true)['reference']]);
        $r = $this->call('POST', '/api/support/tickets', $base + ['customer' => ['name' => 'X'], 'orderReference' => self::TAG . '-DOUBLE'], $agent);
        $this->assertSame([409, 'ORDER_REFERENCE_AMBIGUOUS', 2], [$r->getStatusCode(), json_decode((string) $r->getContent(), true)['code'], json_decode((string) $r->getContent(), true)['count']]);
        $r = $this->call('POST', '/api/support/tickets', $base + ['orderId' => $order, 'orderReference' => self::TAG . '-A1'], $agent);
        $this->assertSame([422, 'NOT_WITH_ORDER_ID'], [$r->getStatusCode(), json_decode((string) $r->getContent(), true)['fields']['orderReference'] ?? null]);
        $r = $this->call('POST', '/api/support/tickets', $base + ['customer' => ['name' => 'X'], 'orderReference' => 12], $agent);
        $this->assertSame([422, 'INVALID_VALUE'], [$r->getStatusCode(), json_decode((string) $r->getContent(), true)['fields']['orderReference'] ?? null]);
        $this->assertSame($before, $count());
        // orderId marche comme avant
        $this->assertSame($order, $this->json('POST', '/api/support/tickets', $base + ['orderId' => $order], $agent, 201)['order']['id']);

        // Recherche d'une commande par sa référence exacte : le résumé de la fiche d'une demande, sans coût ni marge
        $found = $this->json('GET', '/api/support/orders/lookup?reference=' . self::TAG . '-A1', null, $agent);
        $this->assertSame(['order', 'orderSummary'], array_keys($found));
        $this->assertSame(['id' => $order, 'reference' => self::TAG . '-A1'], $found['order']);
        $this->assertIsArray($found['orderSummary']);
        foreach (['cost', 'purchase', 'margin', 'marge'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, json_encode($found), "« $forbidden » dans le résumé");
        }
        $this->assertSame(404, $this->call('GET', '/api/support/orders/lookup?reference=' . self::TAG, null, $agent)->getStatusCode(), 'une référence exacte, pas un début');
        $this->assertSame(409, $this->call('GET', '/api/support/orders/lookup?reference=' . self::TAG . '-DOUBLE', null, $admin)->getStatusCode());
        $r = $this->call('GET', '/api/support/orders/lookup', null, $agent);
        $this->assertSame([422, 'REQUIRED'], [$r->getStatusCode(), json_decode((string) $r->getContent(), true)['fields']['reference'] ?? null]);
        $this->assertSame(401, $this->call('GET', '/api/support/orders/lookup?reference=' . self::TAG . '-A1', null, null)->getStatusCode());
        $this->assertSame(403, $this->call('GET', '/api/support/orders/lookup?reference=' . self::TAG . '-A1', null, $customer)->getStatusCode());
    }

    public function testTheSiteAdministratorInvitesAnAgentWhoChoosesAPasswordOnce(): void
    {
        $admin = $this->user(['ROLE_ADMIN'], 'Gabriel', 'Gérant');
        $agent = $this->user(['ROLE_SUPPORT']);
        $db = $this->db();
        $email = 'invite-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $body = ['email' => strtoupper($email), 'firstName' => ' Inès ', 'lastName' => 'Invitée'];
        $code = fn (Response $r) => json_decode((string) $r->getContent(), true)['code'] ?? null;
        // Réservé à l'administrateur du site ; et seulement quand le DROIT du module est accordé (le mode « demo » ne suffit pas)
        $this->assertSame([403, 'FORBIDDEN'], [($r = $this->call('POST', '/api/support/agents', $body, $agent))->getStatusCode(), $code($r)]);
        $this->assertSame([403, 'SUPPORT_MODULE_REQUIRED'], [($r = $this->call('POST', '/api/support/agents', $body, $admin))->getStatusCode(), $code($r)]);
        $this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM "user" WHERE email = ?', [$email]));
        static::getContainer()->get(EntitlementService::class)->grant(MV_TEST_TENANT_CODE, 'module.support', 'manual', null, 'test', 'test', null, null, false);
        static::getContainer()->get(TenantCacheService::class)->invalidateTenant();
        // Champs
        foreach ([[['email' => 'pas-une-adresse'] + $body, 'email', 'INVALID_EMAIL'], [['firstName' => ''] + $body, 'firstName', 'REQUIRED'], [['locale' => 'de'] + $body, 'locale', 'INVALID_LOCALE']] as [$bad, $field, $expected]) {
            $r = $this->call('POST', '/api/support/agents', $bad, $admin);
            $this->assertSame([422, $expected], [$r->getStatusCode(), json_decode((string) $r->getContent(), true)['fields'][$field] ?? null], $field);
        }
        // Un compte qui existe déjà n'est jamais transformé en agent
        $this->assertSame([409, 'ACCOUNT_EXISTS'], [($r = $this->call('POST', '/api/support/agents', ['email' => $admin->getEmail()] + $body, $admin))->getStatusCode(), $code($r)]);

        // Invitation : un compte ROLE_SUPPORT seul, pas encore vérifié ; le jeton n'est rendu nulle part à l'administrateur
        $invited = $this->json('POST', '/api/support/agents', $body, $admin, 201);
        $row = $db->fetchAssociative('SELECT id, roles::text AS roles, is_verified, signup_channel, reset_token, reset_token_expires_at, firstname FROM "user" WHERE email = ?', [$email]);
        $this->assertSame(['["ROLE_SUPPORT"]', false, 'support_invite', 'Inès'], [str_replace(' ', '', (string) $row['roles']), (bool) $row['is_verified'], $row['signup_channel'], $row['firstname']]);
        $this->assertSame(['id' => (int) $row['id'], 'name' => 'Inès Invitée', 'email' => $email, 'role' => 'support', 'pending' => true], $invited['agent']);
        $this->assertSame([true, 48], [$invited['invitation']['emailSent'], $invited['invitation']['validHours']]);
        $token = (string) $row['reset_token'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertStringNotContainsString($token, json_encode($invited));
        $hours = (strtotime((string) $row['reset_token_expires_at']) - time()) / 3600;
        $this->assertTrue($hours > 47.9 && $hours <= 48.01, "validité : $hours h");
        $this->assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'user_team' AND action = 'grant' AND resource_id = ?", [(string) $row['id']]));
        // Le courriel : une seule adresse, l'écran d'activation du web, avec le jeton dans le FRAGMENT (jamais dans les paramètres : un
        // fragment n'est envoyé à aucun serveur) ; ni mot de passe, ni autre lien
        $mails = array_values(array_filter($this->getMailerMessages(), fn ($m) => $m->getTo()[0]->getAddress() === $email));
        $this->assertCount(1, $mails);
        $html = (string) $mails[0]->getHtmlBody();
        $link = 'https://' . MV_TEST_TENANT_HOST . '/admin/activer#token=' . $token;
        $this->assertStringContainsString('href="' . $link . '"', $html);
        $this->assertStringNotContainsString('?token', $html);
        $this->assertSame(1, substr_count($html, 'href="http'), 'un seul lien dans le courriel');
        $this->assertStringContainsString('Gabriel Gérant', $html);
        $this->assertStringContainsString('48 heures', $html);
        // Tant que le mot de passe n'est pas choisi : aucun accès, absent de la liste d'attribution, visible de l'administrateur en attente
        $this->assertNotSame(200, $this->send('POST', '/api/login', ['username' => $email, 'password' => 'nimporte-lequel', 'platform' => 'webadmin'])->getStatusCode());
        $this->assertNotContains((int) $row['id'], array_column($this->json('GET', '/api/support/agents', null, $agent)['agents'], 'id'));
        $this->assertArrayNotHasKey('pending', $this->json('GET', '/api/support/agents', null, $agent), 'un agent ne voit pas les invitations');
        $pending = array_column($this->json('GET', '/api/support/agents', null, $admin)['pending'], null, 'id')[(int) $row['id']];
        $this->assertSame(['Inès Invitée', false], [$pending['name'], $pending['expired']]);

        // Le web vérifie le lien sans le consommer, puis l'agent choisit son mot de passe : une seule fois
        $check = $this->send('POST', '/api/password-reset/verify', ['token' => $token]);
        $this->assertSame([200, true, 'invitation'], [$check->getStatusCode(), json_decode((string) $check->getContent(), true)['valid'], json_decode((string) $check->getContent(), true)['kind']]);
        $checked = json_decode((string) $check->getContent(), true);
        $this->assertSame(['valid', 'kind', 'expiresAt', 'siteName', 'emailMasked', 'passwordMinLength'], array_keys($checked));
        $this->assertSame(['i***@e***.invalid', 8], [$checked['emailMasked'], $checked['passwordMinLength']]);
        $this->assertStringNotContainsString($email, (string) $check->getContent(), 'jamais l\'adresse entière');
        $this->assertNotNull($db->fetchOne('SELECT reset_token FROM "user" WHERE id = ?', [$row['id']]), 'la vérification ne consomme pas le jeton');
        $this->assertSame([400, 'RESET_LINK_INVALID'], [($r = $this->send('POST', '/api/password-reset/verify', ['token' => str_repeat('a', 64)]))->getStatusCode(), $code($r)]);
        $this->assertSame(200, $this->send('POST', '/api/password-reset/confirm', ['token' => $token, 'password' => self::PASSWORD])->getStatusCode());
        $this->assertTrue((bool) $db->fetchOne('SELECT is_verified FROM "user" WHERE id = ?', [$row['id']]));
        foreach (['/api/password-reset/verify' => ['token' => $token], '/api/password-reset/confirm' => ['token' => $token, 'password' => self::PASSWORD . '2']] as $path => $payload) {
            $this->assertSame([400, 'RESET_LINK_INVALID'], [($r = $this->send('POST', $path, $payload))->getStatusCode(), $code($r)], "$path : le lien ne sert qu'une fois");
        }
        // Il se connecte à l'espace d'administration du web, n'ouvre que l'Assistance, et peut recevoir une demande
        $login = $this->send('POST', '/api/login', ['username' => $email, 'password' => self::PASSWORD, 'platform' => 'webadmin']);
        $this->assertSame(200, $login->getStatusCode(), (string) $login->getContent());
        $me = $this->send('GET', '/api/support/me', null, $this->cookies($login));
        $this->assertSame([true, ['ROLE_SUPPORT']], [json_decode((string) $me->getContent(), true)['supportOnly'], json_decode((string) $me->getContent(), true)['roles']]);
        $this->assertContains((int) $row['id'], array_column($this->json('GET', '/api/support/agents', null, $agent)['agents'], 'id'));
        $this->assertSame([409, 'AGENT_ALREADY_ACTIVE'], [($r = $this->call('POST', '/api/support/agents/' . $row['id'] . '/invitation', [], $admin))->getStatusCode(), $code($r)]);

        // Un lien expiré : refus neutre ; l'administrateur relance, l'ancien jeton ne vaut plus
        $second = $this->json('POST', '/api/support/agents', ['email' => 'invite2-' . bin2hex(random_bytes(4)) . '@example.invalid', 'firstName' => 'Paul', 'lastName' => 'Second'], $admin, 201)['agent'];
        $old = (string) $db->fetchOne('SELECT reset_token FROM "user" WHERE id = ?', [$second['id']]);
        $db->executeStatement("UPDATE \"user\" SET reset_token_expires_at = NOW() - INTERVAL '1 minute' WHERE id = ?", [$second['id']]);
        $this->assertSame([400, 'RESET_LINK_INVALID'], [($r = $this->send('POST', '/api/password-reset/confirm', ['token' => $old, 'password' => self::PASSWORD]))->getStatusCode(), $code($r)]);
        $this->assertSame([400, 'RESET_LINK_INVALID'], [($r = $this->send('POST', '/api/password-reset/verify', ['token' => $old]))->getStatusCode(), $code($r)], 'un lien expiré : le même refus à la vérification');
        $this->assertTrue(array_column($this->json('GET', '/api/support/agents', null, $admin)['pending'], null, 'id')[$second['id']]['expired']);
        $this->assertSame(403, $this->call('POST', '/api/support/agents/' . $second['id'] . '/invitation', [], $agent)->getStatusCode());
        $again = $this->json('POST', '/api/support/agents/' . $second['id'] . '/invitation', [], $admin);
        $new = (string) $db->fetchOne('SELECT reset_token FROM "user" WHERE id = ?', [$second['id']]);
        $this->assertNotSame($old, $new);
        $this->assertTrue($again['invitation']['emailSent']);
        $this->assertSame(400, $this->send('POST', '/api/password-reset/confirm', ['token' => $old, 'password' => self::PASSWORD])->getStatusCode());

        // Retrait : réservé à l'administrateur, pour un compte Assistance seul ; sessions closes, demandes rendues sans agent, compte gardé
        $ticket = $this->json('POST', '/api/support/tickets', ['subject' => 'À reprendre', 'body' => 'Message.', 'customer' => ['name' => 'Cliente Test']], $admin, 201)['id'];
        $this->json('PATCH', '/api/support/tickets/' . $ticket, ['assignedTo' => (int) $row['id']], $admin);
        $this->assertSame(403, $this->call('DELETE', '/api/support/agents/' . $row['id'], null, $agent)->getStatusCode());
        $this->assertSame([404, 'AGENT_NOT_FOUND'], [($r = $this->call('DELETE', '/api/support/agents/' . $admin->getId(), null, $admin))->getStatusCode(), $code($r)], 'un administrateur n\'est pas un agent que cette route gère');
        $this->assertSame(['id' => (int) $row['id'], 'unassigned' => 1], $this->json('DELETE', '/api/support/agents/' . $row['id'], null, $admin));
        $this->assertSame(['[]', null], [str_replace(' ', '', (string) $db->fetchOne('SELECT roles::text FROM "user" WHERE id = ?', [$row['id']])), $db->fetchOne('SELECT assigned_user_id FROM support_ticket WHERE id = ?', [$ticket])]);
        $this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM refresh_tokens WHERE username = ?', [$email]));
        $this->assertSame(403, $this->send('POST', '/api/login', ['username' => $email, 'password' => self::PASSWORD, 'platform' => 'webadmin'])->getStatusCode());
        $this->assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'user_team' AND action = 'revoke' AND resource_id = ?", [(string) $row['id']]));
    }

    public function testAForgottenPasswordAskedFromTheAdminSpaceOpensItsOwnScreenWithTheTokenInTheFragment(): void
    {
        $agent = $this->user(['ROLE_SUPPORT']);
        $customer = $this->user(['ROLE_USER_INTERNET']);
        $db = $this->db();
        $linkOf = function (User $user, array $body) use ($db): array {
            $before = count($this->getMailerMessages());
            $r = $this->send('POST', '/api/password-reset/request', ['email' => $user->getEmail()] + $body);
            $this->assertSame([200, ['message' => 'Link sent if email exists.']], [$r->getStatusCode(), json_decode((string) $r->getContent(), true)], 'réponse neutre, inchangée');
            $mails = array_values(array_filter($this->getMailerMessages(), fn ($m) => $m->getTo()[0]->getAddress() === $user->getEmail()));
            $this->assertNotSame([], $mails, 'un courriel pour ' . json_encode($body));
            preg_match('/href="([^"]+)"/', (string) end($mails)->getHtmlBody(), $m);

            return [html_entity_decode($m[1] ?? ''), (string) $db->fetchOne('SELECT reset_token FROM "user" WHERE id = ?', [$user->getId()])];
        };
        // Demande venue de l'espace d'administration, pour un compte qui y a accès : son écran, le jeton dans le FRAGMENT, jamais après « ? »
        [$link, $token] = $linkOf($agent, ['platform' => 'webadmin']);
        $this->assertSame('https://' . MV_TEST_TENANT_HOST . '/admin/activer#token=' . $token, $link);
        $this->assertStringNotContainsString('?', $link);
        $check = json_decode((string) $this->send('POST', '/api/password-reset/verify', ['token' => $token])->getContent(), true);
        $this->assertSame([true, 'reset'], [$check['valid'], $check['kind']]);
        $minutes = (strtotime((string) $db->fetchOne('SELECT reset_token_expires_at FROM "user" WHERE id = ?', [$agent->getId()])) - time()) / 60;
        $this->assertTrue($minutes > 58 && $minutes <= 60.1, "validité : $minutes min");
        $this->assertSame(200, $this->send('POST', '/api/password-reset/confirm', ['token' => $token, 'password' => self::PASSWORD . '-neuf'])->getStatusCode());
        $this->assertSame(200, $this->send('POST', '/api/login', ['username' => $agent->getEmail(), 'password' => self::PASSWORD . '-neuf', 'platform' => 'webadmin'])->getStatusCode());
        // Sans le champ, ou pour un compte client même avec le champ : la page du site, comme avant (jeton dans les paramètres)
        foreach ([[$agent, []], [$customer, ['platform' => 'webadmin']], [$customer, []]] as [$who, $body]) {
            [$link, $token] = $linkOf($who, $body);
            $this->assertStringStartsWith('https://' . MV_TEST_TENANT_HOST . '/reset-password?token=' . $token, $link, json_encode($body));
            $this->assertStringNotContainsString('/admin/activer', $link);
        }
        // Adresse inconnue : la même réponse neutre, aucun courriel
        $before = count($this->getMailerMessages());
        $r = $this->send('POST', '/api/password-reset/request', ['email' => 'personne-' . bin2hex(random_bytes(3)) . '@example.invalid', 'platform' => 'webadmin']);
        $this->assertSame([200, ['message' => 'Link sent if email exists.']], [$r->getStatusCode(), json_decode((string) $r->getContent(), true)]);
    }

    // ----- aides -----

    /** @return array<string, string> cookies posés par la réponse */
    private function cookies(Response $response): array
    {
        $out = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $out[$cookie->getName()] = (string) $cookie->getValue();
        }

        return $out;
    }

    private function send(string $method, string $path, ?array $body, array $cookies = [], array $server = []): Response
    {
        $this->client->getCookieJar()->clear();
        foreach ($cookies as $name => $value) {
            $this->client->getCookieJar()->set(new Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server + ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $this->ip],
            $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function call(string $method, string $path, ?array $body, ?User $as): Response
    {
        $token = $as === null ? null : static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->provider()->getEntityManager()->find(User::class, $as->getId()));

        return $this->send($method, $path, $body, [], $token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    private function json(string $method, string $path, ?array $body, ?User $as, int $expected = 200): array
    {
        $response = $this->call($method, $path, $body, $as);
        $this->assertSame($expected, $response->getStatusCode(), "$method $path : " . substr((string) $response->getContent(), 0, 500));

        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function user(array $roles, string $first = 'Compte', string $last = 'Essai'): User
    {
        $email = 'supweb-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $provider = $this->provider();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname($first)->setLastname($last)->setRoles($roles)->setIsVerified(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }

    private function provider(): TenantEntityManagerProvider
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider;
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->provider()->getEntityManager()->getConnection();
    }

    private function clean(): void
    {
        $db = $this->db();
        foreach (['support_attachment', 'support_message', 'support_ticket', 'support_setting'] as $t) {
            $db->executeStatement("DELETE FROM $t");
        }
        $db->executeStatement("DELETE FROM content_audit_log WHERE resource IN ('support_ticket', 'user_team')");
        $db->executeStatement('DELETE FROM "order" WHERE reference LIKE ?', [self::TAG . '%']);
        $db->executeStatement('UPDATE entreprise SET is_support_active = false');
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $master->prepare("DELETE FROM tenant_entitlement_events WHERE entitlement_id IN (SELECT id FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'module.support')")->execute([MV_TEST_TENANT_CODE]);
        $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'module.support'")->execute([MV_TEST_TENANT_CODE]);
    }
}
