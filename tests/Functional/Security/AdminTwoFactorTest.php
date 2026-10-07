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
        unset($_ENV['ADMIN_OTP_REQUIRED'], $_SERVER['ADMIN_OTP_REQUIRED']);
        parent::tearDown();
    }

    public function testAdminMustEnterTheEmailCodeWhenRequired(): void
    {
        $admin = $this->user(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $customer = $this->user(['ROLE_USER_INTERNET']);

        $this->assertArrayNotHasKey('otp_required', $this->login($admin), 'réglage absent : connexion directe');

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
