<?php

namespace App\Tests\Functional\Security;

use App\Entity\AppProfile;
use App\Entity\User;
use App\Security\AppFamilies;
use App\Services\AccountService\PersonalDataEraser;
use App\Services\AppMembershipService\AppMembershipService;
use App\Services\AppMembershipService\AppTermsService;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * APPS-MEMBRES, lot M1 (14/11/2026, docs/apps-membres.md) : trois familles d'applications (boutique, memoires, pos), conditions
 * d'une famille et leur version, appartenances créées aux entrées (inscription dans une application, invitation par l'équipe,
 * commande en invité suivie du choix d'un mot de passe ; le rattachement de caisse est dans PosCustomerApiTest), routes de lecture,
 * mode « observer » : aucune connexion n'est refusée, la famille est portée par le jeton.
 */
class AppMembershipApiTest extends WebTestCase
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
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'Canada', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->noTerms();
        FakeCheckoutPaymentGateway::reset();
    }

    protected function tearDown(): void
    {
        $this->noTerms();
        parent::tearDown();
    }

    public function testTheTermsOfAFamilyAndTheirVersion(): void
    {
        $unknown = $this->request('GET', '/api/apps/boussole/terms');
        $this->assertSame([404, 'APP_UNKNOWN', AppFamilies::ALL], [$unknown->getStatusCode(), $this->body($unknown)['code'], $this->body($unknown)['params']['families']], 'la Boussole et la gestion ne sont pas des familles à membres');

        // Aucun texte : aucune version, il n'y a rien à accepter
        $empty = $this->json('GET', '/api/apps/web/terms');
        $this->assertSame(['app' => 'boutique', 'version' => null, 'locale' => 'fr', 'privacyPolicy' => null, 'termsOfUse' => null, 'legalNotice' => null, 'privacyPolicyUrl' => null, 'updatedAt' => null], $empty);

        // Le web et le mobile d'une même famille ont les mêmes conditions : celles de la fiche Entreprise pour la boutique
        $this->db->executeStatement("UPDATE entreprise SET privacy_policy = 'Politique du site', condition_of_use = 'Conditions du site'");
        $web = $this->json('GET', '/api/apps/web/terms');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $web['version']);
        $this->assertSame(['boutique', 'Politique du site', 'Conditions du site', null], [$web['app'], $web['privacyPolicy'], $web['termsOfUse'], $web['legalNotice']]);
        foreach (['mobile', 'boutique', 'WEB'] as $code) {
            $this->assertSame($web, $this->json('GET', "/api/apps/$code/terms"), "$code : même famille, mêmes conditions, même version");
        }
        $v1 = $web['version'];

        // Mémoires Vivantes sans texte propre : ceux du site, donc la même version ; avec son texte : sa version, celle de la boutique ne bouge pas
        foreach (['memoires', 'livingmemory'] as $code) {
            $mv = $this->json('GET', "/api/apps/$code/terms");
            $this->assertSame(['memoires', $v1, 'Politique du site'], [$mv['app'], $mv['version'], $mv['privacyPolicy']], $code);
        }
        $this->profile('memoires', 'Politique de Mémoires Vivantes');
        $mv = $this->json('GET', '/api/apps/livingmemory/terms');
        $this->assertSame(['memoires', 'Politique de Mémoires Vivantes', 'Conditions du site'], [$mv['app'], $mv['privacyPolicy'], $mv['termsOfUse']], 'un champ vide du profil prend celui du site');
        $this->assertNotSame($v1, $mv['version']);
        $this->assertSame($v1, $this->json('GET', '/api/apps/web/terms')['version']);
        $this->assertSame('pos', $this->json('GET', '/api/apps/pos/terms')['app']);

        // La version change dès qu'un texte change, dans n'importe quelle langue ; elle est la même quelle que soit la langue demandée
        // (la traduction anglaise de la fiche existe peut-être déjà, laissée par un autre test : on la complète, sinon on la crée)
        if ($this->db->executeStatement("UPDATE entreprise_translation SET privacy_policy = 'Site policy' WHERE language = 'en' AND entreprise_id = (SELECT MIN(id) FROM entreprise)") === 0) {
            $this->createdTranslation = (int) $this->db->fetchOne("INSERT INTO entreprise_translation (id, entreprise_id, language, privacy_policy) SELECT nextval('entreprise_translation_id_seq'), MIN(id), 'en', 'Site policy' FROM entreprise RETURNING id");
        }
        $fr = $this->json('GET', '/api/apps/web/terms?locale=fr');
        $en = $this->json('GET', '/api/apps/web/terms?locale=en');
        $this->assertSame([$fr['version'], 'en', 'Site policy', 'Politique du site'], [$en['version'], $en['locale'], $en['privacyPolicy'], $fr['privacyPolicy']]);
        $this->assertNotSame($v1, $fr['version'], 'un texte anglais de plus : nouvelle version');
        $this->assertSame($fr, $this->json('GET', '/api/apps/web/terms?locale=de'), 'langue inconnue : la réponse française, qui le dit (locale « fr »)');
        // Le profil de l'application mobile de la boutique porte son image de marque, pas les conditions de la famille : celles du site
        $this->profile('mobile', 'Politique propre au profil mobile');
        $this->assertSame($fr, $this->json('GET', '/api/apps/mobile/terms?locale=fr'));
        $this->assertSame($mv['version'], $this->json('GET', '/api/apps/memoires/terms')['version'], 'la politique du site ne compte plus pour Mémoires Vivantes, qui a la sienne');
        $this->db->executeStatement("UPDATE entreprise SET condition_of_use = 'Conditions du site, revues'");
        $this->assertNotSame($mv['version'], $this->json('GET', '/api/apps/memoires/terms')['version'], 'les conditions du site s\'affichent encore pour Mémoires Vivantes : sa version change');

        $service = static::getContainer()->get(AppTermsService::class);
        $this->assertSame($this->json('GET', '/api/apps/web/terms')['version'], $service->version(AppFamilies::BOUTIQUE));
    }

    public function testRegisteringInAnApplicationEntersItsFamily(): void
    {
        $this->db->executeStatement("UPDATE entreprise SET privacy_policy = 'Politique du site'");
        $version = $this->json('GET', '/api/apps/web/terms')['version'];
        $audit = fn (int $id): array => $this->db->fetchAllAssociative("SELECT action, fields, after_state FROM content_audit_log WHERE resource = 'user_app' AND resource_id = ? ORDER BY id", [(string) $id]);

        // Living Memory, sans version envoyée : membre de la famille memoires, aucune acceptation inventée
        $living = $this->register(['app' => 'livingmemory']);
        $this->assertSame([['app' => 'memoires', 'source' => 'register', 'terms_version' => null, 'terms_accepted_at' => null]], $this->rows($living));
        $this->assertSame('mobile', $this->db->fetchOne('SELECT signup_channel FROM "user" WHERE id = ?', [$living]), 'origine du compte inchangée');
        $journal = $audit($living);
        $this->assertSame([1, 'grant', 'memoires,register'], [count($journal), $journal[0]['action'], $journal[0]['fields']]);
        $this->assertSame(['app' => 'memoires', 'source' => 'register', 'termsVersion' => null, 'termsAcceptedAt' => null], array_diff_key(json_decode($journal[0]['after_state'], true), ['joinedAt' => 1]));

        // Boutique web avec la version en vigueur : acceptation enregistrée ; avec une version périmée : appartenance sans acceptation
        $web = $this->register(['app' => 'web', 'termsVersion' => $version]);
        $row = $this->db->fetchAssociative('SELECT app, source, terms_version, terms_accepted_at, joined_at FROM user_app_membership WHERE user_id = ?', [$web]);
        $this->assertSame(['boutique', 'register', $version], [$row['app'], $row['source'], $row['terms_version']]);
        $this->assertSame($row['joined_at'], $row['terms_accepted_at'], 'acceptée à l\'inscription');
        $stale = $this->register(['app' => 'mobile', 'termsVersion' => 'version-perimee']);
        $this->assertSame([['app' => 'boutique', 'source' => 'register', 'terms_version' => null, 'terms_accepted_at' => null]], $this->rows($stale), 'on n\'enregistre pas l\'acceptation d\'un autre texte');
        // Chaque famille a SA version : celle de la boutique ne vaut pas acceptation des conditions de Mémoires Vivantes
        $this->profile('memoires', 'Politique de Mémoires Vivantes');
        $own = $this->json('GET', '/api/apps/memoires/terms')['version'];
        $this->assertNotSame($version, $own);
        $this->assertSame([['app' => 'memoires', 'source' => 'register', 'terms_version' => null, 'terms_accepted_at' => null]], $this->rows($this->register(['app' => 'memoires', 'termsVersion' => $version])));
        $this->assertSame([$own], array_column($this->rows($this->register(['app' => 'livingmemory', 'termsVersion' => $own])), 'terms_version'));

        // Sans `app`, ou avec un code inconnu : rien ne change, aucune appartenance
        $this->assertSame([], $this->rows($this->register([])));
        $this->assertSame([], $this->rows($this->register(['app' => 'gestion'])));
        $this->assertSame([], $this->rows($this->register(['app' => ['web']])));
    }

    public function testMembershipRulesOfTheService(): void
    {
        $service = static::getContainer()->get(AppMembershipService::class);
        $client = $this->user(['ROLE_USER', 'ROLE_USER_INTERNET']);
        $count = fn (User $u): int => (int) $this->db->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'user_app' AND resource_id = ?", [(string) $u->getId()]);

        $at = new \DateTimeImmutable('2026-03-04 10:00:00');
        $this->assertTrue($service->grant($client, AppFamilies::BOUTIQUE, AppFamilies::SOURCE_BACKFILL, null, $at));
        $this->assertFalse($service->grant($client, AppFamilies::BOUTIQUE, AppFamilies::SOURCE_REGISTER, 'v2'), 'déjà membre : rien ne change');
        $this->assertSame([['app' => 'boutique', 'source' => 'backfill', 'terms_version' => null, 'terms_accepted_at' => null]], $this->rows((int) $client->getId()));
        $this->assertSame('2026-03-04 10:00:00', $this->db->fetchOne('SELECT joined_at FROM user_app_membership WHERE user_id = ?', [$client->getId()]));
        $this->assertSame(1, $count($client), 'une seule entrée au journal');
        $this->assertTrue($service->grant($client, AppFamilies::POS, AppFamilies::SOURCE_POS_LINK, '2026-10', null, new \DateTimeImmutable('2026-10-02 09:00:00')));
        $this->assertSame(['2026-10', '2026-10-02 09:00:00'], array_values($this->db->fetchAssociative("SELECT terms_version, terms_accepted_at FROM user_app_membership WHERE user_id = ? AND app = 'pos'", [$client->getId()])));
        $this->assertSame([true, true, false], [$service->has($client, 'boutique'), $service->has($client, 'pos'), $service->has($client, 'memoires')]);
        $this->assertSame(['boutique', 'pos'], array_map(fn ($m) => $m->getApp(), $service->of($client)), 'de la plus ancienne à la plus récente');

        // Jamais membre : un compte d'équipe, quel que soit son rôle d'équipe ; un compte né d'une commande en invité sans mot de passe choisi
        foreach ([['ROLE_ADMIN', 'ROLE_USER_INTERNET'], ['ROLE_SUPER_ADMIN'], ['ROLE_USER_POS'], ['ROLE_POS_MANAGER'], ['ROLE_SUPPORT'], ['ROLE_USER_INTERNET', 'ROLE_MEMOIRES_BIOGRAPHE'], ['ROLE_USER_INTERNET', 'ROLE_BOUSSOLE_CONSEILLER']] as $roles) {
            $team = $this->user($roles);
            $this->assertTrue(AppFamilies::isTeam($team), implode(',', $roles));
            $this->assertFalse($service->grant($team, AppFamilies::BOUTIQUE, AppFamilies::SOURCE_REGISTER), implode(',', $roles));
            $this->assertSame([], $this->rows((int) $team->getId()));
        }
        $this->assertFalse(AppFamilies::isTeam($client));
        $guest = $this->user(['ROLE_USER_INTERNET'], false);
        $this->assertFalse($service->grant($guest, AppFamilies::BOUTIQUE, AppFamilies::SOURCE_GUEST_CHECKOUT), 'pas de mot de passe choisi : aucune appartenance');
        $this->assertSame([], $this->rows((int) $guest->getId()));

        foreach ([['gestion', AppFamilies::SOURCE_REGISTER], [AppFamilies::BOUTIQUE, 'cadeau']] as [$family, $source]) {
            try {
                $service->grant($client, $family, $source);
                $this->fail('famille ou source inconnue acceptée');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // Retrait : journalisé, idempotent ; effacement du compte : ses appartenances partent
        $this->assertTrue($service->revoke((int) $client->getId(), AppFamilies::POS, 'pos_customer_erased'));
        $this->assertFalse($service->revoke((int) $client->getId(), AppFamilies::POS, 'pos_customer_erased'));
        $this->assertSame(['boutique'], array_column($this->rows((int) $client->getId()), 'app'));
        $last = $this->db->fetchAssociative("SELECT action, fields, before_state, after_state FROM content_audit_log WHERE resource = 'user_app' AND resource_id = ? ORDER BY id DESC LIMIT 1", [(string) $client->getId()]);
        $this->assertSame(['revoke', 'pos,pos_customer_erased', '2026-10', null], [$last['action'], $last['fields'], json_decode($last['before_state'], true)['termsVersion'], json_decode((string) $last['after_state'], true)]);
        $this->assertSame(3, $count($client));
        $clientId = (int) $client->getId();
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager(); // les comptes créés depuis ont renouvelé le gestionnaire d'entités
        static::getContainer()->get(PersonalDataEraser::class)->erase($em->find(User::class, $clientId));
        $this->assertSame([], $this->rows($clientId), 'compte effacé : plus aucune appartenance');
        $erased = $em->find(User::class, $clientId);
        $this->assertSame([true, true], [$erased->isDeleted(), $erased->isPasswordSet()]);
        $this->assertFalse($service->grant($erased, AppFamilies::BOUTIQUE, AppFamilies::SOURCE_REGISTER), 'un compte effacé ne redevient jamais membre');
        $this->assertSame([], $this->rows($clientId));
    }

    public function testASessionCarriesItsFamilyAndObserveModeRefusesNothing(): void
    {
        $member = $this->user(['ROLE_USER', 'ROLE_USER_INTERNET']);
        static::getContainer()->get(AppMembershipService::class)->grant($member, AppFamilies::BOUTIQUE, AppFamilies::SOURCE_REGISTER);
        $login = fn (array $extra, string $email): array => $this->json('POST', '/api/login', ['username' => $email, 'password' => self::PASSWORD, 'platform' => 'mobile'] + $extra);

        // Sans `app` : exactement comme avant, le jeton ne porte pas de famille
        $plain = $login([], $member->getEmail());
        $this->assertArrayNotHasKey('app', self::claims($plain['token']));
        $this->assertArrayNotHasKey('app', self::claims($login(['app' => 'gestion'], $member->getEmail())['token']), 'code inconnu : ignoré');
        // Avec `app` : la famille est dans le jeton ; un compte qui n'est pas membre reçoit sa session quand même (mode « observer »)
        $this->assertSame('boutique', self::claims($login(['app' => 'web'], $member->getEmail())['token'])['app']);
        $this->assertSame('boutique', self::claims($login(['app' => 'mobile'], $member->getEmail())['token'])['app']);
        $other = $login(['app' => 'livingmemory'], $member->getEmail());
        $this->assertSame('memoires', self::claims($other['token'])['app'], 'pas membre de Mémoires Vivantes : session rendue, rien n\'est refusé');
        $this->assertSame(['boutique'], array_column($this->rows((int) $member->getId()), 'app'), 'jamais d\'ajout silencieux : se connecter ne crée aucune appartenance');
        $claims = self::claims($other['token']);
        $this->assertSame([(int) $member->getId(), $member->getEmail()], [$claims['id'], $claims['email']], 'les autres champs du jeton sont ceux d\'avant');

        // Session web : la famille est dans le jeton des cookies et du corps
        $webLogin = $this->json('POST', '/api/login', ['username' => $member->getEmail(), 'password' => self::PASSWORD, 'platform' => 'web', 'app' => 'web']);
        $this->assertSame('boutique', self::claims($webLogin['token'])['app']);

        // Rafraîchissement d'une session web (cookies) : la famille est lue dans le corps, le jeton du cookie la porte
        $code = MV_TEST_TENANT_CODE;
        $cookies = fn (Response $r): array => array_column(array_map(fn ($c) => [$c->getName(), $c->getValue()], $r->headers->getCookies()), 1, 0);
        $session = $cookies($this->request('POST', '/api/login', ['username' => $member->getEmail(), 'password' => self::PASSWORD, 'platform' => 'web', 'app' => 'web']));
        $this->assertSame('boutique', self::claims($session["auth_token_$code"])['app']);
        foreach ([[['app' => 'mobile'], 'boutique'], [[], null], [null, null]] as [$body, $expected]) {
            $this->client->getCookieJar()->clear();
            $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie("refresh_token_$code", $session["refresh_token_$code"], null, '/', MV_TEST_TENANT_HOST, true));
            $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/token/refresh', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip], $body === null ? null : json_encode($body));
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
            $this->assertSame($expected, self::claims($cookies($this->client->getResponse())["auth_token_$code"])['app'] ?? null, json_encode($body));
        }

        // Rafraîchissement : la famille est redite par l'application, sinon le nouveau jeton n'en porte pas
        $refreshed = $this->json('POST', '/api/token/refresh', ['refresh_token' => $other['refresh_token'], 'platform' => 'mobile', 'app' => 'livingmemory']);
        $this->assertSame('memoires', self::claims($refreshed['token'])['app']);
        $this->assertArrayNotHasKey('app', self::claims($this->json('POST', '/api/token/refresh', ['refresh_token' => $other['refresh_token']])['token']));
        // Session ouverte sans `app` (avant M1) : `app` au rafraîchissement suffit, le serveur ne compare avec rien
        $this->assertSame('boutique', self::claims($this->json('POST', '/api/token/refresh', ['refresh_token' => $plain['refresh_token'], 'app' => 'mobile'])['token'])['app']);

        // Vérification du code (double vérification) : même règle
        $otp = $this->user(['ROLE_USER', 'ROLE_USER_INTERNET'], true, true);
        $this->assertTrue($login(['app' => 'memoires'], $otp->getEmail())['otp_required']);
        $code = $this->db->fetchOne('SELECT code FROM otp_code WHERE user_otp_id = ? ORDER BY id DESC LIMIT 1', [$otp->getId()]);
        $verified = $this->json('POST', '/api/otp-verify', ['username' => $otp->getEmail(), 'otp' => $code, 'platform' => 'mobile', 'app' => 'memoires']);
        $this->assertSame('memoires', self::claims($verified['token'])['app']);

        // Ce que le mode « observer » note : la connexion d'un compte CLIENT à une famille dont il n'est pas membre, et rien d'autre
        $logger = new class() extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $level . ' ' . $message;
            }
        };
        $c = static::getContainer();
        $c->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $service = new AppMembershipService($c->get(TenantEntityManagerProvider::class), $c->get(ContentAuditRecorder::class), $c->get(AppTermsService::class), $logger, $c->get(TenantConnectionProvider::class));
        $this->assertSame('boutique', $service->sessionFamily($member, 'web', 'login'));
        $this->assertSame([], $logger->lines, 'membre : rien à noter');
        $this->assertNull($service->sessionFamily($member, null, 'login'));
        $this->assertNull($service->sessionFamily($member, 'gestion', 'login'));
        $this->assertSame('memoires', $service->sessionFamily($this->user(['ROLE_ADMIN', 'ROLE_USER_INTERNET']), 'memoires', 'login'));
        $this->assertSame([], $logger->lines, 'compte d\'équipe : jamais membre, jamais noté');
        $this->assertSame('memoires', $service->sessionFamily($member, 'livingmemory', 'refresh'));
        $this->assertCount(1, $logger->lines);
        $this->assertStringStartsWith('info ' . AppMembershipService::OBSERVE_MARK, $logger->lines[0]);
        $this->assertStringContainsString(sprintf('le compte %d n\'est pas membre de « memoires » (refresh, site %s)', $member->getId(), MV_TEST_TENANT_CODE), $logger->lines[0]);
    }

    public function testInvitedAccountsGuestOrdersAndMyMemberships(): void
    {
        $this->db->executeStatement("UPDATE entreprise SET privacy_policy = 'Politique du site'");
        $version = $this->json('GET', '/api/apps/web/terms')['version'];
        $admin = $this->user(['ROLE_ADMIN', 'ROLE_USER', 'ROLE_USER_INTERNET']);
        $adminToken = $this->json('POST', '/api/login', ['username' => $admin->getEmail(), 'password' => self::PASSWORD, 'platform' => 'mobile'])['token'];

        // Compte client créé par l'équipe de Mémoires Vivantes : membre, sans conditions acceptées ; compte d'équipe créé de même : jamais membre
        $invited = $this->json('POST', '/api/memoires/admin/users', ['email' => 'invite-' . bin2hex(random_bytes(3)) . '@example.invalid', 'firstName' => 'Inv', 'lastName' => 'Ité', 'role' => 'ROLE_USER'], $adminToken, [200, 201]);
        $this->assertSame([['app' => 'memoires', 'source' => 'invite', 'terms_version' => null, 'terms_accepted_at' => null]], $this->rows((int) $invited['user']['id']));
        $colleague = $this->json('POST', '/api/memoires/admin/users', ['email' => 'collegue-' . bin2hex(random_bytes(3)) . '@example.invalid', 'firstName' => 'Col', 'lastName' => 'Lègue', 'role' => 'ROLE_ADMIN'], $adminToken, [200, 201]);
        $this->assertSame([], $this->rows((int) $colleague['user']['id']));

        // Commande en invité : le compte existe, sans mot de passe choisi : aucune appartenance, même avec des commandes ;
        // la version des conditions acceptées au paiement est gardée sur la commande si elle est celle en vigueur
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-paiement@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $email = 'invite-commande-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $first = $this->guestOrder($email, ['termsVersion' => $version]);
        $second = $this->guestOrder($email, ['termsVersion' => 'version-perimee']);
        $third = $this->guestOrder($email, []);
        $this->assertSame([$version, null, null], array_map(fn (int $id) => $this->db->fetchOne('SELECT terms_version FROM "order" WHERE id = ?', [$id]), [$first, $second, $third]));
        $guest = $this->db->fetchAssociative('SELECT id, password_set FROM "user" WHERE email = ?', [$email]);
        $this->assertFalse((bool) $guest['password_set']);
        $this->assertSame([], $this->rows((int) $guest['id']), 'trois commandes, aucun mot de passe choisi : aucune appartenance');
        $this->db->executeStatement("UPDATE \"order\" SET order_date = '2026-05-06 07:08:09' WHERE id = ?", [$first]);

        // Il choisit son mot de passe : c'est la création de son compte ; il entre dans la boutique à la date et à la version de sa première commande
        $this->db->executeStatement("UPDATE \"user\" SET reset_token = 'jeton-m1-' || id, reset_token_expires_at = NOW() + INTERVAL '1 hour' WHERE id = ?", [$guest['id']]);
        $this->json('POST', '/api/password-reset/confirm', ['token' => 'jeton-m1-' . $guest['id'], 'password' => self::PASSWORD]);
        $row = $this->db->fetchAssociative('SELECT app, source, terms_version, joined_at, terms_accepted_at FROM user_app_membership WHERE user_id = ?', [$guest['id']]);
        $this->assertSame(['app' => 'boutique', 'source' => 'guest_checkout', 'terms_version' => $version, 'joined_at' => '2026-05-06 07:08:09', 'terms_accepted_at' => '2026-05-06 07:08:09'], $row);
        $this->assertTrue((bool) $this->db->fetchOne('SELECT password_set FROM "user" WHERE id = ?', [$guest['id']]));

        // Même règle par le formulaire du backend (lien du courriel sans page du frontend)
        $other = 'invite-formulaire-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $fourth = $this->guestOrder($other, ['termsVersion' => $version]);
        $otherId = (int) $this->db->fetchOne('SELECT id FROM "user" WHERE email = ?', [$other]);
        $this->db->executeStatement("UPDATE \"user\" SET reset_token = 'jeton-m1-' || id, reset_token_expires_at = NOW() + INTERVAL '1 hour' WHERE id = ?", [$otherId]);
        $form = $this->request('POST', '/password-reset/confirm?tenant_host=' . MV_TEST_TENANT_HOST, ['token' => 'jeton-m1-' . $otherId, 'newPassword' => self::PASSWORD]);
        $this->assertLessThan(400, $form->getStatusCode(), (string) $form->getContent());
        $this->assertSame([['app' => 'boutique', 'source' => 'guest_checkout', 'terms_version' => $version, 'terms_accepted_at' => $this->db->fetchOne('SELECT order_date FROM "order" WHERE id = ?', [$fourth])]], $this->rows($otherId));

        // Un compte qui avait déjà son mot de passe et en change : rien ; ni un compte sans commande EN INVITÉ (une commande de client ne compte pas)
        $regular = $this->user(['ROLE_USER', 'ROLE_USER_INTERNET']);
        $this->db->executeStatement('UPDATE "order" SET user_id_id = ? WHERE id = ?', [$regular->getId(), $third]);
        $never = $this->user(['ROLE_USER', 'ROLE_USER_INTERNET'], false);
        $this->db->executeStatement('UPDATE "order" SET user_id_id = ?, guest_token = NULL WHERE id = ?', [$never->getId(), $second]);
        foreach ([$regular, $never] as $u) {
            $this->db->executeStatement("UPDATE \"user\" SET reset_token = 'jeton-m1-' || id, reset_token_expires_at = NOW() + INTERVAL '1 hour' WHERE id = ?", [$u->getId()]);
            $this->json('POST', '/api/password-reset/confirm', ['token' => 'jeton-m1-' . $u->getId(), 'password' => self::PASSWORD . '2']);
            $this->assertSame([], $this->rows((int) $u->getId()));
        }

        // Mes appartenances : celles du compte connecté ; un compte d'équipe n'en a pas et le sait
        $anonymous = $this->request('GET', '/api/account/memberships');
        $this->assertSame(401, $anonymous->getStatusCode());
        $token = $this->json('POST', '/api/login', ['username' => $email, 'password' => self::PASSWORD, 'platform' => 'mobile', 'app' => 'web'])['token'];
        $mine = $this->json('GET', '/api/account/memberships', null, $token);
        $this->assertSame(['team' => false, 'memberships' => [['app' => 'boutique', 'joinedAt' => '2026-05-06T07:08:09+00:00', 'source' => 'guest_checkout', 'termsVersion' => $version, 'termsAcceptedAt' => '2026-05-06T07:08:09+00:00']]], $mine);
        $this->assertSame(['team' => true, 'memberships' => []], $this->json('GET', '/api/account/memberships', null, $adminToken));
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

    /** Inscription par l'API ; rend l'identifiant du compte créé */
    private function register(array $extra): int
    {
        $email = 'inscrit-' . bin2hex(random_bytes(4)) . '@example.com';
        $this->ip = '10.83.' . random_int(1, 254) . '.' . random_int(1, 254); // /api/register est limité par IP
        $response = $this->request('POST', '/api/register', ['email' => $email, 'password' => self::PASSWORD, 'firstName' => 'Jeanne', 'lastName' => 'Dupont', 'platform' => 'mobile'] + $extra);
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        return (int) $this->db->fetchOne('SELECT id FROM "user" WHERE email = ?', [$email]);
    }

    /** Commande d'invité payée (Stripe simulé) ; rend l'identifiant de la commande */
    private function guestOrder(string $email, array $extra): int
    {
        $variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $body = [
            'guestInfo' => ['email' => $email, 'firstName' => 'Invité', 'lastName' => 'Test', 'phone' => '5145550100'],
            'items' => [['productVariantId' => $variant, 'quantity' => 1]], 'carrierId' => 6,
            'shippingAddress' => ['firstname' => 'Invité', 'lastname' => 'Test', 'address' => '1 rue du Test', 'city' => 'Québec', 'province' => 'QC', 'postalCode' => 'G1R 4P5'],
        ];
        $pi = $this->json('POST', '/api/stripe/create-intent', ['items' => $body['items'], 'carrierId' => 6, 'shippingAddress' => ['province' => 'QC']])['paymentIntentId'];
        FakeCheckoutPaymentGateway::confirm($pi);

        return (int) $this->json('POST', '/api/order/create-guest', $body + ['paymentIntentId' => $pi] + $extra, null, [201])['orderId'];
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
