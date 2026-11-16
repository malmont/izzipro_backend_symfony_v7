<?php

namespace App\Tests\Functional\Platform;

use App\Entity\User;
use App\Services\Agency\AgencyEntitlements;
use App\Services\Agency\AgencySiteCode;
use App\Services\Agency\AgencySiteRequestService;
use App\Services\Agency\Message\BuildAgencySiteMessage;
use App\Services\Agency\Message\ResendAgencySiteInvitationMessage;
use App\Services\Platform\EntitlementService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Agences, lot AG-2 (15/11/2026, `docs/agences-et-import.md` § 8) : création d'un site par l'agence, par une file. Le site est
 * VRAIMENT fabriqué (copie du modèle de site des tests, `SITE_TEMPLATE_TEST_DB`, jamais du site de référence) : la fiche, les droits
 * de source « agence », le nettoyage de ce qu'un site neuf ne doit pas hériter, l'invitation de l'administrateur, puis la suspension
 * en cascade. Les sites créés ici s'appellent `agtest-…` et leurs bases `db_agtest_…` : elles sont supprimées à la fin de chaque essai.
 */
class AgencySitesTest extends WebTestCase
{
    private const PASSWORD = 'Un-mot-de-passe-solide-42';

    private KernelBrowser $client;
    private \PDO $master;
    private string $tag;
    /** @var array<string, string> jetons par clé */
    private array $tokens = [];
    private int $agency = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->tag = bin2hex(random_bytes(3));
        $this->clean();
        $this->agency = (int) $this->json('POST', '/api/platform/agencies', ['code' => 'agence-sites', 'name' => 'Agence des Sites', 'homeTenantCode' => MV_TEST_TENANT_CODE, 'test' => true], 'super', 201)['agency']['id'];
        $this->account('agence-resp@example.invalid', ['ROLE_ADMIN', 'ROLE_AGENCY'], 'manager');
        $this->account('agence-collab@example.invalid', ['ROLE_AGENCY_MEMBER'], 'member');
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    public function testTheManagerAsksForASiteWhichIsBuiltCleanedGivenItsRightsAndItsAdministratorIsInvited(): void
    {
        $code = 'agtest-' . $this->tag;
        $db = AgencySiteCode::database($code);

        // Le nom du site : forme, mots réservés, noms déjà pris (un site, une BASE qui existe sans être un site, un domaine)
        $reason = fn (string $c): ?string => $this->json('GET', '/api/agency/sites/availability?code=' . rawurlencode($c), null, 'member', 200)['reason'];
        $this->assertSame(['INVALID', 'INVALID', 'INVALID', 'INVALID', 'RESERVED', 'RESERVED', 'TAKEN', 'TAKEN', 'TAKEN', null],
            [$reason('ab'), $reason('1site'), $reason('mon--site'), $reason('mon_site'), $reason('admin'), $reason('gmasuite'), $reason(MV_TEST_TENANT2_CODE), $reason('mv-contract-test'), $reason('site-template-test'), $reason($code)]);
        $this->assertSame(['code' => $code, 'available' => true, 'reason' => null], $this->json('GET', '/api/agency/sites/availability?code=' . strtoupper($code), null, 'manager', 200));

        // Qui demande : le responsable seulement ; ni le collaborateur, ni un administrateur de site, ni un anonyme
        $body = ['code' => $code, 'name' => 'Boutique de Zoé', 'adminEmail' => "Zoe-{$this->tag}@Example.invalid", 'adminFirstName' => 'Zoé', 'locale' => 'fr', 'country' => 'CA', 'province' => 'QC', 'currency' => 'CAD', 'timezone' => 'America/Toronto'];
        $this->assertSame('AGENCY_MANAGER_ONLY', $this->json('POST', '/api/agency/sites', $body, 'member', 403)['code']);
        $this->assertSame(403, $this->api('POST', '/api/agency/sites', $body, 'admin')->getStatusCode());
        $this->assertSame(401, $this->api('POST', '/api/agency/sites', $body, null)->getStatusCode());
        // Champs contrôlés ; le courriel de l'administrateur est obligatoire
        $errors = $this->json('POST', '/api/agency/sites', ['code' => 'A', 'name' => '<b>', 'locale' => 'de', 'country' => 'Canada', 'timezone' => 'Lune/Base', 'modules' => ['ai.full'], 'adminFirstName' => str_repeat('a', 101)], 'manager', 422)['errors'];
        $this->assertSame(['code', 'name', 'adminEmail', 'adminFirstName', 'locale', 'country', 'timezone', 'modules'], array_column($errors, 'path'));
        $this->assertSame('AGENCY_SITE_CODE_RESERVED', $this->json('POST', '/api/agency/sites', ['code' => 'demo'] + $body, 'manager', 409)['code']);
        $this->assertSame('AGENCY_SITE_CODE_TAKEN', $this->json('POST', '/api/agency/sites', ['code' => 'mv-contract-test'] + $body, 'manager', 409)['code']);
        $this->assertSame(0, (int) $this->master->query('SELECT COUNT(*) FROM agency_site_requests')->fetchColumn(), 'un refus n\'écrit aucune demande');

        // La demande : réponse immédiate, « en attente » ; elle retient une place et le nom ; le worker « media » est prévenu
        $request = $this->json('POST', '/api/agency/sites', $body, 'manager', 202)['request'];
        $id = (int) $request['id'];
        $this->assertSame([$code, 'Boutique de Zoé', "zoe-{$this->tag}@example.invalid", 'pending', null, null, null], [$request['siteCode'], $request['name'], $request['adminEmail'], $request['status'], $request['errorCode'], $request['site'], $request['invitation']]);
        $sent = static::getContainer()->get('messenger.transport.media')->getSent();
        $this->assertCount(1, $sent);
        $this->assertEquals(new BuildAgencySiteMessage($id), $sent[0]->getMessage());
        $this->assertSame('TAKEN', $reason($code));
        $this->assertSame('AGENCY_SITE_CODE_TAKEN', $this->json('POST', '/api/agency/sites', $body, 'manager', 409)['code']);
        $me = $this->json('GET', '/api/agency/me', null, 'member', 200)['agency'];
        $this->assertSame([0, 1, 3, 5], [$me['sitesCount'], $me['sitesUsed'], $me['maxSites'], $me['dailySiteRequests']]);
        $this->assertSame(['sites' => [], 'maxSites' => 3, 'used' => 0, 'pending' => 1], $this->json('GET', '/api/agency/sites', null, 'member', 200));
        $this->assertFalse($this->databaseExists($db), 'rien n\'est fabriqué dans la requête');

        // Le worker fabrique le site
        $this->service()->process($id);
        $mails = self::getMailerMessages();
        $done = $this->json('GET', "/api/agency/site-requests/$id", null, 'member', 200)['request'];
        $this->assertSame(['ready', null, [], $code, 'Boutique de Zoé'], [$done['status'], $done['errorCode'], $done['warnings'], $done['site']['code'], $done['site']['name']]);
        $host = (string) $done['site']['host'];
        $this->assertStringStartsWith($code . '.', $host);
        $this->assertSame([[$code, $db]], $this->master->query("SELECT code, dbname FROM tenants WHERE code = '$code'")->fetchAll(\PDO::FETCH_NUM));
        $this->assertTrue($this->databaseExists($db));
        $this->assertSame([['created', 'agence-resp@example.invalid']], $this->master->query("SELECT origin, attached_by FROM agency_sites WHERE tenant_code = '$code' AND detached_at IS NULL")->fetchAll(\PDO::FETCH_NUM));
        $list = $this->json('GET', '/api/agency/sites', null, 'member', 200);
        $this->assertSame([[$code], ['created'], 1, 0], [array_column($list['sites'], 'code'), array_column($list['sites'], 'origin'), $list['used'], $list['pending']]);
        $this->assertSame([$id], array_column($this->json('GET', '/api/agency/site-requests', null, 'member', 200)['requests'], 'id'));

        // Ce que le site neuf n'hérite PAS du modèle : ni compte autre que celui de la plateforme, ni compte Boussole, ni serveur de
        // courriel, ni fiche d'un autre, ni chiffres, ni adresse d'espace d'administration
        $site = $this->pdo($db);
        $one = fn (string $sql) => $site->query($sql)->fetchColumn();
        $this->assertSame([0, 0, 0, 0, 0], [(int) $one('SELECT COUNT(*) FROM esg_user'), (int) $one('SELECT COUNT(*) FROM esg_company'), (int) $one('SELECT COUNT(*) FROM gestion_stock_snapshot'),
            (int) $one('SELECT COUNT(*) FROM refresh_tokens'), (int) $one('SELECT COUNT(*) FROM email_configuration WHERE smtp_host IS NOT NULL OR smtp_password IS NOT NULL')]);
        $this->assertSame([['plateforme@example.invalid', '["ROLE_ADMIN","ROLE_SUPER_ADMIN"]'], ["zoe-{$this->tag}@example.invalid", '["ROLE_ADMIN"]']],
            array_map(static fn (array $r): array => [$r[0], str_replace(' ', '', $r[1])], $site->query('SELECT email, roles::text FROM "user" ORDER BY id')->fetchAll(\PDO::FETCH_NUM)));
        // La fiche du site : la sienne
        $sheet = $site->query('SELECT name, email, tel, website, ein, admin_space_url, locale, currency, pos_timezone FROM entreprise')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame([['name' => 'Boutique de Zoé', 'email' => "zoe-{$this->tag}@example.invalid", 'tel' => null, 'website' => 'https://' . $host, 'ein' => null, 'admin_space_url' => null, 'locale' => 'fr', 'currency' => 'CAD', 'pos_timezone' => 'America/Toronto']], $sheet);
        $this->assertSame([['CA', 'QC', '']], $site->query('SELECT country, state, street1 FROM address_entreprise')->fetchAll(\PDO::FETCH_NUM));
        // la fiche est une fiche NEUVE, pas celle du modèle réécrite : ce qui dépend de la fiche d'un autre (réseaux, réservations) ne passe pas
        $this->assertSame([1, 0], [(int) $one('SELECT COUNT(*) FROM entreprise'), (int) $one('SELECT COUNT(*) FROM reservation')]);
        $this->assertNotSame((int) $this->pdo(SITE_TEMPLATE_TEST_DB)->query('SELECT id FROM entreprise')->fetchColumn(), (int) $one('SELECT id FROM entreprise'));
        $this->assertSame(["zoe-{$this->tag}@example.invalid"], $site->query('SELECT from_email FROM email_configuration')->fetchAll(\PDO::FETCH_COLUMN), 'l\'expéditeur des courriels est posé d\'après la fiche du site');

        // Les droits de source « agence » : les modules et les applications, pas l'IA ; projetés sur la fiche du site
        $features = AgencyEntitlements::features();
        $this->assertCount(10, $features);
        $this->assertSame([], array_values(array_filter($features, static fn (string $k): bool => str_starts_with($k, 'ai.'))));
        $rights = $this->master->query("SELECT feature_key, source, status, reference FROM tenant_entitlements WHERE tenant_code = '$code' AND source = 'agency' ORDER BY feature_key")->fetchAll(\PDO::FETCH_ASSOC);
        $sorted = $features;
        sort($sorted);
        $this->assertSame([$sorted, ['agency'], ['active'], ['agency:' . $this->agency]], [array_column($rights, 'feature_key'), array_values(array_unique(array_column($rights, 'source'))), array_values(array_unique(array_column($rights, 'status'))), array_values(array_unique(array_column($rights, 'reference')))]);
        $this->assertSame($features, $done['modules']['opened']);
        $this->assertSame([true, true, true, false], $site->query('SELECT is_landing_page_active, is_boutique_active, is_pos_active, is_ai_full_active FROM entreprise')->fetch(\PDO::FETCH_NUM));

        // L'invitation : un compte d'administrateur du site neuf, inutilisable tant que le mot de passe n'est pas choisi ; un courriel, un lien
        $invited = $site->query("SELECT is_verified, signup_channel, reset_token, reset_token_expires_at FROM \"user\" WHERE email = 'zoe-{$this->tag}@example.invalid'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame([false, 'admin_invite', 64], [(bool) $invited['is_verified'], $invited['signup_channel'], strlen((string) $invited['reset_token'])]);
        $days = (new \DateTimeImmutable())->diff(new \DateTimeImmutable((string) $invited['reset_token_expires_at']))->days;
        $this->assertContains($days, [6, 7], 'le lien vaut 7 jours');
        $this->assertSame(['sent' => true, 'reason' => null], ['sent' => $done['invitation']['sent'], 'reason' => $done['invitation']['reason']]);
        $this->assertCount(1, $mails);
        $mail = $mails[0];
        $this->assertSame(["zoe-{$this->tag}@example.invalid"], array_map(static fn ($a) => $a->getAddress(), $mail->getTo()));
        $this->assertSame('Boutique de Zoé : votre accès d\'administrateur', $mail->getSubject());
        $html = (string) $mail->getHtmlBody();
        $this->assertStringContainsString('Bonjour Zoé,', $html);
        $this->assertStringContainsString('Agence des Sites a créé le site Boutique de Zoé pour vous', $html);
        $this->assertStringContainsString('https://' . $host . '/admin/activer#token=' . $invited['reset_token'], $html);
        $this->assertStringNotContainsString($invited['reset_token'], json_encode($done), 'le jeton n\'est jamais rendu à l\'agence');
        $journal = $this->master->query('SELECT event FROM agency_events WHERE agency_id = ' . $this->agency . " AND event LIKE 'site_%' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['site_requested', 'site_created', 'site_admin_invited'], $journal);
        $this->assertStringNotContainsString('@', (string) $this->master->query("SELECT string_agg(payload::text, ' ') FROM agency_events WHERE event LIKE 'site_%'")->fetchColumn(), 'le journal ne porte pas l\'adresse de l\'administrateur');

        // La personne invitée ne peut pas entrer avant d'avoir choisi son mot de passe ; puis elle administre SON site, et lui seul
        $this->assertNotSame(200, $this->login("zoe-{$this->tag}@example.invalid", 'webadmin', $host)->getStatusCode());
        $this->assertSame(200, $this->api('POST', '/api/password-reset/confirm', ['token' => $invited['reset_token'], 'password' => self::PASSWORD], null, $host)->getStatusCode());
        $this->assertTrue((bool) $this->pdo($db)->query("SELECT is_verified FROM \"user\" WHERE email = 'zoe-{$this->tag}@example.invalid'")->fetchColumn());
        $login = $this->login("zoe-{$this->tag}@example.invalid", 'webadmin', $host);
        $this->assertSame(200, $login->getStatusCode(), (string) $login->getContent());
        $this->assertNotSame(200, $this->login("zoe-{$this->tag}@example.invalid", 'webadmin', MV_TEST_TENANT_HOST)->getStatusCode(), 'ce compte n\'existe pas sur le site d\'attache de l\'agence');

        // Renvoyer l'invitation : par le responsable, fait par le worker ; un compte déjà activé ne reçoit plus rien
        $this->assertSame('AGENCY_MANAGER_ONLY', $this->json('POST', "/api/agency/sites/$code/invitation", null, 'member', 403)['code']);
        $this->json('POST', "/api/agency/sites/$code/invitation", null, 'manager', 202);
        $resend = static::getContainer()->get('messenger.transport.media')->getSent();
        $this->assertEquals(new ResendAgencySiteInvitationMessage($id, 'agence-resp@example.invalid'), end($resend)->getMessage());
        $this->service()->resendInvitation($id, 'agence-resp@example.invalid');
        $this->assertCount(0, self::getMailerMessages());
        $this->assertSame(['sent' => false, 'reason' => 'ALREADY_ACTIVE', 'expiresAt' => null], $this->json('GET', "/api/agency/site-requests/$id", null, 'manager', 200)['request']['invitation']);

        // Une demande d'une autre agence n'est pas lisible ; un site inconnu non plus
        $this->assertSame('AGENCY_REQUEST_NOT_FOUND', $this->json('GET', '/api/agency/site-requests/999999', null, 'manager', 404)['code']);
        $other = (int) $this->master->query("INSERT INTO agencies (code, name, home_tenant_code, status, max_sites) VALUES ('agence-autre', 'Autre agence', 'agfict-attache', 'trial', 3) RETURNING id")->fetchColumn();
        $foreign = (int) $this->master->query("INSERT INTO agency_site_requests (agency_id, tenant_code, name, admin_email) VALUES ($other, 'agtest-ailleurs', 'Site d''une autre agence', 'ailleurs@example.invalid') RETURNING id")->fetchColumn();
        $this->assertSame('AGENCY_REQUEST_NOT_FOUND', $this->json('GET', "/api/agency/site-requests/$foreign", null, 'manager', 404)['code']);
        $this->assertNotContains($foreign, array_column($this->json('GET', '/api/agency/site-requests', null, 'manager', 200)['requests'], 'id'));
        $this->assertSame('AGENCY_SITE_NOT_ATTACHED', $this->json('GET', '/api/agency/sites/agtest-inconnu/modules', null, 'manager', 404)['code']);
    }

    public function testTheAgencyOpensAndClosesModulesOfItsCreatedSitesAndASuspensionCascadesAndComesBack(): void
    {
        $code = 'agtest-' . $this->tag;
        $db = AgencySiteCode::database($code);
        // L'agence désigne comme administrateur une adresse qui est DÉJÀ celle d'un compte du site neuf (ici le compte de la plateforme) :
        // le site est créé, mais ce compte n'est jamais transformé, ne reçoit ni lien ni courriel, même à la demande d'un renvoi
        $id = (int) $this->json('POST', '/api/agency/sites', ['code' => $code, 'name' => 'Atelier Rémi', 'adminEmail' => 'plateforme@example.invalid', 'modules' => ['module.landing', 'module.boutique', 'module.pos', 'module.support', 'app.management']], 'manager', 202)['request']['id'];
        $this->service()->process($id);
        $made = $this->json('GET', "/api/agency/site-requests/$id", null, 'manager', 200)['request'];
        $this->assertSame(['ready', ['sent' => false, 'reason' => 'ACCOUNT_EXISTS', 'expiresAt' => null]], [$made['status'], $made['invitation']]);
        $this->assertCount(0, self::getMailerMessages());
        $account = fn (): array => $this->pdo($db)->query("SELECT COUNT(*), MIN(roles::text), BOOL_OR(reset_token IS NOT NULL), MIN(password) FROM \"user\" WHERE email = 'plateforme@example.invalid'")->fetch(\PDO::FETCH_NUM);
        $untouched = $account();
        $this->assertSame([1, false, 'inutilisable'], [$untouched[0], $untouched[2], $untouched[3]]);
        $this->json('POST', "/api/agency/sites/$code/invitation", null, 'manager', 202);
        $this->service()->resendInvitation($id, 'agence-resp@example.invalid');
        $this->assertSame(['sent' => false, 'reason' => 'NOT_FOUND', 'expiresAt' => null], $this->json('GET', "/api/agency/site-requests/$id", null, 'manager', 200)['request']['invitation']);
        $this->assertSame([$untouched, 0], [$account(), count(self::getMailerMessages())], 'un compte qui n\'est pas né de l\'invitation ne reçoit jamais de lien');
        $flags = fn (): array => $this->pdo($db)->query('SELECT is_landing_page_active, is_boutique_active, is_pos_active, is_support_active, is_management_app_active, is_boussole_esg_active FROM entreprise')->fetch(\PDO::FETCH_NUM);
        $this->assertSame([true, true, true, true, true, false], $flags(), 'seuls les modules demandés sont ouverts');
        $modules = fn (string $as = 'member'): array => array_column($this->json('GET', "/api/agency/sites/$code/modules", null, $as, 200)['modules'], null, 'key');
        $state = fn (string $key): array => [$modules()[$key]['enabled'], $modules()[$key]['byAgency'], $modules()[$key]['status']];
        $this->assertCount(10, $modules());
        $this->assertSame([[true, true, 'active'], [false, false, null], 'Boutique'], [$state('module.boutique'), $state('module.boussole'), $modules()['module.boutique']['label']]);

        // Ouvrir, fermer : le responsable seulement ; rien pour un module inconnu ou pour l'IA
        $put = fn (string $feature, mixed $enabled, string $as, int $status): array => $this->json('PUT', "/api/agency/sites/$code/modules/$feature", ['enabled' => $enabled], $as, $status);
        $this->assertSame('AGENCY_MANAGER_ONLY', $put('module.boussole', true, 'member', 403)['code']);
        $this->assertSame('enabled', $put('module.boussole', 'oui', 'manager', 422)['errors'][0]['path']);
        $this->assertSame('AGENCY_MODULE_UNKNOWN', $put('ai.full', true, 'manager', 404)['code']);
        $opened = array_column($put('module.boussole', true, 'manager', 200)['modules'], null, 'key');
        $this->assertSame([true, true], [$opened['module.boussole']['enabled'], $opened['module.boussole']['byAgency']]);
        $this->assertTrue($flags()[5]);
        $events = fn (): array => $this->master->query('SELECT event FROM agency_events WHERE agency_id = ' . $this->agency . " AND event LIKE 'site_module_%' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        $put('module.boussole', true, 'manager', 200);
        $this->assertSame(['site_module_opened'], $events(), 'rouvrir un module ouvert ne change rien et n\'écrit rien');
        $put('module.boussole', false, 'manager', 200);
        $put('module.boussole', false, 'manager', 200);
        $this->assertSame([[false, false, null], false, ['site_module_opened', 'site_module_closed']], [$state('module.boussole'), $flags()[5], $events()]);

        // Un droit que le site tient d'une autre source (ici un essai) : l'agence ne le ferme pas et ne le remplace pas
        static::getContainer()->get(EntitlementService::class)->trial($code, 'module.pos', 5, 'essai', 'essai du site');
        $this->assertSame([true, false, 'active'], $state('module.pos'));
        $this->assertSame(['AGENCY_MODULE_HELD', 'trial'], [$put('module.pos', false, 'manager', 409)['code'], $put('module.pos', true, 'manager', 409)['source']]);

        // Un site rattaché à la main par la plateforme reste inchangé : l'agence ne règle pas ses modules, et la cascade ne le touche pas
        $this->json('POST', "/api/platform/agencies/{$this->agency}/sites", ['tenantCode' => MV_TEST_TENANT2_CODE], 'super', 201);
        static::getContainer()->get(EntitlementService::class)->grant(MV_TEST_TENANT2_CODE, 'module.boutique', 'manual', null, 'essai', 'droit du site rattaché', null, null, false);
        $this->master->exec("INSERT INTO tenant_entitlements (tenant_code, feature_key, source, status, granted_by, reference) VALUES ('" . MV_TEST_TENANT2_CODE . "', 'module.support', 'agency', 'active', 'essai', 'agency:{$this->agency}')");
        $this->assertSame('AGENCY_SITE_NOT_CREATED', $this->json('GET', '/api/agency/sites/' . MV_TEST_TENANT2_CODE . '/modules', null, 'manager', 409)['code']);
        $this->assertSame('AGENCY_SITE_NOT_CREATED', $this->json('PUT', '/api/agency/sites/' . MV_TEST_TENANT2_CODE . '/modules/module.boutique', ['enabled' => false], 'manager', 409)['code']);
        $this->assertSame('AGENCY_SITE_NOT_CREATED', $this->json('POST', '/api/agency/sites/' . MV_TEST_TENANT2_CODE . '/invitation', null, 'manager', 409)['code']);

        // Un droit « agence » que la plateforme a suspendu elle-même, pour une autre raison, avant la suspension de l'agence
        $rights = fn (string $tenant = ''): array => $this->master->query("SELECT feature_key, status, reference FROM tenant_entitlements WHERE tenant_code = '" . ($tenant ?: $code) . "' AND status IN ('active', 'grace', 'suspended') AND feature_key NOT LIKE 'ai.%' ORDER BY feature_key")->fetchAll(\PDO::FETCH_NUM);
        // l'IA suit la plateforme : l'essai « Aide IA » automatique de tout site neuf, jamais un droit de l'agence
        $this->assertSame([['ai.help', 'trial']], $this->master->query("SELECT feature_key, source FROM tenant_entitlements WHERE tenant_code = '$code' AND feature_key LIKE 'ai.%' AND status = 'active'")->fetchAll(\PDO::FETCH_NUM));
        $support = (int) $this->master->query("SELECT id FROM tenant_entitlements WHERE tenant_code = '$code' AND feature_key = 'module.support' AND status = 'active'")->fetchColumn();
        static::getContainer()->get(EntitlementService::class)->suspend($support, 'plateforme', 'autre raison', false);
        $attachedBefore = $rights(MV_TEST_TENANT2_CODE);
        self::getMailerMessages();

        // Suspension de l'agence, en cascade : ses droits sont suspendus, la page publique est gardée 30 jours ; aucun courriel au site
        $mark = 'agency:' . $this->agency . ':suspended';
        $this->json('POST', "/api/platform/agencies/{$this->agency}/suspend", ['reason' => 'impayé'], 'super', 200);
        $this->assertCount(0, self::getMailerMessages(), 'la cascade n\'écrit à personne');
        $this->assertSame([['app.management', 'suspended', $mark], ['module.boutique', 'suspended', $mark], ['module.landing', 'grace', $mark], ['module.pos', 'active', null], ['module.support', 'suspended', 'agency:' . $this->agency]], $rights());
        $grace = new \DateTimeImmutable((string) $this->master->query("SELECT grace_until FROM tenant_entitlements WHERE tenant_code = '$code' AND feature_key = 'module.landing' AND status = 'grace'")->fetchColumn());
        $this->assertContains((new \DateTimeImmutable())->diff($grace)->days, [29, 30], 'la page publique reste en ligne 30 jours');
        $this->assertSame([true, false, true, false, false, false], $flags(), 'la page publique reste, la caisse en essai aussi ; le reste est fermé');
        $this->assertSame($attachedBefore, $rights(MV_TEST_TENANT2_CODE), 'le site rattaché à la main ne change pas');
        $this->assertSame([[true, true, 'grace'], [false, true, 'suspended']], [$state('module.landing'), $state('module.boutique')]);
        $this->assertNotNull($modules()['module.landing']['graceUntil']);
        $suspended = json_decode((string) $this->master->query('SELECT payload FROM agency_events WHERE agency_id = ' . $this->agency . " AND event = 'suspended'")->fetchColumn(), true);
        $this->assertSame(3, $suspended['rights']);
        // Suspendue, l'agence lit encore, mais ne crée plus et ne règle plus rien
        $this->assertSame('AGENCY_NOT_OPEN', $this->json('POST', '/api/agency/sites', ['code' => 'agtest-autre', 'name' => 'Autre', 'adminEmail' => 'autre@example.invalid'], 'manager', 409)['code']);
        $this->assertSame('AGENCY_NOT_OPEN', $put('module.boussole', true, 'manager', 409)['code']);
        $this->assertSame('AGENCY_NOT_OPEN', $this->json('POST', "/api/agency/sites/$code/invitation", null, 'manager', 409)['code']);
        // Au bout des 30 jours, le balayage des droits suspend la page publique (elle reste marquée par la cascade)
        static::getContainer()->get(EntitlementService::class)->sweep((new \DateTimeImmutable())->modify('+31 days'));
        $this->assertSame(['module.landing', 'suspended', $mark], $rights()[2]);
        $this->assertFalse($flags()[0]);

        // Lever la suspension : ce que la cascade avait pris est rendu, rien d'autre
        $this->json('POST', "/api/platform/agencies/{$this->agency}/resume", null, 'super', 200);
        $this->assertSame([['app.management', 'active', 'agency:' . $this->agency], ['module.boutique', 'active', 'agency:' . $this->agency], ['module.landing', 'active', 'agency:' . $this->agency],
            ['module.support', 'suspended', 'agency:' . $this->agency]], $rights(), 'l\'essai de la caisse, lui, est arrivé à sa fin pendant ces 31 jours');
        $this->assertSame([true, true, false, false, true, false], $flags());
        $this->assertSame($attachedBefore, $rights(MV_TEST_TENANT2_CODE));
        $resumed = json_decode((string) $this->master->query('SELECT payload FROM agency_events WHERE agency_id = ' . $this->agency . " AND event = 'resumed'")->fetchColumn(), true);
        $this->assertSame(3, $resumed['rights']);
    }

    public function testLimitsFailuresAndInterruptedBuildsNeverLeaveAHalfMadeSiteNorDropAnExistingDatabase(): void
    {
        $body = fn (string $code): array => ['code' => $code, 'name' => 'Site ' . $code, 'adminEmail' => "admin-$code@example.invalid"];
        $ask = fn (string $code, int $status): array => $this->json('POST', '/api/agency/sites', $body($code), 'manager', $status);
        $status = fn (int $id): array => $this->master->query("SELECT status, error_code FROM agency_site_requests WHERE id = $id")->fetch(\PDO::FETCH_NUM);
        $used = fn (): int => $this->json('GET', '/api/agency/me', null, 'manager', 200)['agency']['sitesUsed'];
        $a = 'agtest-a' . $this->tag;
        $b = 'agtest-b' . $this->tag;
        $c = 'agtest-c' . $this->tag;

        // Limites, par le super administrateur seulement : nombre de sites (vide = illimité), demandes par jour (garde-fou technique)
        $patch = fn (array $data, string $as, int $expected): array => $this->json('PATCH', "/api/platform/agencies/{$this->agency}", $data, $as, $expected);
        $this->assertSame(403, $this->api('PATCH', "/api/platform/agencies/{$this->agency}", ['maxSites' => 50], 'admin')->getStatusCode());
        $this->assertSame(403, $this->api('PATCH', "/api/platform/agencies/{$this->agency}", ['maxSites' => 50], 'manager')->getStatusCode());
        $this->assertSame(['maxSites', 'dailySiteRequests'], array_column($patch(['maxSites' => -1, 'dailySiteRequests' => 501], 'super', 422)['errors'], 'path'));
        $this->assertSame(['maxSites', 'dailySiteRequests'], array_column($patch(['maxSites' => '3', 'dailySiteRequests' => null], 'super', 422)['errors'], 'path'));
        $limits = $patch(['maxSites' => null, 'dailySiteRequests' => 2], 'super', 200)['agency'];
        $this->assertSame([null, 2], [$limits['maxSites'], $limits['dailySiteRequests']]);
        $this->assertSame(2, $patch([], 'super', 200)['agency']['dailySiteRequests'], 'un champ absent garde sa valeur');
        $this->assertEquals(['maxSites' => null, 'dailySiteRequests' => 2, 'from' => ['maxSites' => 3, 'dailySiteRequests' => 5]],
            json_decode((string) $this->master->query('SELECT payload FROM agency_events WHERE agency_id = ' . $this->agency . " AND event = 'limits_changed'")->fetchColumn(), true));
        $this->assertSame(1, (int) $this->master->query('SELECT COUNT(*) FROM agency_events WHERE agency_id = ' . $this->agency . " AND event = 'limits_changed'")->fetchColumn(), 'sans changement, rien au journal');

        // Plafond de demandes par 24 heures : toutes les demandes comptent, même en échec
        $first = (int) $ask($a, 202)['request']['id'];
        $second = (int) $ask($b, 202)['request']['id'];
        $this->assertSame(['AGENCY_REQUEST_LIMIT', 2], [$ask($c, 429)['code'], $ask($c, 429)['perDay']]);
        $this->assertSame(2, $used());

        // Une base de ce nom apparaît entre la demande et la fabrication : la demande échoue, et la base qui existait N'EST PAS supprimée
        $this->master->exec('CREATE DATABASE "' . AgencySiteCode::database($a) . '"');
        $this->service()->process($first);
        $this->assertSame(['failed', 'SITE_CODE_TAKEN'], $status($first));
        $this->assertTrue($this->databaseExists(AgencySiteCode::database($a)), 'une base existante n\'est jamais supprimée');
        $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM tenants WHERE code = '$a'")->fetchColumn());
        // de même par le chemin de création lui-même : il refuse, sans rien supprimer
        try {
            static::getContainer()->get(TenantConnectionManager::class)->createTenant($a, 'Site', AgencySiteCode::database($a));
            $this->fail('la création sur une base existante doit être refusée');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('existe déjà', $e->getMessage());
        }
        $this->assertTrue($this->databaseExists(AgencySiteCode::database($a)));
        $this->assertSame(1, $used(), 'une demande en échec rend sa place');

        // L'agence est suspendue entre la demande et la fabrication : rien n'est fabriqué
        $this->json('POST', "/api/platform/agencies/{$this->agency}/suspend", null, 'super', 200);
        $this->service()->process($second);
        $this->assertSame(['failed', 'AGENCY_NOT_OPEN'], $status($second));
        $this->assertFalse($this->databaseExists(AgencySiteCode::database($b)));
        $this->json('POST', "/api/platform/agencies/{$this->agency}/resume", null, 'super', 200);
        $this->service()->process($second);
        $this->assertSame(['failed', 'AGENCY_NOT_OPEN'], $status($second), 'une demande en échec n\'est pas refabriquée');

        // Limite de sites : les demandes en cours comptent, pour l'agence comme pour un rattachement fait par la plateforme
        $this->master->exec('DELETE FROM agency_site_requests');
        $patch(['maxSites' => 1, 'dailySiteRequests' => 10], 'super', 200);
        $third = (int) $ask($b, 202)['request']['id'];
        $this->assertSame(['AGENCY_SITE_LIMIT', 1], [$ask($c, 409)['code'], $ask($c, 409)['maxSites']]);
        $this->assertSame('AGENCY_SITE_LIMIT', $this->json('POST', "/api/platform/agencies/{$this->agency}/sites", ['tenantCode' => MV_TEST_TENANT2_CODE], 'super', 409)['code']);
        $this->assertSame('AGENCY_LIMITS', $patch(['maxSites' => 0], 'super', 409)['code']);

        // Le rattachement final échoue (le nom a été confié ailleurs au même instant) : le site fabriqué est DÉFAIT, ses droits annulés
        $other = (int) $this->master->query("INSERT INTO agencies (code, name, home_tenant_code, status, max_sites) VALUES ('agence-autre', 'Autre agence', 'agfict-attache', 'trial', 3) RETURNING id")->fetchColumn();
        $this->master->prepare('INSERT INTO agency_sites (agency_id, tenant_code, origin) VALUES (?, ?, ?)')->execute([$other, $b, 'attached']);
        $this->service()->process($third);
        $this->assertSame(['failed', 'ATTACH_FAILED'], $status($third));
        $this->assertFalse($this->databaseExists(AgencySiteCode::database($b)), 'pas de site à moitié fait : la base créée par cette fabrication est supprimée');
        $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM tenants WHERE code = '$b'")->fetchColumn());
        $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM tenant_entitlements WHERE tenant_code = '$b' AND status IN ('active', 'grace', 'suspended')")->fetchColumn(), 'aucun droit ne reste au nom d\'un site qui n\'existe pas');
        $this->assertSame(0, count(self::getMailerMessages()), 'aucune invitation pour un site qui n\'existe pas');
        $this->master->exec("DELETE FROM agency_sites WHERE agency_id = $other");

        // Le modèle des sites porte un compte qui n'est pas un compte de la plateforme : la création est REFUSÉE, rien ne reste
        $template = $this->pdo(SITE_TEMPLATE_TEST_DB);
        $template->exec("INSERT INTO \"user\" (id, email, roles, password, username, firstname, lastname, is_verified, created_at) VALUES (99001, 'etranger@example.invalid', '[\"ROLE_USER_INTERNET\"]', 'x', 'etranger@example.invalid', 'Compte', 'Étranger', true, NOW())");
        $template = null;
        try {
            $dirty = (int) $ask('agtest-e' . $this->tag, 202)['request']['id'];
            $this->service()->process($dirty);
            $this->assertSame(['failed', 'BUILD_FAILED'], $status($dirty));
            $this->assertFalse($this->databaseExists(AgencySiteCode::database('agtest-e' . $this->tag)), 'un site né avec le compte d\'un autre n\'est pas livré');
            $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM tenants WHERE code = 'agtest-e{$this->tag}'")->fetchColumn());
        } finally {
            $this->pdo(SITE_TEMPLATE_TEST_DB)->exec('DELETE FROM "user" WHERE id = 99001');
        }

        // Défaire un site : seulement celui que le processus vient de créer. Jamais un site existant, même sur demande directe
        $this->assertFalse(static::getContainer()->get(TenantConnectionManager::class)->discardCreatedTenant(MV_TEST_TENANT2_CODE, MV_TEST_TENANT2_DB));
        $this->assertSame([true, 1], [$this->databaseExists(MV_TEST_TENANT2_DB), (int) $this->master->query("SELECT COUNT(*) FROM tenants WHERE code = '" . MV_TEST_TENANT2_CODE . "'")->fetchColumn()]);

        // Un nom qui a servi : des droits vivants ou une clé d'application restés dans la base maître le rendent indisponible
        $this->master->exec("INSERT INTO tenant_entitlements (tenant_code, feature_key, source, status, granted_by) VALUES ('$c', 'module.pos', 'manual', 'active', 'essai')");
        $this->assertSame('TAKEN', $this->json('GET', "/api/agency/sites/availability?code=$c", null, 'manager', 200)['reason']);
        $this->master->exec("DELETE FROM tenant_entitlements WHERE tenant_code = '$c'");
        $this->master->exec("INSERT INTO tenant_app_keys (tenant_code, app, key_hash, key_hint, label, created_by) VALUES ('$c', 'pos', '" . hash('sha256', $c) . "', 'xx', 'ancienne clé', 'essai')");
        $this->assertSame('AGENCY_SITE_CODE_TAKEN', $ask($c, 409)['code']);
        $this->master->exec("DELETE FROM tenant_app_keys WHERE tenant_code = '$c'");
        $this->assertNull($this->json('GET', "/api/agency/sites/availability?code=$c", null, 'manager', 200)['reason']);

        // Fin de l'essai : plus de création
        $this->master->exec("UPDATE agencies SET trial_ends_at = NOW() - INTERVAL '1 day', max_sites = NULL WHERE id = {$this->agency}");
        $this->assertSame('AGENCY_TRIAL_ENDED', $ask($c, 409)['code']);
        $this->master->exec("UPDATE agencies SET trial_ends_at = NOW() + INTERVAL '30 days' WHERE id = {$this->agency}");

        // Passage de rattrapage : une fabrication interrompue passe en échec ; une demande restée en attente repart vers le worker
        $stuck = (int) $ask($c, 202)['request']['id'];
        $this->master->exec("UPDATE agency_site_requests SET status = 'building', started_at = NOW() - INTERVAL '14 minutes' WHERE id = $stuck");
        $this->assertSame(['interrupted' => 0, 'requeued' => 0], $this->service()->sweep());
        $this->master->exec("UPDATE agency_site_requests SET started_at = NOW() - INTERVAL '16 minutes' WHERE id = $stuck");
        $this->assertSame(['interrupted' => 1, 'requeued' => 0], $this->service()->sweep());
        $this->assertSame(['failed', 'INTERRUPTED'], $status($stuck));
        $this->assertSame('INTERRUPTED', $this->json('GET', "/api/agency/site-requests/$stuck", null, 'manager', 200)['request']['errorCode']);
        $waiting = (int) $ask('agtest-d' . $this->tag, 202)['request']['id'];
        $sentBefore = count(static::getContainer()->get('messenger.transport.media')->getSent());
        $this->assertSame(['interrupted' => 0, 'requeued' => 0], $this->service()->sweep(), 'une demande récente attend son worker');
        $this->master->exec("UPDATE agency_site_requests SET requested_at = NOW() - INTERVAL '16 minutes' WHERE id = $waiting");
        $this->assertSame(['interrupted' => 0, 'requeued' => 1], $this->service()->sweep());
        $sent = static::getContainer()->get('messenger.transport.media')->getSent();
        $this->assertSame([$sentBefore + 1, $waiting], [count($sent), end($sent)->getMessage()->requestId]);
        $this->assertSame(['pending', null], $status($waiting));
    }

    public function testTheSuperAdministratorSetsLimitsAndReadsSiteRequestsOnTheAgenciesPage(): void
    {
        $this->json('POST', '/api/agency/sites', ['code' => 'agtest-' . $this->tag, 'name' => 'Site <vu>', 'adminEmail' => 'vu@example.invalid'], 'manager', 422);
        $this->json('POST', '/api/agency/sites', ['code' => 'agtest-' . $this->tag, 'name' => 'Site vu à l\'écran', 'adminEmail' => 'vu@example.invalid'], 'manager', 202);
        $this->client->getCookieJar()->clear();
        $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/agences?agence=' . $this->agency, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 600));
        $this->assertStringContainsString('agtest-' . $this->tag, $crawler->filter('table.agences-demandes')->text());
        $this->assertStringContainsString('pending', $crawler->filter('table.agences-demandes')->text());
        $this->assertStringContainsString('1 demande(s) de création en cours', $crawler->filter('body')->text());
        $form = $crawler->filter('form.agences-limites')->form(['maxSites' => '', 'dailySiteRequests' => '40']);
        $this->client->submit($form);
        $this->assertSame([null, 40], $this->master->query('SELECT max_sites, daily_site_requests FROM agencies WHERE id = ' . $this->agency)->fetch(\PDO::FETCH_NUM));
        $crawler = $this->client->followRedirect();
        $this->assertStringContainsString('illimité', $crawler->filter('body')->text());
        // un jeton de sécurité faux ne règle rien
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/plateforme/agences/{$this->agency}/limites", ['_token' => 'faux', 'maxSites' => '2', 'dailySiteRequests' => '1'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame([null, 40], $this->master->query('SELECT max_sites, daily_site_requests FROM agencies WHERE id = ' . $this->agency)->fetch(\PDO::FETCH_NUM));
    }

    private function service(): AgencySiteRequestService
    {
        return static::getContainer()->get(AgencySiteRequestService::class);
    }

    private function databaseExists(string $db): bool
    {
        return (bool) $this->master->query("SELECT COUNT(*) FROM pg_database WHERE datname = '$db'")->fetchColumn();
    }

    private function clean(): void
    {
        // les sites créés par un essai : leur base, leur ligne, leurs droits
        foreach ($this->master->query("SELECT datname FROM pg_database WHERE datname LIKE 'db\\_agtest\\_%'")->fetchAll(\PDO::FETCH_COLUMN) as $db) {
            $this->master->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$db' AND pid <> pg_backend_pid()");
            $this->master->exec('DROP DATABASE IF EXISTS "' . $db . '"');
        }
        $this->master->exec("DELETE FROM tenant_entitlement_events WHERE tenant_code LIKE 'agtest-%' OR tenant_code = '" . MV_TEST_TENANT2_CODE . "'");
        $this->master->exec("DELETE FROM tenant_entitlements WHERE tenant_code LIKE 'agtest-%' OR tenant_code = '" . MV_TEST_TENANT2_CODE . "'");
        $this->master->exec("DELETE FROM tenant_app_keys WHERE tenant_code LIKE 'agtest-%'");
        foreach (['agency_site_requests', 'agency_events', 'agency_sites'] as $table) {
            $this->master->exec("DELETE FROM $table");
        }
        $this->master->exec('DELETE FROM agency_plans WHERE agency_id IS NOT NULL');
        $this->master->exec('DELETE FROM agencies');
        $this->master->exec("DELETE FROM tenants WHERE code LIKE 'agtest-%'");
        static::getContainer()->get('cache.app.taggable')->invalidateTags(['tenants']);
        $site = $this->pdo(MV_TEST_TENANT_DB);
        $site->exec("DELETE FROM refresh_tokens WHERE username LIKE 'agence-%@example.invalid'");
        $site->exec("DELETE FROM \"user\" WHERE email LIKE 'agence-%@example.invalid' OR email IN ('super-agences@example.invalid', 'admin-agences@example.invalid')");
        $this->pdo(MV_TEST_TENANT2_DB)->exec('UPDATE entreprise SET is_boutique_active = is_boutique_active'); // la base du second site n'est pas modifiée par ces essais
    }

    /** Une connexion directe à la base d'un site (celle de la requête en cours n'est pas touchée) */
    private function pdo(string $db): \PDO
    {
        return static::getContainer()->get(\App\Services\Platform\TenantDatabaseConnector::class)->pdo($db);
    }

    /** Un compte du site d'attache, et son jeton */
    private function account(string $email, array $roles, string $key): void
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Agathe')->setLastname('Agence')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->tokens[$key] = $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    private function login(string $email, string $platform, string $host): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . $host . '/api/login', [], [], ['HTTP_X_TENANT_HOST' => $host, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.86.' . random_int(1, 254) . '.' . random_int(1, 254)],
            json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => $platform]));

        return $this->client->getResponse();
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

    /** Jeton d'un compte du site d'attache : `super`, `admin`, ou un jeton gardé (`manager`, `member`) */
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
