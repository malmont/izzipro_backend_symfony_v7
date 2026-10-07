<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Code par e-mail imposé aux administrateurs (ADMIN_OTP_REQUIRED, TwoFactorPolicy) */
class AdminTwoFactorTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        $_ENV['ADMIN_OTP_REQUIRED'] = $_SERVER['ADMIN_OTP_REQUIRED'] = '0';
        parent::tearDown();
    }

    public function testAdminMustEnterTheEmailCodeWhenRequired(): void
    {
        $admin = $this->user(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $customer = $this->user(['ROLE_USER_INTERNET']);

        $this->assertArrayNotHasKey('otp_required', $this->login($admin), 'réglage à 0 : connexion directe');

        $_ENV['ADMIN_OTP_REQUIRED'] = $_SERVER['ADMIN_OTP_REQUIRED'] = '1';
        $body = $this->login($admin);
        $this->assertTrue($body['otp_required'] ?? false, 'administrateur : code demandé');
        $this->assertSame([], array_filter($this->client->getResponse()->headers->getCookies(), fn ($c) => str_contains(strtolower($c->getName()), 'token')), 'aucun jeton avant le code');
        $code = $this->db()->fetchOne('SELECT code FROM otp_code WHERE user_otp_id = (SELECT id FROM "user" WHERE email = ?) ORDER BY id DESC LIMIT 1', [$admin]);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);

        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/otp-verify', [], [], $this->headers(), json_encode(['username' => $admin, 'otp' => $code, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        $this->assertArrayNotHasKey('otp_required', $this->login($customer), 'client : pas de code');
    }

    public function testAdministrationStaysOnTheCodePageUntilTheCodeIsValidated(): void
    {
        $email = $this->user(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $this->db()->executeStatement('UPDATE "user" SET otp_enabled = true WHERE email = ?', [$email]);
        $user = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->client->loginUser($user, 'main'); // mot de passe accepté, code pas encore saisi
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST];

        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin', [], [], $server);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), 'pas d\'administration sans le code');
        $this->assertStringEndsWith('/account/otp', (string) $this->client->getResponse()->headers->get('Location'));

        $page = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/account/otp', [], [], $server);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'la page du code s\'affiche (erreur 500 jusqu\'au 07/10/2026)');

        $this->db()->executeStatement("INSERT INTO otp_code (id, user_otp_id, code, expiration) VALUES (nextval('otp_code_id_seq'), (SELECT id FROM \"user\" WHERE email = ?), '424242', NOW() + INTERVAL '5 minutes')", [$email]);
        $token = $page->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/account/otp', ['otp' => '424242', '_csrf_token' => $token], [], $server);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertStringEndsWith('/admin', (string) $this->client->getResponse()->headers->get('Location'));

        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin', [], [], $server);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'code validé : administration ouverte');
    }

    public function testCodeIsSentByThePlatformWhenTheSiteHasNoMailConfiguration(): void
    {
        $email = $this->user(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $this->db()->executeStatement('UPDATE "user" SET otp_enabled = true WHERE email = ?', [$email]);
        $configurations = $this->db()->fetchAllAssociative('SELECT * FROM email_configuration');
        $this->db()->executeStatement('DELETE FROM email_configuration_translation');
        $this->db()->executeStatement('DELETE FROM email_configuration');
        $_ENV['MAILER_DSN'] = $_SERVER['MAILER_DSN'] = 'null://plateforme%40example.invalid:x@default';
        try {
            $this->assertTrue($this->login($email)['otp_required'] ?? false);
            $this->assertEmailCount(1);
            $message = $this->getMailerMessage();
            $this->assertSame('plateforme@example.invalid', $message->getFrom()[0]->getAddress(), 'expéditeur : le compte du serveur de la plateforme');
            $this->assertSame($email, $message->getTo()[0]->getAddress());
            $code = $this->db()->fetchOne('SELECT code FROM otp_code WHERE user_otp_id = (SELECT id FROM "user" WHERE email = ?) ORDER BY id DESC LIMIT 1', [$email]);
            $this->assertStringContainsString((string) $code, $message->getHtmlBody());
        } finally {
            unset($_ENV['MAILER_DSN'], $_SERVER['MAILER_DSN']);
            foreach ($configurations as $row) {
                $this->db()->insert('email_configuration', $row);
            }
        }
    }

    private function login(string $email): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], $this->headers(), json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function headers(): array
    {
        return ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    }

    private function user(array $roles): string
    {
        $email = '2fa-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Double')->setLastname('Facteur')->setRoles($roles)->setIsVerified(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $email;
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager()->getConnection();
    }
}
