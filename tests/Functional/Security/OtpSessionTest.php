<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Security\OtpSession;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Lot SÉCURITÉ-2 (16/11/2026), préalables à l'entrée des agences :
 * - la page du code à usage unique d'EasyAdmin (`/account/otp`) LIMITE LES ESSAIS (5 par 10 minutes et par compte), comme la
 *   vérification par l'API ; au-delà, refus et codes en attente effacés ;
 * - la validation du code est LIÉE AU COMPTE qui l'a saisi : un autre compte dans la même session doit saisir le sien ;
 * - les pages de la plateforme (`/admin/plateforme*`) sont gardées par une règle d'accès, pas seulement par leurs contrôleurs.
 */
class OtpSessionTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private string $tag;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->tag = bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement("DELETE FROM otp_code WHERE user_otp_id IN (SELECT id FROM \"user\" WHERE email LIKE 'code-%@example.invalid')");
        $this->db->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'code-%@example.invalid'");
        parent::tearDown();
    }

    public function testTheValidationOfTheCodeBelongsToTheAccountThatTypedIt(): void
    {
        $a = $this->user('a', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $b = $this->user('b', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $session = new Session(new MockArraySessionStorage());
        $site = MV_TEST_TENANT_CODE;
        $this->assertFalse(OtpSession::validated($session, $a, $site));
        OtpSession::markValidated($session, $a, $site);
        $this->assertTrue(OtpSession::validated($session, $a, $site));
        $this->assertFalse(OtpSession::validated($session, $b, $site), 'un autre compte dans la même session n\'est pas validé');
        // l'ancien drapeau « vrai » d'une session d'avant ce lot ne vaut plus rien ; ni une marque vide ou d'un autre type
        foreach ([true, '1', '', ['a' => 1]] as $old) {
            $session->set(OtpSession::KEY, $old);
            $this->assertFalse(OtpSession::validated($session, $a, $site), var_export($old, true));
        }

        // UN COMPTE ORDINAIRE : sa validation vaut sur CE site, pour CE compte. Le compte de même adresse sur un AUTRE site (même
        // session, `/login?tenant=…`) doit saisir son propre code ; un compte sans identifiant ou hors site n'est jamais validé
        $ordinary = $this->user('ordinaire', ['ROLE_ADMIN']);
        $twinElsewhere = (new User())->setEmail($ordinary->getEmail())->setUsername($ordinary->getEmail())->setRoles(['ROLE_ADMIN']);
        (new \ReflectionProperty(User::class, 'id'))->setValue($twinElsewhere, $ordinary->getId());
        OtpSession::markValidated($session, $ordinary, $site);
        $this->assertTrue(OtpSession::validated($session, $ordinary, $site));
        $this->assertFalse(OtpSession::validated($session, $twinElsewhere, MV_TEST_TENANT2_CODE), 'même adresse, même numéro, autre site : refusé');
        $this->assertFalse(OtpSession::validated($session, $ordinary, null));
        $this->assertFalse(OtpSession::validated($session, $ordinary, ''));
        OtpSession::markValidated($session, $ordinary, null);
        $this->assertFalse($session->has(OtpSession::KEY), 'hors site : aucune marque posée');

        // LE COMPTE DE LA PLATEFORME garde sa validation d'un site à l'autre ; un compte ordinaire de même adresse ne s'en prévaut jamais
        OtpSession::markValidated($session, $a, $site);
        $this->assertTrue(OtpSession::validated($session, $a, MV_TEST_TENANT2_CODE));
        $impostor = (new User())->setEmail($a->getEmail())->setUsername($a->getEmail())->setRoles(['ROLE_ADMIN']);
        (new \ReflectionProperty(User::class, 'id'))->setValue($impostor, $a->getId());
        $this->assertFalse(OtpSession::validated($session, $impostor, $site), 'un compte ordinaire à l\'adresse du compte de la plateforme : refusé');

        // EN VRAI : le compte A valide son code ; le compte B, connecté ensuite dans la même session, est renvoyé vers la page du code
        $this->login($a);
        $this->validate($a);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'A entre');
        $this->client->loginUser($b, 'main'); // même session (les cookies sont gardés)
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), 'B n\'entre pas sur la validation de A');
        $this->assertStringContainsString('/account/otp', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testThePageOfTheCodeLimitsTheAttemptsAndClearsThePendingCodes(): void
    {
        $user = $this->user('essais', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $this->login($user);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), 'la page bloquée pose le compte en attente du code');
        $this->db->executeStatement("INSERT INTO otp_code (id, user_otp_id, code, expiration, purpose) VALUES (nextval('otp_code_id_seq'), ?, '135790', NOW() + INTERVAL '5 minutes', 'login')", [$user->getId()]);
        $refused = 0;
        for ($i = 0; $i < 5; ++$i) {
            $refused += str_contains($this->attempt(str_pad((string) (100000 + $i), 6, '0')), 'invalide ou expiré') ? 1 : 0;
        }
        $this->assertSame(5, $refused, 'cinq mauvais codes, refusés un par un');
        // le sixième essai, MÊME AVEC LE BON CODE, est refusé, et le code en attente est effacé
        $this->assertStringContainsString('Trop d\'essais', $this->attempt('135790'));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM otp_code WHERE user_otp_id = ?', [$user->getId()]));
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), 'toujours pas entré');
    }

    /** Une session ouverte sans code en attente en reçoit un ; une fois la connexion validée, aucun code de connexion ne reste */
    public function testASessionWithoutCodeReceivesOneAndNoCodeSurvivesTheValidation(): void
    {
        $user = $this->user('envoi', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $this->login($user);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM otp_code WHERE user_otp_id = ? AND purpose = 'login'", [$user->getId()]), 'un code est envoyé à la session qui n\'en avait pas');
        $first = (string) $this->db->fetchOne("SELECT id || ':' || code FROM otp_code WHERE user_otp_id = ? AND purpose = 'login'", [$user->getId()]);
        // une seconde page bloquée n'en envoie pas un autre tant que le premier vaut (le code reçu reste celui à saisir)
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame($first, (string) $this->db->fetchOne("SELECT id || ':' || code FROM otp_code WHERE user_otp_id = ? AND purpose = 'login'", [$user->getId()]), 'le même code, pas un nouveau');
        $sent = (string) $this->db->fetchOne("SELECT code FROM otp_code WHERE user_otp_id = ? AND purpose = 'login'", [$user->getId()]);
        // validée par un autre code valable du compte : le code envoyé est effacé avec lui
        $this->db->executeStatement("INSERT INTO otp_code (id, user_otp_id, code, expiration, purpose) VALUES (nextval('otp_code_id_seq'), ?, ?, NOW() + INTERVAL '5 minutes', 'login')", [$user->getId(), $sent === '975310' ? '975311' : '975310']);
        $this->attempt($sent === '975310' ? '975311' : '975310');
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), 'code accepté');
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM otp_code WHERE user_otp_id = ? AND purpose = 'login'", [$user->getId()]), 'aucun code ne survit à la connexion');
    }

    public function testThePlatformPagesAreGuardedByAnAccessRule(): void
    {
        $map = static::getContainer()->get('security.access_map');
        foreach (['/admin/plateforme', '/admin/plateforme/sites', '/admin/plateforme/agences', '/admin/plateforme/une-action-future'] as $path) {
            [$roles] = $map->getPatterns(\Symfony\Component\HttpFoundation\Request::create('https://' . MV_TEST_TENANT_HOST . $path));
            $this->assertSame(['ROLE_SUPER_ADMIN'], $roles, $path);
        }
        [$roles] = $map->getPatterns(\Symfony\Component\HttpFoundation\Request::create('https://' . MV_TEST_TENANT_HOST . '/admin/mon-site'));
        $this->assertSame(['ROLE_ADMIN'], $roles, 'le reste de l\'espace d\'administration ne change pas');
        // un administrateur de site : refusé par la règle (une adresse inexistante répond 404 avant toute règle : la route se cherche d'abord)
        $this->login($this->user('site', ['ROLE_ADMIN']));
        foreach (['/admin/plateforme', '/admin/plateforme/sites', '/admin/plateforme/agences'] as $path) {
            $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), $path);
        }
    }

    /** L'avis « adresse changée » du compte de la plateforme part par le serveur de la plateforme, jamais par celui du site */
    public function testTheEmailChangeNoticeOfThePlatformAccountGoesThroughThePlatform(): void
    {
        $c = static::getContainer();
        $request = \Symfony\Component\HttpFoundation\Request::create('https://' . MV_TEST_TENANT_HOST . '/api/profile');
        $guard = function (object $mailer) use ($c): \App\Services\AccountService\EmailChangeGuard {
            return new \App\Services\AccountService\EmailChangeGuard($c->get(TenantEntityManagerProvider::class), $c->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class),
                $c->get(\App\Services\OtpService::class), $mailer, $c->get('limiter.profile_email_change_account'), new \Psr\Log\NullLogger());
        };
        $platform = $this->user('avis-plateforme', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $mailer = $this->createMock(\App\Services\EmailConfigurationService\EmailSenderService::class);
        $mailer->method('subject')->willReturn('Adresse changée');
        $mailer->expects($this->once())->method('sendPlatformEmail')->with('ancienne@example.invalid')->willReturn(true);
        $mailer->expects($this->never())->method('sendCustomerEmail');
        $guard($mailer)->notifyChanged($platform, 'ancienne@example.invalid', 'nouvelle@example.invalid', 'password', $request);

        $ordinary = $this->user('avis-ordinaire', ['ROLE_USER_INTERNET']);
        $mailer = $this->createMock(\App\Services\EmailConfigurationService\EmailSenderService::class);
        $mailer->expects($this->once())->method('sendCustomerEmail')->with('ancienne@example.invalid')->willReturn(true);
        $mailer->expects($this->never())->method('sendPlatformEmail');
        $guard($mailer)->notifyChanged($ordinary, 'ancienne@example.invalid', 'nouvelle@example.invalid', 'password', $request);
    }

    private function attempt(string $code): string
    {
        $page = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/account/otp', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $token = $page->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/account/otp', ['otp' => $code, '_csrf_token' => $token], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);

        return html_entity_decode((string) $this->client->getResponse()->getContent(), \ENT_QUOTES);
    }

    private function validate(User $user): void
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->db->executeStatement("INSERT INTO otp_code (id, user_otp_id, code, expiration, purpose) VALUES (nextval('otp_code_id_seq'), ?, '246802', NOW() + INTERVAL '5 minutes', 'login')", [$user->getId()]);
        $this->attempt('246802');
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), 'code accepté');
    }

    private function login(User $user): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
    }

    private function user(string $name, array $roles): User
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $email = "code-$name-{$this->tag}@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Code')->setLastname($name)->setRoles($roles)->setPassword('x')->setIsVerified(true);
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
