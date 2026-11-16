<?php

namespace App\Tests\Functional\Platform;

use App\Entity\User;
use App\Services\Agency\AgencySiteCode;
use App\Services\Agency\AgencySiteRequestService;
use App\Services\Platform\Sites\Message\BuildPlatformSiteMessage;
use App\Services\Platform\Sites\PlatformSiteService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Création d'un site avec son type par le super administrateur, lot TYPE-1 (16/11/2026, `docs/agences-et-import.md` § 9). Le site est
 * VRAIMENT fabriqué (copie du modèle de site des tests, jamais du site de référence). La demande est écrite avant sa première étape ;
 * chaque étape se rejoue sans doublon ; une étape qui échoue après la création du site laisse la demande « à compléter » ; « Reprendre »
 * rejoue les seules étapes manquantes, « Abandonner » laisse un site normal ; le rôle de responsable d'une agence est donné quand
 * l'administrateur accepte son invitation ; les pastilles se calculent depuis les liens. Sites `agtest-…`, supprimés à la fin.
 */
class PlatformSitesTest extends WebTestCase
{
    private const PASSWORD = 'Un-mot-de-passe-solide-42';
    private const DSN = 'null://plateforme-envoi%40example.invalid:x@default';

    private KernelBrowser $client;
    private \PDO $master;
    private string $tag;
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->tag = bin2hex(random_bytes(3));
        $_ENV['MAILER_DSN'] = $_SERVER['MAILER_DSN'] = self::DSN; // l'invitation part par l'expéditeur de la plateforme
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        unset($_ENV['MAILER_DSN'], $_SERVER['MAILER_DSN']);
        parent::tearDown();
    }

    public function testTheSuperAdministratorCreatesANormalSiteByARequestWithStepsAndTheListShowsComputedBadges(): void
    {
        $code = 'agtest-n' . $this->tag;
        $body = ['type' => 'normal', 'code' => $code, 'name' => 'Boutique d\'Inès', 'adminEmail' => "INES-{$this->tag}@Example.invalid", 'adminFirstName' => 'Inès', 'locale' => 'fr'];

        // Réservé au super administrateur ; une saisie fausse est refusée champ par champ ; un code réservé ou pris ne se demande pas
        $this->assertSame(401, $this->api('POST', '/api/platform/sites', $body, null)->getStatusCode());
        $this->assertSame(403, $this->api('POST', '/api/platform/sites', $body, 'admin')->getStatusCode());
        $this->assertSame(403, $this->api('GET', '/api/platform/sites/badges', null, 'admin')->getStatusCode());
        $this->assertSame(403, $this->api('GET', '/api/platform/site-requests', null, 'admin')->getStatusCode());
        $errors = $this->json('POST', '/api/platform/sites', ['type' => 'groupe', 'code' => 'X', 'name' => '<b>', 'adminEmail' => 'pas-une-adresse', 'locale' => 'de', 'modules' => ['module.inconnu']], 'super', 422)['errors'];
        $this->assertSame(['type', 'code', 'name', 'adminEmail', 'locale', 'modules'], array_column($errors, 'path'));
        $this->assertSame('PLATFORM_SITE_CODE_RESERVED', $this->json('POST', '/api/platform/sites', ['code' => 'demo'] + $body, 'super', 409)['code']);
        $this->assertSame('PLATFORM_SITE_CODE_TAKEN', $this->json('POST', '/api/platform/sites', ['code' => MV_TEST_TENANT_CODE] + $body, 'super', 409)['code']);
        $this->assertSame(0, (int) $this->master->query('SELECT COUNT(*) FROM platform_site_requests')->fetchColumn(), 'un refus n\'écrit aucune demande');

        // LA DEMANDE est écrite avant toute étape, puis confiée au worker
        $asked = $this->json('POST', '/api/platform/sites', $body, 'super', 202)['request'];
        $id = (int) $asked['id'];
        $this->assertSame(['normal', $code, "ines-{$this->tag}@example.invalid", 'pending', ['site' => 'todo', 'invitation' => 'todo'], null, null],
            [$asked['type'], $asked['siteCode'], $asked['adminEmail'], $asked['status'], $asked['steps'], $asked['site'], $asked['failedStep']]);
        $queued = static::getContainer()->get('messenger.transport.media')->getSent();
        $this->assertInstanceOf(BuildPlatformSiteMessage::class, end($queued)->getMessage());
        $this->assertSame($id, end($queued)->getMessage()->requestId);
        $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM tenants WHERE code = '$code'")->fetchColumn(), 'rien n\'est créé par la demande elle-même');
        // son code est retenu : ni la plateforme ni une agence ne le redemandent
        $this->assertSame('PLATFORM_SITE_CODE_TAKEN', $this->json('POST', '/api/platform/sites', $body, 'super', 409)['code']);
        $this->assertSame(['available' => false, 'reason' => 'TAKEN'], array_intersect_key(static::getContainer()->get(AgencySiteRequestService::class)->availability($code), ['available' => 1, 'reason' => 1]));

        // LE WORKER déroule les étapes : le site (fabrication d'AG-2, sans agence), puis l'invitation
        $this->sites()->process($id);
        $mails = self::getMailerMessages(); // lus tout de suite : la requête suivante repart d'un compteur vide
        $done = $this->json('GET', "/api/platform/site-requests/$id", null, 'super', 200)['request'];
        $this->assertSame(['ready', ['site' => 'done', 'invitation' => 'done'], null, null, null], [$done['status'], $done['steps'], $done['failedStep'], $done['errorCode'], $done['agencyId']]);
        $this->assertSame([$code, 'Boutique d\'Inès'], [$done['site']['code'], $done['site']['name']]);
        $host = (string) $done['site']['host'];
        $this->assertStringStartsWith($code . '.', $host);
        $this->assertTrue($done['invitation']['sent']);
        $site = $this->pdo(AgencySiteCode::database($code));
        // les modules cochés par défaut (page publique et boutique), posés comme des ACCORDS DE LA PLATEFORME, sans essai automatique
        $this->assertSame([['module.boutique', 'manual', 'active'], ['module.landing', 'manual', 'active']],
            $this->master->query("SELECT feature_key, source, status FROM tenant_entitlements WHERE tenant_code = '$code' AND feature_key LIKE 'module.%' ORDER BY feature_key")->fetchAll(\PDO::FETCH_NUM));
        $this->assertEquals(['opened' => ['module.landing', 'module.boutique'], 'failed' => []], $done['modules']);
        // le site naît en construction, sa fiche est neuve, ses comptes hérités sont nettoyés
        $this->assertSame(['construction', true, true, false], array_map(static fn ($v) => is_string($v) && !in_array($v, ['t', 'f'], true) ? $v : in_array($v, [true, 't', 1, '1'], true),
            $site->query('SELECT storefront_state, is_boutique_active, is_landing_page_active, is_pos_active FROM entreprise')->fetch(\PDO::FETCH_NUM)));
        $admin = $site->query("SELECT email, roles::text, is_verified, signup_channel, reset_token IS NOT NULL AS linked FROM \"user\" WHERE email = 'ines-{$this->tag}@example.invalid'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame([User::SIGNUP_ADMIN_INVITE, false, true], [$admin['signup_channel'], in_array($admin['is_verified'], [true, 't', 1, '1'], true), in_array($admin['linked'], [true, 't', 1, '1'], true)]);
        $this->assertStringContainsString('ROLE_ADMIN', $admin['roles']);
        // le courriel d'invitation ne nomme aucune agence
        $this->assertCount(1, $mails);
        $html = (string) $mails[0]->getHtmlBody();
        $this->assertSame(["ines-{$this->tag}@example.invalid"], array_map(static fn ($a) => $a->getAddress(), $mails[0]->getTo()));
        $this->assertStringContainsString('a été créé pour vous', $html);
        $this->assertStringContainsString('la personne qui a créé votre site', $html);
        $this->assertStringNotContainsStringIgnoringCase('agence', $html);
        // rejouer le traitement d'une demande faite ne refait rien : ni site, ni courriel, ni nouveau lien
        $link = (string) $site->query("SELECT reset_token FROM \"user\" WHERE email = 'ines-{$this->tag}@example.invalid'")->fetchColumn();
        $this->sites()->process($id);
        $this->assertCount(0, self::getMailerMessages());
        $this->assertSame($link, (string) $site->query("SELECT reset_token FROM \"user\" WHERE email = 'ines-{$this->tag}@example.invalid'")->fetchColumn());
        $this->assertSame(1, (int) $this->master->query("SELECT COUNT(*) FROM tenants WHERE code = '$code'")->fetchColumn());

        // PASTILLES, calculées depuis les liens : ce site est indépendant ; le filtre ne garde que la pastille demandée
        $list = array_column($this->json('GET', '/api/platform/sites/badges', null, 'super', 200)['sites'], null, 'code');
        // (la grille sites × fonctionnalités de la console garde son adresse et sa forme)
        $this->assertArrayHasKey('features', $this->json('GET', '/api/platform/sites', null, 'super', 200));
        $this->assertSame([[['type' => 'independent']], 'Boutique d\'Inès', $host], [$list[$code]['badges'], $list[$code]['name'], $list[$code]['host']]);
        $this->assertArrayHasKey(MV_TEST_TENANT_CODE, $list);
        $this->assertSame([], $this->json('GET', '/api/platform/sites/badges?badge=agency_home', null, 'super', 200)['sites']);
        $this->assertArrayHasKey($code, array_column($this->json('GET', '/api/platform/sites/badges?badge=independent', null, 'super', 200)['sites'], null, 'code'));
        $this->assertSame('badge', $this->json('GET', '/api/platform/sites/badges?badge=groupe', null, 'super', 422)['errors'][0]['path']);
        $this->assertSame($id, $this->json('GET', '/api/platform/site-requests', null, 'super', 200)['requests'][0]['id']);
        $this->assertSame('PLATFORM_SITE_REQUEST_NOT_FOUND', $this->json('GET', '/api/platform/site-requests/999999', null, 'super', 404)['code']);
        // une demande faite ne se reprend ni ne s'abandonne
        $this->assertSame('PLATFORM_SITE_REQUEST_STATE', $this->json('POST', "/api/platform/site-requests/$id/resume", null, 'super', 409)['code']);
        $this->assertSame('PLATFORM_SITE_REQUEST_STATE', $this->json('POST', "/api/platform/site-requests/$id/abandon", null, 'super', 409)['code']);

        // LA PAGE d'EasyAdmin : réservée au super administrateur ; mêmes sites, mêmes pastilles, même service pour créer
        $this->loginAs(['ROLE_ADMIN']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/sites', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->getCookieJar()->clear();
        $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $page = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/sites', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 600));
        $this->assertSame(['independent'], $page->filter("tr[data-site=\"$code\"] [data-pastille]")->each(static fn ($b) => $b->attr('data-pastille')));
        $this->assertSame('ready', $page->filter("tr[data-demande=\"$id\"]")->attr('data-etat'));
        $this->assertSame(['module.landing', 'module.boutique'], $page->filter('form.sites-creer input[name="modules[]"][checked]')->each(static fn ($i) => $i->attr('value')));
        $second = 'agtest-p' . $this->tag;
        $form = $page->filter('form.sites-creer')->form(['type' => 'normal', 'code' => $second, 'name' => 'Par la page', 'adminEmail' => "page-{$this->tag}@example.invalid"]);
        $this->client->submit($form);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['normal', 'pending'], $this->master->query("SELECT type, status FROM platform_site_requests WHERE tenant_code = '$second'")->fetch(\PDO::FETCH_NUM));
        // un jeton de sécurité faux n'écrit rien
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/sites/creer', ['_token' => 'faux', 'code' => 'agtest-z' . $this->tag, 'name' => 'Z', 'adminEmail' => 'z@example.invalid'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM platform_site_requests WHERE tenant_code = 'agtest-z{$this->tag}'")->fetchColumn());
        $this->client->getCookieJar()->clear();
    }

    public function testAStepThatFailsLeavesTheRequestToCompleteThenItIsResumedOrAbandonedAndAnAgencyGetsItsManagerWhenTheInvitationIsAccepted(): void
    {
        $code = 'agtest-a' . $this->tag;
        $email = "resp-{$this->tag}@example.invalid";
        // l'adresse désignée est DÉJÀ celle d'un compte du site neuf (le compte de la plateforme, hérité du modèle) : l'invitation échouera
        $asked = $this->json('POST', '/api/platform/sites', ['type' => 'agency', 'code' => $code, 'name' => 'Site de l\'agence', 'adminEmail' => 'plateforme@example.invalid', 'modules' => ['module.landing'],
            'agency' => ['name' => 'Agence Créée', 'contactEmail' => 'contact-cree@example.invalid', 'isTest' => true, 'trialDays' => 30, 'maxSites' => 7]], 'super', 202)['request'];
        $id = (int) $asked['id'];
        $this->assertSame(['site' => 'todo', 'invitation' => 'todo', 'agency' => 'todo', 'manager' => 'todo'], $asked['steps']);
        // les réglages de l'agence sont contrôlés à la demande
        $bad = $this->json('POST', '/api/platform/sites', ['type' => 'agency', 'code' => 'agtest-q' . $this->tag, 'name' => 'Quai', 'adminEmail' => 'q@example.invalid', 'agency' => ['name' => '', 'trialDays' => 0, 'maxSites' => '3', 'isTest' => 'oui']], 'super', 422)['errors'];
        $this->assertSame(['agency.name', 'agency.isTest', 'agency.trialDays', 'agency.maxSites'], array_column($bad, 'path'));

        // L'INVITATION ÉCHOUE : le site existe, la demande est « à compléter », avec l'étape et son code ; ce compte-là n'est pas touché
        $this->sites()->process($id);
        $this->assertCount(0, self::getMailerMessages());
        $stuck = $this->json('GET', "/api/platform/site-requests/$id", null, 'super', 200)['request'];
        $this->assertSame(['incomplete', 'invitation', ['site' => 'done', 'invitation' => 'failed', 'agency' => 'todo', 'manager' => 'todo'], null], [$stuck['status'], $stuck['failedStep'], $stuck['steps'], $stuck['agencyId']]);
        $this->assertSame('ACCOUNT_EXISTS', $stuck['errorCode']);
        $this->assertSame($code, $stuck['site']['code'], 'le site existe : la demande le dit');
        $this->assertSame(1, (int) $this->master->query("SELECT COUNT(*) FROM tenants WHERE code = '$code'")->fetchColumn(), 'rien n\'est défait en silence');
        $this->assertSame(0, (int) $this->master->query('SELECT COUNT(*) FROM agencies')->fetchColumn(), 'les étapes suivantes ne sont pas faites');
        // né sans boutique (seule la page publique est cochée) : le site est en service, jamais bloqué
        $this->assertSame('open', $this->pdo(AgencySiteCode::database($code))->query('SELECT storefront_state FROM entreprise')->fetchColumn());
        // la pastille « Création à compléter » s'ajoute, et le filtre la trouve
        $list = array_column($this->json('GET', '/api/platform/sites/badges?badge=creation_incomplete', null, 'super', 200)['sites'], null, 'code');
        $this->assertSame([['type' => 'independent'], ['type' => 'creation_incomplete', 'requestId' => $id]], $list[$code]['badges']);
        // tant qu'elle est à compléter, la demande retient son code
        $this->assertSame('PLATFORM_SITE_CODE_TAKEN', $this->json('POST', '/api/platform/sites', ['code' => $code, 'name' => 'Autre', 'adminEmail' => 'autre@example.invalid'], 'super', 409)['code']);
        // reprise alors que la cause dure : toujours à compléter, toujours un seul site
        $this->assertSame('pending', $this->json('POST', "/api/platform/site-requests/$id/resume", null, 'super', 202)['request']['status']);
        $this->sites()->process($id);
        $this->assertSame(['incomplete', 'invitation'], array_values(array_intersect_key($this->json('GET', "/api/platform/site-requests/$id", null, 'super', 200)['request'], ['status' => 1, 'failedStep' => 1])));
        $this->assertSame(1, (int) $this->master->query("SELECT COUNT(*) FROM tenants WHERE code = '$code'")->fetchColumn());

        // « REPRENDRE » en désignant une autre adresse : les SEULES étapes manquantes sont rejouées (le site n'est pas refait)
        $this->assertSame(403, $this->api('POST', "/api/platform/site-requests/$id/resume", ['adminEmail' => $email], 'admin')->getStatusCode());
        $this->assertSame('adminEmail', $this->json('POST', "/api/platform/site-requests/$id/resume", ['adminEmail' => 'pas-une-adresse'], 'super', 422)['errors'][0]['path']);
        $this->assertSame($email, $this->json('POST', "/api/platform/site-requests/$id/resume", ['adminEmail' => strtoupper($email)], 'super', 202)['request']['adminEmail']);
        $untouched = $this->pdo(AgencySiteCode::database($code))->query("SELECT roles::text || '|' || password FROM \"user\" WHERE email = 'plateforme@example.invalid'")->fetchColumn();
        $this->sites()->process($id);
        $sent = self::getMailerMessages();
        $ready = $this->json('GET', "/api/platform/site-requests/$id", null, 'super', 200)['request'];
        $this->assertSame(['ready', null, null, ['site' => 'done', 'invitation' => 'done', 'agency' => 'done', 'manager' => 'waiting']], [$ready['status'], $ready['failedStep'], $ready['errorCode'], $ready['steps']]);
        $this->assertCount(1, $sent, 'une seule invitation est partie, à la reprise');
        $this->assertSame([$email], array_map(static fn ($a) => $a->getAddress(), $sent[0]->getTo()));
        $this->assertSame($untouched, $this->pdo(AgencySiteCode::database($code))->query("SELECT roles::text || '|' || password FROM \"user\" WHERE email = 'plateforme@example.invalid'")->fetchColumn(), 'le compte de la plateforme n\'a pas été touché');
        // une fois l'invitation partie, l'administrateur ne se change plus par la demande
        $this->assertSame('PLATFORM_SITE_REQUEST_STATE', $this->json('POST', "/api/platform/site-requests/$id/resume", ['adminEmail' => 'encore@example.invalid'], 'super', 409)['code']);
        // la fiche d'agence naît avec ce site pour attache, avec l'essai et le nombre de sites du formulaire
        $agency = $this->master->query('SELECT id, code, name, home_tenant_code, status, max_sites, is_test, contact_email, trial_ends_at FROM agencies')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $agency);
        $this->assertSame([$code, 'Agence Créée', $code, 'trial', 7, true, 'contact-cree@example.invalid', $ready['agencyId']],
            [$agency[0]['code'], $agency[0]['name'], $agency[0]['home_tenant_code'], $agency[0]['status'], (int) $agency[0]['max_sites'], in_array($agency[0]['is_test'], [true, 't', 1, '1'], true), $agency[0]['contact_email'], (int) $agency[0]['id']]);
        $this->assertContains((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->diff(new \DateTimeImmutable((string) $agency[0]['trial_ends_at'], new \DateTimeZone('UTC')))->days, [29, 30]);
        // sa pastille est celle d'un site d'attache d'agence ; elle n'est plus « indépendant » ni « à compléter »
        $list = array_column($this->json('GET', '/api/platform/sites/badges?badge=agency_home', null, 'super', 200)['sites'], null, 'code');
        $this->assertSame([['type' => 'agency_home', 'agencyId' => (int) $agency[0]['id'], 'agencyCode' => $code, 'agencyName' => 'Agence Créée']], $list[$code]['badges']);

        // LE RÔLE DE RESPONSABLE n'est donné qu'à l'ACCEPTATION de l'invitation, pas avant
        $site = $this->pdo(AgencySiteCode::database($code));
        $roles = fn (): string => (string) $site->query("SELECT roles::text FROM \"user\" WHERE email = '$email'")->fetchColumn();
        $this->assertStringNotContainsString('ROLE_AGENCY', $roles(), 'avant l\'acceptation : administrateur du site, rien de plus');
        $this->json('GET', '/api/platform/site-requests', null, 'super', 200); // le rattrapage ne donne rien à un compte qui n'a pas accepté
        $this->assertStringNotContainsString('ROLE_AGENCY', $roles());
        // … et l'acceptation d'UNE AUTRE invitation sur ce site (un second administrateur invité) ne le donne pas à sa place
        $site->exec("INSERT INTO \"user\" (id, email, username, firstname, lastname, roles, password, is_verified, signup_channel, reset_token, reset_token_expires_at, created_at) SELECT nextval('user_id_seq'), 'second-{$this->tag}@example.invalid', 'second-{$this->tag}@example.invalid', 'Second', 'Invité', '[\"ROLE_ADMIN\"]', 'inutilisable', false, '" . User::SIGNUP_ADMIN_INVITE . "', 'jeton-second-{$this->tag}', NOW() + INTERVAL '1 day', NOW()");
        $this->assertSame(200, $this->api('POST', '/api/password-reset/confirm', ['token' => "jeton-second-{$this->tag}", 'password' => self::PASSWORD], null, (string) $ready['site']['host'])->getStatusCode());
        $this->assertStringNotContainsString('ROLE_AGENCY', $roles(), 'l\'administrateur désigné n\'a pas encore accepté');
        $this->assertStringNotContainsString('ROLE_AGENCY', (string) $site->query("SELECT roles::text FROM \"user\" WHERE email = 'second-{$this->tag}@example.invalid'")->fetchColumn());
        $token = (string) $site->query("SELECT reset_token FROM \"user\" WHERE email = '$email'")->fetchColumn();
        $accepted = $this->api('POST', '/api/password-reset/confirm', ['token' => $token, 'password' => self::PASSWORD], null, (string) $ready['site']['host']);
        $this->assertSame(200, $accepted->getStatusCode(), (string) $accepted->getContent());
        $this->assertStringContainsString('"ROLE_AGENCY"', $roles());
        $this->assertStringContainsString('ROLE_ADMIN', $roles());
        $this->assertSame('done', $this->json('GET', "/api/platform/site-requests/$id", null, 'super', 200)['request']['steps']['manager']);
        $this->assertSame(1, (int) $this->master->query("SELECT COUNT(*) FROM agency_events WHERE event = 'role_granted' AND agency_id = " . (int) $agency[0]['id'])->fetchColumn());

        // « ABANDONNER » une demande à compléter : le site reste un site normal, la demande le dit ; elle ne se reprend plus
        $other = 'agtest-b' . $this->tag;
        $second = (int) $this->json('POST', '/api/platform/sites', ['type' => 'agency', 'code' => $other, 'name' => 'Abandonné', 'adminEmail' => 'plateforme@example.invalid', 'agency' => ['name' => 'Jamais Créée', 'managerRole' => false]], 'super', 202)['request']['id'];
        $this->sites()->process($second);
        $this->assertSame(['incomplete', 'skipped'], [$this->json('GET', "/api/platform/site-requests/$second", null, 'super', 200)['request']['status'], $this->json('GET', "/api/platform/site-requests/$second", null, 'super', 200)['request']['steps']['manager']]);
        $this->assertSame(403, $this->api('POST', "/api/platform/site-requests/$second/abandon", null, 'admin')->getStatusCode());
        $gone = $this->json('POST', "/api/platform/site-requests/$second/abandon", null, 'super', 200)['request'];
        $this->assertSame(['abandoned', $other], [$gone['status'], $gone['site']['code']]);
        $this->assertSame(1, (int) $this->master->query("SELECT COUNT(*) FROM tenants WHERE code = '$other'")->fetchColumn(), 'le site n\'est ni défait ni supprimé');
        $this->assertSame(1, (int) $this->master->query('SELECT COUNT(*) FROM agencies')->fetchColumn(), 'aucune agence n\'est née de la demande abandonnée');
        $this->assertSame([['type' => 'independent']], array_column($this->json('GET', '/api/platform/sites/badges', null, 'super', 200)['sites'], null, 'code')[$other]['badges'], 'un site normal : plus de pastille « à compléter »');
        $this->assertSame('PLATFORM_SITE_REQUEST_STATE', $this->json('POST', "/api/platform/site-requests/$second/resume", null, 'super', 409)['code']);
        $this->sites()->process($second);
        $this->assertSame('abandoned', $this->json('GET', "/api/platform/site-requests/$second", null, 'super', 200)['request']['status']);

        // Abandonner une demande PAS ENCORE COMMENCÉE : aucun site n'a été créé, et le code redevient libre
        $free = 'agtest-c' . $this->tag;
        $third = (int) $this->json('POST', '/api/platform/sites', ['code' => $free, 'name' => 'Jamais fait', 'adminEmail' => "jamais-{$this->tag}@example.invalid"], 'super', 202)['request']['id'];
        $this->assertSame(['abandoned', null], array_values(array_intersect_key($this->json('POST', "/api/platform/site-requests/$third/abandon", null, 'super', 200)['request'], ['status' => 1, 'site' => 1])));
        $this->sites()->process($third);
        $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM tenants WHERE code = '$free'")->fetchColumn());

        // ÉCHEC AVANT que le site existe (une base de ce nom apparaît entre la demande et la fabrication) : la demande est « en échec »,
        // pas « à compléter » ; rien n'a été créé, et la base qui existait n'est pas touchée
        $fourth = (int) $this->json('POST', '/api/platform/sites', ['code' => $free, 'name' => 'Pris entre-temps', 'adminEmail' => "pris-{$this->tag}@example.invalid"], 'super', 202)['request']['id'];
        $this->master->exec('CREATE DATABASE "' . AgencySiteCode::database($free) . '"');
        $this->sites()->process($fourth);
        $failed = $this->json('GET', "/api/platform/site-requests/$fourth", null, 'super', 200)['request'];
        $this->assertSame(['failed', 'site', 'SITE_CODE_TAKEN', null, 'failed'], [$failed['status'], $failed['failedStep'], $failed['errorCode'], $failed['site'], $failed['steps']['site']]);
        $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM tenants WHERE code = '$free'")->fetchColumn());
        $this->assertSame(1, (int) $this->master->query("SELECT COUNT(*) FROM pg_database WHERE datname = '" . AgencySiteCode::database($free) . "'")->fetchColumn(), 'une base existante n\'est jamais supprimée');
        $this->assertSame('PLATFORM_SITE_REQUEST_STATE', $this->json('POST', "/api/platform/site-requests/$fourth/resume", null, 'super', 409)['code']);

        // RATTRAPAGE : un rôle de responsable qui attendait une acceptation faite par un autre chemin est donné à la lecture de la liste
        $late = 'agtest-d' . $this->tag;
        $fifth = (int) $this->json('POST', '/api/platform/sites', ['type' => 'agency', 'code' => $late, 'name' => 'Rattrapé', 'adminEmail' => "tard-{$this->tag}@example.invalid", 'modules' => [], 'agency' => ['name' => 'Agence Tardive']], 'super', 202)['request']['id'];
        $this->sites()->process($fifth);
        $this->assertSame('waiting', $this->json('GET', "/api/platform/site-requests/$fifth", null, 'super', 200)['request']['steps']['manager']);
        $lateSite = $this->pdo(AgencySiteCode::database($late));
        $lateSite->exec("UPDATE \"user\" SET is_verified = true WHERE email = 'tard-{$this->tag}@example.invalid'");
        $this->assertSame('done', $this->json('GET', '/api/platform/site-requests', null, 'super', 200)['requests'][0]['steps']['manager']);
        $this->assertStringContainsString('"ROLE_AGENCY"', (string) $lateSite->query("SELECT roles::text FROM \"user\" WHERE email = 'tard-{$this->tag}@example.invalid'")->fetchColumn());
    }

    private function sites(): PlatformSiteService
    {
        return static::getContainer()->get(PlatformSiteService::class);
    }

    private function clean(): void
    {
        foreach ($this->master->query("SELECT datname FROM pg_database WHERE datname LIKE 'db\\_agtest\\_%'")->fetchAll(\PDO::FETCH_COLUMN) as $db) {
            $this->master->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$db' AND pid <> pg_backend_pid()");
            $this->master->exec('DROP DATABASE IF EXISTS "' . $db . '"');
        }
        $this->master->exec("DELETE FROM tenant_entitlement_events WHERE tenant_code LIKE 'agtest-%'");
        $this->master->exec("DELETE FROM tenant_entitlements WHERE tenant_code LIKE 'agtest-%'");
        $this->master->exec("DELETE FROM tenant_app_keys WHERE tenant_code LIKE 'agtest-%'");
        foreach (['platform_site_requests', 'agency_site_requests', 'agency_events', 'agency_sites'] as $table) {
            $this->master->exec("DELETE FROM $table");
        }
        $this->master->exec('DELETE FROM agency_plans WHERE agency_id IS NOT NULL');
        $this->master->exec('DELETE FROM agencies');
        $this->master->exec("DELETE FROM tenants WHERE code LIKE 'agtest-%'");
        static::getContainer()->get('cache.app.taggable')->invalidateTags(['tenants']);
        $site = $this->pdo(MV_TEST_TENANT_DB);
        $site->exec("DELETE FROM refresh_tokens WHERE username LIKE 'plateforme-sites-%@example.invalid'");
        $site->exec("DELETE FROM \"user\" WHERE email LIKE 'plateforme-sites-%@example.invalid'");
    }

    private function pdo(string $db): \PDO
    {
        return static::getContainer()->get(\App\Services\Platform\TenantDatabaseConnector::class)->pdo($db);
    }

    private function loginAs(array $roles): void
    {
        $email = 'plateforme-sites-ecran-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Écran')->setLastname('Sites')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $path, ?array $body, ?string $as, int $expected): array
    {
        $response = $this->api($method, $path, $body, $as);
        $this->assertSame($expected, $response->getStatusCode(), "$method $path : " . substr((string) $response->getContent(), 0, 500));

        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function api(string $method, string $path, ?array $body, ?string $as, string $host = MV_TEST_TENANT_HOST): Response
    {
        $this->client->getCookieJar()->clear();
        $server = ['HTTP_X_TENANT_HOST' => $host, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test', 'REMOTE_ADDR' => '10.87.' . random_int(1, 254) . '.' . random_int(1, 254)];
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token($as);
        }
        $this->client->request($method, 'https://' . $host . $path, [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    /** Jeton d'un compte du site de test : `super` ou `admin` */
    private function token(string $as): string
    {
        if (isset($this->tokens[$as])) {
            return $this->tokens[$as];
        }
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $email = 'plateforme-sites-' . $as . '-' . $this->tag . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Sites')->setLastname('Test')->setRoles($as === 'super' ? ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] : ['ROLE_ADMIN'])->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();

        return $this->tokens[$as] = $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
