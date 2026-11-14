<?php

namespace App\Tests\Functional\Security;

use App\Entity\AppProfile;
use App\Entity\User;
use App\Security\AppFamilies;
use App\Services\AppMembershipService\AppJoinTokens;
use App\Services\AppMembershipService\AppMembershipException;
use App\Services\AppMembershipService\AppMembershipService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * APPS-MEMBRES, lot M3 (14/11/2026, docs/apps-membres.md § 6) : mode du site (« observe » ou « require », réglé par la commande ou la
 * fiche Entreprise, « require » refusé sans reprise faite), refus APP_MEMBERSHIP_REQUIRED à la connexion et à la vérification du code
 * (jamais au rafraîchissement, jamais pour l'équipe, jamais sans `app`), jeton de passage à usage unique, « rejoindre » qui rend la
 * session comme une connexion, acceptation des conditions d'un invité de l'équipe, quitter une famille (caisse : fiche détachée).
 */
class AppMembershipJoinApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $ip = null;
    private ?int $createdTranslation = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->db->executeStatement("UPDATE entreprise SET email = 'direction@mvtest.test', currency = 'CAD'");
        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->reset();
        parent::tearDown();
    }

    public function testTheModeIsAlsoSetInTheEntrepriseFileBySuperAdministratorsOnly(): void
    {
        $id = (int) $this->db->fetchOne('SELECT MIN(id) FROM entreprise');
        $edit = function (array $roles) use ($id): \Symfony\Component\DomCrawler\Crawler {
            $admin = $this->user($roles);
            $this->client->loginUser($admin, 'main');
            \App\Tests\Functional\Support\OtpTestHelper::validateIfRequired($this->client, $admin); // super administrateur : code toujours exigé
            $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'edit', 'crudControllerFqcn' => \App\Controller\Admin\EntrepriseCrudController::class, 'entityId' => $id]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());

            return $crawler;
        };
        $journal = fn (): int => (int) $this->db->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'user_app' AND action = 'mode'");
        $before = $journal();

        // L'administrateur du site voit le réglage, sans pouvoir le changer
        $field = $edit(['ROLE_ADMIN'])->filter('select[name="Entreprise[appMembershipMode]"]');
        $this->assertSame([1, 'disabled', ['observe', 'require']], [$field->count(), $field->attr('disabled'), $field->filter('option')->each(fn ($o) => $o->attr('value'))]);
        $this->assertStringContainsString('Modifiable uniquement par le super administrateur.', (string) $this->client->getResponse()->getContent());

        // Le super administrateur : « Exiger » est refusé tant que la reprise n'est pas faite, puis accepté ; au journal
        $save = function (string $mode) use ($edit): string {
            $crawler = $edit(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
            $this->assertNull($crawler->filter('select[name="Entreprise[appMembershipMode]"]')->attr('disabled'));
            $form = $crawler->filter('form[name="Entreprise"]')->form();
            $form['Entreprise[appMembershipMode]']->select($mode);
            $this->client->submit($form, [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertLessThan(400, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 600));

            return $this->mode();
        };
        $this->assertSame(['observe', $before], [$save('require'), $journal()], 'reprise pas faite : le mode reste « observe », rien au journal');
        $this->db->executeStatement('UPDATE entreprise SET app_membership_backfilled_at = NOW()');
        $this->assertSame(['require', $before + 1], [$save('require'), $journal()]);
        $this->assertSame('{"mode":"require"}', $this->db->fetchOne("SELECT after_state FROM content_audit_log WHERE resource = 'user_app' AND action = 'mode' ORDER BY id DESC LIMIT 1"));
        $this->assertSame(['require', $before + 1], [$save('require'), $journal()], 'enregistrer sans changer : rien de plus au journal');
        $this->assertSame(['observe', $before + 2], [$save('observe'), $journal()]);
    }

    /** Le site de test revient à l'état d'avant : mode « observe », reprise pas faite, aucun texte de conditions */
    private function reset(): void
    {
        $this->db->executeStatement("UPDATE entreprise SET app_membership_mode = 'observe', app_membership_backfilled_at = NULL");
        $this->noTerms();
    }

    /** @return array{0: int, 1: string} */
    private function command(array $arguments): array
    {
        $tester = new CommandTester((new Application(static::getContainer()->get('kernel')))->find('app:members:mode'));
        $status = $tester->execute($arguments);
        static::getContainer()->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return [$status, preg_replace('/\s+/', ' ', $tester->getDisplay())];
    }

    private function mode(): string
    {
        return (string) $this->db->fetchOne('SELECT app_membership_mode FROM entreprise ORDER BY id LIMIT 1');
    }

    private function login(User $user, array $extra, string $platform = 'mobile'): Response
    {
        return $this->request('POST', '/api/login', ['username' => $user->getEmail(), 'password' => self::PASSWORD, 'platform' => $platform] + $extra);
    }

    public function testTheModeOfASiteIsSetByTheCommandAndRequireNeedsTheBackfill(): void
    {
        $journal = fn (): array => $this->db->fetchAllAssociative("SELECT before_state, after_state, fields FROM content_audit_log WHERE resource = 'user_app' AND action = 'mode' ORDER BY id");
        $before = count($journal());
        [$status, $display] = $this->command(['site' => MV_TEST_TENANT_CODE]);
        $this->assertSame([0, true], [$status, str_contains($display, 'mode « observe » ; reprise de l\'historique pas faite')], $display);

        // « require » est refusé tant que la reprise de l'historique n'est pas faite
        [$status, $display] = $this->command(['site' => MV_TEST_TENANT_CODE, 'mode' => 'require']);
        $this->assertSame([1, true, 'observe', $before], [$status, str_contains($display, 'reprise de l\'historique des appartenances de ce site doit être faite'), $this->mode(), count($journal())], $display);
        $this->db->executeStatement('UPDATE entreprise SET app_membership_backfilled_at = NOW()');
        [$status, $display] = $this->command(['site' => MV_TEST_TENANT_CODE, 'mode' => 'require']);
        $this->assertSame([0, true, 'require'], [$status, str_contains($display, 'mode « require » (changé, au journal) ; reprise de l\'historique faite'), $this->mode()], $display);
        $last = $journal()[$before];
        $this->assertSame(['{"mode":"observe"}', '{"mode":"require"}', 'require', $before + 1], [$last['before_state'], $last['after_state'], $last['fields'], count($journal())]);
        // redire le même mode ne change rien et n'écrit rien ; revenir en « observe » est toujours possible
        [$status, $display] = $this->command(['site' => MV_TEST_TENANT_CODE, 'mode' => 'require']);
        $this->assertSame([0, false, $before + 1], [$status, str_contains($display, 'changé'), count($journal())]);
        $this->db->executeStatement('UPDATE entreprise SET app_membership_backfilled_at = NULL');
        [$status, $display] = $this->command(['site' => MV_TEST_TENANT_CODE, 'mode' => 'observe']);
        $this->assertSame([0, 'observe', $before + 2, '{"mode":"observe"}'], [$status, $this->mode(), count($journal()), $journal()[$before + 1]['after_state']]);
        // un mode inconnu (« strict » n'existe pas encore), un site inconnu
        [$status, $display] = $this->command(['site' => MV_TEST_TENANT_CODE, 'mode' => 'strict']);
        $this->assertSame([1, true, 'observe'], [$status, str_contains($display, 'Mode inconnu : « strict »'), $this->mode()]);
        [$status, $display] = $this->command(['site' => 'site-inexistant']);
        $this->assertSame([1, true], [$status, str_contains($display, 'absent de la table tenants')]);
        // le service dit le code de chaque refus
        $service = static::getContainer()->get(AppMembershipService::class);
        foreach ([['require', 'BACKFILL_REQUIRED', 409], ['strict', 'MODE_INVALID', 422]] as [$mode, $code, $http]) {
            try {
                $service->changeMode($mode);
                $this->fail("mode $mode accepté");
            } catch (AppMembershipException $e) {
                $this->assertSame([$code, $http, $code], [$e->errorCode, $e->status, $e->body()['code']]);
            }
        }
        $this->assertFalse($service->changeMode('observe'), 'déjà en « observe »');
    }

    public function testInRequireModeAClientJoinsAFamilyBeforeGettingASession(): void
    {
        $service = static::getContainer()->get(AppMembershipService::class);
        $member = $this->user(['ROLE_USER', 'ROLE_USER_INTERNET']);
        $service->grant($member, AppFamilies::BOUTIQUE, AppFamilies::SOURCE_BACKFILL); // appartenance reprise, sans version : acquise, rien ne lui est demandé
        $stranger = $this->user(['ROLE_USER', 'ROLE_USER_INTERNET']);
        $team = $this->user(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $session = fn (Response $r): array => [$r->getStatusCode(), self::claims($this->body($r)['token'] ?? 'x.e30.x')['app'] ?? null];

        // Mode « observe » : personne n'est refusé
        $this->assertSame([200, 'boutique'], $session($this->login($stranger, ['app' => 'web'])));
        $this->db->executeStatement("UPDATE entreprise SET app_membership_backfilled_at = NOW(), app_membership_mode = 'require'");
        $this->db->executeStatement('UPDATE "user" SET last_login_at = NULL WHERE id = ?', [$stranger->getId()]);
        $this->db->executeStatement('DELETE FROM refresh_tokens WHERE username = ?', [$stranger->getEmail()]);

        // Mode « require ». Passent comme avant : un membre, un compte d'équipe, une connexion sans `app` ou au code inconnu
        $this->assertSame([[200, 'boutique'], [200, 'boutique'], [200, null], [200, null]], [$session($this->login($member, ['app' => 'web'])), $session($this->login($team, ['app' => 'web'])),
            $session($this->login($stranger, [])), $session($this->login($stranger, ['app' => 'gestion']))]);
        $this->db->executeStatement('UPDATE "user" SET last_login_at = NULL WHERE id = ?', [$stranger->getId()]);
        $this->db->executeStatement('DELETE FROM refresh_tokens WHERE username = ?', [$stranger->getEmail()]);

        // Refusé : un compte client qui n'est pas membre de la famille. Ni session, ni cookie, ni jeton de rafraîchissement, ni connexion marquée, ni appartenance
        $refused = $this->login($stranger, ['app' => 'mobile']);
        $body = $this->body($refused);
        $this->assertSame([403, ['error', 'code', 'message', 'app', 'reason', 'terms', 'joinToken', 'expiresAt']], [$refused->getStatusCode(), array_keys($body)]);
        $this->assertSame(['APP_MEMBERSHIP_REQUIRED', 'APP_MEMBERSHIP_REQUIRED', 'boutique', 'membership', ['version' => null, 'locale' => 'fr', 'privacyPolicy' => null, 'termsOfUse' => null, 'legalNotice' => null, 'privacyPolicyUrl' => null, 'updatedAt' => null]],
            [$body['error'], $body['code'], $body['app'], $body['reason'], $body['terms']]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['joinToken']);
        $left = strtotime($body['expiresAt']) - time();
        $this->assertTrue($left > 590 && $left <= 600, "10 minutes ($left s)");
        $this->assertSame([[], null, 0, []], [$refused->headers->getCookies(), $this->db->fetchOne('SELECT last_login_at FROM "user" WHERE id = ?', [$stranger->getId()]),
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM refresh_tokens WHERE username = ?', [$stranger->getEmail()]), $this->rows((int) $stranger->getId())]);
        $web = $this->login($stranger, ['app' => 'web'], 'web');
        $this->assertSame([403, []], [$web->getStatusCode(), $web->headers->getCookies()], 'sur le web non plus, aucun cookie');

        // « Rejoindre » : sans jeton, avec un faux jeton, sans accepter, avec une version qui n'est pas celle en vigueur : rien n'est créé
        $join = fn (array $b): Response => $this->request('POST', '/api/apps/join', $b);
        $error = fn (Response $r): array => [$r->getStatusCode(), $this->body($r)['code'] ?? null];
        $token = $body['joinToken'];
        $this->assertSame([[400, 'FIELDS_REQUIRED'], [401, 'JOIN_TOKEN_INVALID'], [401, 'JOIN_TOKEN_INVALID'], [422, 'TERMS_NOT_ACCEPTED'], [422, 'TERMS_NOT_ACCEPTED']],
            [$error($join(['accepted' => true])), $error($join(['joinToken' => str_repeat('a', 64), 'accepted' => true])), $error($join(['joinToken' => 'pas-un-jeton', 'accepted' => true])),
                $error($join(['joinToken' => $token])), $error($join(['joinToken' => $token, 'accepted' => 'true']))]);
        $changed = $join(['joinToken' => $token, 'termsVersion' => 'version-perimee', 'accepted' => true]);
        $this->assertSame([409, 'TERMS_VERSION_CHANGED', 'boutique', null, []], [$changed->getStatusCode(), $this->body($changed)['code'], $this->body($changed)['app'], $this->body($changed)['terms']['version'], $this->rows((int) $stranger->getId())]);

        // Il accepte (la famille n'a aucun texte : aucune version) : appartenance créée, au journal, et la session est rendue comme une connexion
        $joined = $join(['joinToken' => $token, 'accepted' => true]);
        $this->assertSame(200, $joined->getStatusCode(), (string) $joined->getContent());
        $tokens = $this->body($joined);
        $this->assertSame(['token', 'refresh_token'], array_keys($tokens));
        $claims = self::claims($tokens['token']);
        $this->assertSame([(int) $stranger->getId(), $stranger->getEmail(), 'boutique'], [$claims['id'], $claims['email'], $claims['app']]);
        $this->assertSame([['app' => 'boutique', 'source' => 'join', 'terms_version' => null, 'terms_accepted_at' => null]], $this->rows((int) $stranger->getId()));
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'user_app' AND resource_id = ? AND action = 'grant' AND fields = 'boutique,join'", [(string) $stranger->getId()]));
        $this->assertNotNull($this->db->fetchOne('SELECT last_login_at FROM "user" WHERE id = ?', [$stranger->getId()]), 'rejoindre vaut connexion');
        $this->assertSame(['team' => false, 'memberships' => 1], array_map(fn ($v) => is_array($v) ? count($v) : $v, $this->json('GET', '/api/account/memberships', null, $tokens['token'])), 'la session rendue est une vraie session');
        $this->assertSame(200, $this->json('POST', '/api/token/refresh', ['refresh_token' => $tokens['refresh_token'], 'app' => 'web']) !== [] ? 200 : 0);
        // le jeton de passage ne sert qu'une fois ; ensuite la connexion passe sans étape
        $this->assertSame([401, 'JOIN_TOKEN_INVALID'], $error($join(['joinToken' => $token, 'accepted' => true])));
        $this->assertSame([200, 'boutique'], $session($this->login($stranger, ['app' => 'web'])));

        // Avec des conditions : la version en vigueur est exigée et enregistrée ; le jeton reste valable après « version changée »
        $this->db->executeStatement("UPDATE entreprise SET privacy_policy = 'Politique du site'");
        $version = $this->json('GET', '/api/apps/memoires/terms')['version'];
        $refused = $this->body($this->login($stranger, ['app' => 'livingmemory'], 'web'));
        $this->assertSame(['memoires', 'membership', $version, 'Politique du site'], [$refused['app'], $refused['reason'], $refused['terms']['version'], $refused['terms']['privacyPolicy']]);
        $stale = $join(['joinToken' => $refused['joinToken'], 'accepted' => true]);
        $this->assertSame([409, 'TERMS_VERSION_CHANGED', $version], [$stale->getStatusCode(), $this->body($stale)['code'], $this->body($stale)['terms']['version']], 'sans version alors que la famille en a une');
        $joined = $join(['joinToken' => $refused['joinToken'], 'termsVersion' => $version, 'accepted' => true]);
        $this->assertSame(200, $joined->getStatusCode(), (string) $joined->getContent());
        // la plateforme de la connexion est gardée : ici le web, donc ses cookies, avec la famille dans le jeton
        $cookies = array_column(array_map(fn ($c) => [$c->getName(), $c->getValue()], $joined->headers->getCookies()), 1, 0);
        $this->assertSame(['memoires', true, true], [self::claims($cookies['auth_token_' . MV_TEST_TENANT_CODE])['app'], isset($cookies['refresh_token_' . MV_TEST_TENANT_CODE]), isset($this->body($joined)['csrf_token'])]);
        $row = $this->db->fetchAssociative("SELECT source, terms_version, terms_accepted_at IS NOT NULL AS accepted FROM user_app_membership WHERE user_id = ? AND app = 'memoires'", [$stranger->getId()]);
        $this->assertSame(['source' => 'join', 'terms_version' => $version, 'accepted' => true], $row);

        // Le rafraîchissement ne refuse jamais : aucune session ouverte n'est coupée
        $outsider = $this->user(['ROLE_USER', 'ROLE_USER_INTERNET']);
        $open = $this->body($this->login($outsider, []));
        $this->assertSame('boutique', self::claims($this->json('POST', '/api/token/refresh', ['refresh_token' => $open['refresh_token'], 'app' => 'web'])['token'])['app']);
        $this->assertSame([], $this->rows((int) $outsider->getId()));

        // Vérification du code (double vérification) : le refus arrive après le code, puis « rejoindre » rend la session
        $otp = $this->user(['ROLE_USER', 'ROLE_USER_INTERNET'], true, true);
        $this->assertTrue($this->body($this->login($otp, ['app' => 'web']))['otp_required']);
        $code = $this->db->fetchOne('SELECT code FROM otp_code WHERE user_otp_id = ? ORDER BY id DESC LIMIT 1', [$otp->getId()]);
        $afterCode = $this->request('POST', '/api/otp-verify', ['username' => $otp->getEmail(), 'otp' => $code, 'platform' => 'mobile', 'app' => 'web']);
        $this->assertSame([403, 'APP_MEMBERSHIP_REQUIRED', 'membership', null], [$afterCode->getStatusCode(), $this->body($afterCode)['code'], $this->body($afterCode)['reason'], $this->db->fetchOne('SELECT last_login_at FROM "user" WHERE id = ?', [$otp->getId()])]);
        $boutique = $this->json('GET', '/api/apps/web/terms')['version'];
        $this->assertSame('boutique', self::claims($this->body($join(['joinToken' => $this->body($afterCode)['joinToken'], 'termsVersion' => $boutique, 'accepted' => true]))['token'])['app']);

        // Un jeton d'un autre site, un jeton expiré, le jeton d'un compte effacé depuis : refusés
        $tokensService = static::getContainer()->get(AppJoinTokens::class);
        $pool = static::getContainer()->get('cache.rate_limiter');
        $forge = function (array $data) use ($pool): string {
            $forged = bin2hex(random_bytes(32));
            $pool->save($pool->getItem('app_join.' . hash('sha256', $forged))->set($data)->expiresAfter(600));

            return $forged;
        };
        $valid = ['site' => MV_TEST_TENANT_CODE, 'userId' => (int) $outsider->getId(), 'app' => 'boutique', 'platform' => 'mobile', 'reason' => 'membership', 'expiresAt' => time() + 300];
        $this->assertSame([[401, 'JOIN_TOKEN_INVALID'], [401, 'JOIN_TOKEN_INVALID']],
            [$error($join(['joinToken' => $forge(['site' => 'un-autre-site'] + $valid), 'termsVersion' => $boutique, 'accepted' => true])), $error($join(['joinToken' => $forge(['expiresAt' => time() - 1] + $valid), 'termsVersion' => $boutique, 'accepted' => true]))]);
        $this->assertSame([], $this->rows((int) $outsider->getId()));
        $good = $forge($valid);
        $this->assertNotNull($tokensService->read($good));
        $this->db->executeStatement('UPDATE "user" SET deleted_at = NOW() WHERE id = ?', [$outsider->getId()]);
        $this->assertSame([[401, 'JOIN_TOKEN_INVALID'], []], [$error($join(['joinToken' => $good, 'termsVersion' => $boutique, 'accepted' => true])), $this->rows((int) $outsider->getId())]);
    }

    public function testAnAccountInvitedByTheTeamAcceptsTheTermsAtItsFirstLogin(): void
    {
        $service = static::getContainer()->get(AppMembershipService::class);
        $invited = $this->user(['ROLE_USER', 'ROLE_USER_INTERNET']);
        $service->grant($invited, AppFamilies::MEMOIRES, AppFamilies::SOURCE_INVITE, null, new \DateTimeImmutable('2026-02-03 04:05:06'));
        $registered = $this->user(['ROLE_USER', 'ROLE_USER_INTERNET']);
        $service->grant($registered, AppFamilies::MEMOIRES, AppFamilies::SOURCE_REGISTER); // inscrit sans version envoyée : rien ne lui est demandé
        $this->db->executeStatement("UPDATE entreprise SET app_membership_backfilled_at = NOW(), app_membership_mode = 'require'");

        // La famille n'a aucun texte : il n'y a rien à accepter, l'invité entre
        $this->assertSame(200, $this->login($invited, ['app' => 'memoires'])->getStatusCode());
        // La famille a des conditions : l'invité les accepte à sa première connexion (reason « terms ») ; l'inscrit n'est pas concerné
        $this->profile('memoires', 'Politique de Mémoires Vivantes');
        $version = $this->json('GET', '/api/apps/memoires/terms')['version'];
        $this->assertSame(200, $this->login($registered, ['app' => 'livingmemory'])->getStatusCode());
        $refused = $this->login($invited, ['app' => 'livingmemory']);
        $body = $this->body($refused);
        $this->assertSame([403, 'APP_MEMBERSHIP_REQUIRED', 'memoires', 'terms', $version, 'Politique de Mémoires Vivantes', 'Acceptez les conditions de cette application pour continuer.'],
            [$refused->getStatusCode(), $body['code'], $body['app'], $body['reason'], $body['terms']['version'], $body['terms']['privacyPolicy'], $body['message']]);
        $joined = $this->request('POST', '/api/apps/join', ['joinToken' => $body['joinToken'], 'termsVersion' => $version, 'accepted' => true]);
        $this->assertSame([200, 'memoires'], [$joined->getStatusCode(), self::claims($this->body($joined)['token'])['app']]);
        // l'acceptation est enregistrée sur l'appartenance qu'il avait : sa source et sa date d'entrée ne changent pas ; au journal
        $row = $this->db->fetchAssociative("SELECT source, joined_at, terms_version, terms_accepted_at IS NOT NULL AS accepted FROM user_app_membership WHERE user_id = ?", [$invited->getId()]);
        $this->assertSame(['source' => 'invite', 'joined_at' => '2026-02-03 04:05:06', 'terms_version' => $version, 'accepted' => true], $row);
        $journal = $this->db->fetchAssociative("SELECT action, fields, after_state FROM content_audit_log WHERE resource = 'user_app' AND resource_id = ? ORDER BY id DESC LIMIT 1", [(string) $invited->getId()]);
        $this->assertSame(['accept', 'memoires,accept', ['app' => 'memoires', 'termsVersion' => $version]], [$journal['action'], $journal['fields'], json_decode($journal['after_state'], true)]);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_app_membership WHERE user_id = ?', [$invited->getId()]));
        $this->assertSame(200, $this->login($invited, ['app' => 'memoires'])->getStatusCode(), 'ensuite il entre sans étape');
    }

    public function testAClientLeavesAFamilyWithoutDeletingTheAccount(): void
    {
        $service = static::getContainer()->get(AppMembershipService::class);
        $client = $this->user(['ROLE_USER', 'ROLE_USER_INTERNET']);
        foreach ([AppFamilies::BOUTIQUE, AppFamilies::MEMOIRES, AppFamilies::POS] as $family) {
            $service->grant($client, $family, AppFamilies::SOURCE_REGISTER);
        }
        $file = (int) $this->db->fetchOne("INSERT INTO pos_customer (user_id, first_name, last_name, created_at, updated_at) VALUES (?, 'Quitte', 'La caisse', NOW(), NOW()) RETURNING id", [$client->getId()]);
        $this->db->executeStatement("INSERT INTO pos_customer_consent (customer_id, purpose, granted, at, text_version) VALUES (?, 'account_link', true, '2025-01-02 03:04:05', '2025-01')", [$file]);
        $token = $this->body($this->login($client, []))['token'];
        $families = fn (array $r): array => array_column($r['memberships'], 'app');
        $error = fn (Response $r): array => [$r->getStatusCode(), $this->body($r)['code'] ?? null];
        $revoked = fn (string $family): int => (int) $this->db->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'user_app' AND resource_id = ? AND action = 'revoke' AND fields = ?", [(string) $client->getId(), $family . ',left']);

        $this->assertSame([401, [404, 'APP_UNKNOWN']], [$this->request('DELETE', '/api/account/memberships/web')->getStatusCode(), $error($this->request('DELETE', '/api/account/memberships/gestion', null, $token))]);
        // Il quitte la boutique (par le code de son application) : l'appartenance part, au journal ; rien d'autre ne change
        $left = $this->json('DELETE', '/api/account/memberships/web', null, $token);
        $this->assertSame([false, ['memoires', 'pos'], 1], [$left['team'], $families($left), $revoked('boutique')]);
        $this->assertSame([404, 'MEMBERSHIP_NOT_FOUND'], $error($this->request('DELETE', '/api/account/memberships/boutique', null, $token)), 'déjà parti');
        $this->assertSame(1, $revoked('boutique'));
        // Il quitte la caisse : c'est le retrait de son consentement de rattachement ; la fiche est détachée et le retrait y est enregistré
        $left = $this->json('DELETE', '/api/account/memberships/pos', null, $token);
        $this->assertSame([['memoires'], 1, null], [$families($left), $revoked('pos'), $this->db->fetchOne('SELECT user_id FROM pos_customer WHERE id = ?', [$file])]);
        $this->assertSame(['granted' => false, 'source' => 'account', 'by_user_id' => $client->getId()], $this->db->fetchAssociative("SELECT granted, source, by_user_id FROM pos_customer_consent WHERE customer_id = ? AND purpose = 'account_link' ORDER BY id DESC LIMIT 1", [$file]));
        $audit = (string) $this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'CUSTOMER_UPDATED' ORDER BY id DESC LIMIT 1");
        $this->assertStringContainsString('"by":"account"', $audit);
        $this->assertStringContainsString('"unlinked":true', $audit);
        // Un membre de la caisse sans fiche rattachée (fiche fusionnée entre-temps) part aussi, sans erreur
        $service->grant($client, AppFamilies::POS, AppFamilies::SOURCE_POS_LINK);
        $this->assertSame(['memoires'], $families($this->json('DELETE', '/api/account/memberships/pos', null, $token)));
        // Un compte d'équipe n'a pas d'appartenance
        $team = $this->user(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $this->assertSame([404, 'MEMBERSHIP_NOT_FOUND'], $error($this->request('DELETE', '/api/account/memberships/web', null, $this->body($this->login($team, []))['token'])));

        // Pour revenir, il faudra « rejoindre » : en mode « require », la connexion à la boutique lui est refusée
        $this->db->executeStatement("UPDATE entreprise SET app_membership_backfilled_at = NOW(), app_membership_mode = 'require'");
        $this->assertSame([[403, 'APP_MEMBERSHIP_REQUIRED'], 200], [$error($this->login($client, ['app' => 'web'])), $this->login($client, ['app' => 'memoires'])->getStatusCode()]);
    }

    // ---------------------------------------------------------------- aides

    /** Aucun texte de conditions sur le site de test (les autres tests n'en dépendent pas) */
    private function noTerms(): void
    {
        $this->db->executeStatement('UPDATE entreprise SET privacy_policy = NULL, condition_of_use = NULL, legal_notice = NULL');
        if ($this->createdTranslation !== null) {
            $this->db->executeStatement('DELETE FROM entreprise_translation WHERE id = ?', [$this->createdTranslation]);
            $this->createdTranslation = null;
        }
        $this->db->executeStatement('UPDATE entreprise_translation SET privacy_policy = NULL, condition_of_use = NULL, legal_notice = NULL');
        $this->db->executeStatement('UPDATE app_profile SET privacy_policy = NULL, condition_of_use = NULL, legal_notice = NULL');
        $this->db->executeStatement('UPDATE app_profile_translation SET privacy_policy = NULL, condition_of_use = NULL, legal_notice = NULL');
    }

    private function profile(string $app, string $privacyPolicy): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $profile = $em->getRepository(AppProfile::class)->findOneBy(['app' => $app]) ?? (new AppProfile())->setApp($app);
        $profile->setPrivacyPolicy($privacyPolicy);
        $em->persist($profile);
        $em->flush();
    }

    /** @return list<array{app: string, source: string, terms_version: ?string, terms_accepted_at: ?string}> */
    private function rows(int $userId): array
    {
        return $this->db->fetchAllAssociative('SELECT app, source, terms_version, terms_accepted_at FROM user_app_membership WHERE user_id = ? ORDER BY joined_at, id', [$userId]);
    }

    /** @return array<string, mixed> champs du jeton d'accès */
    private static function claims(string $jwt): array
    {
        return json_decode((string) base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true);
    }

    /** @param list<int> $expected */
    private function json(string $method, string $path, ?array $body = null, ?string $bearer = null, array $expected = [200]): array
    {
        $response = $this->request($method, $path, $body, $bearer);
        $this->assertContains($response->getStatusCode(), $expected, $method . ' ' . $path . ' : ' . $response->getContent());

        return $this->body($response);
    }

    private function body(Response $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }

    private function request(string $method, string $path, ?array $body = null, ?string $bearer = null): Response
    {
        // IP propre au test : les limites de débit (Redis) survivent d'un lancement à l'autre
        $this->ip ??= '10.83.' . random_int(1, 254) . '.' . random_int(1, 254);
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $bearer ? 'Bearer ' . $bearer : null, 'REMOTE_ADDR' => $this->ip,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    /** Compte vérifié, au mot de passe connu ; `$passwordSet` faux : compte né d'une commande en invité */
    private function user(array $roles, bool $passwordSet = true, bool $otp = false): User
    {
        $email = 'client-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Client')->setLastname('Test')->setRoles($roles)->setIsVerified(true)->setPasswordSet($passwordSet)->setOtpEnabled($otp);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }
}
