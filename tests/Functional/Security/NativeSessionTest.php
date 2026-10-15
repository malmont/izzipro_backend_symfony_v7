<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Session des applications natives (15/10/2026) : le rafraîchissement par le corps renvoie le nouveau JWT dans le corps
 * (en plus des cookies posés comme avant, même refresh token) ; plateforme « admin » (application de gestion, ROLE_ADMIN).
 */
class NativeSessionTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testRefreshFromTheBodyReturnsAUsableTokenAndKeepsTheRefreshToken(): void
    {
        $email = $this->user(['ROLE_USER_INTERNET']);
        $login = json_decode($this->request('POST', '/api/login', json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'mobile']))->getContent(), true);
        $refreshToken = $login['refresh_token'];

        $refresh = $this->request('POST', '/api/token/refresh', json_encode(['refresh_token' => $refreshToken]));
        $this->assertSame(200, $refresh->getStatusCode(), $refresh->getContent());
        $body = json_decode($refresh->getContent(), true);
        $this->assertSame('Token refreshed successfully', $body['message'], 'message inchangé');
        $this->assertNotEmpty($body['csrf_token'], 'csrf_token toujours présent');
        $this->assertNotEmpty($body['token'] ?? null, 'nouveau JWT dans le corps (ajout)');
        $cookies = array_map(fn ($c) => $c->getName(), $refresh->headers->getCookies());
        $this->assertContains('auth_token_' . MV_TEST_TENANT_CODE, $cookies, 'cookies posés comme avant');
        $this->assertArrayNotHasKey('refresh_token', $body, 'pas de renouvellement du refresh token');

        $me = $this->request('GET', '/api/validate-token', null, ['HTTP_AUTHORIZATION' => 'Bearer ' . $body['token']]);
        $this->assertSame(200, $me->getStatusCode(), 'le jeton du corps sert en Bearer : ' . $me->getContent());

        $again = $this->request('POST', '/api/token/refresh', json_encode(['refresh_token' => $refreshToken]));
        $this->assertSame(200, $again->getStatusCode(), 'le même refresh token reste valable');
    }

    public function testWebRefreshByCookieIsUnchanged(): void
    {
        $email = $this->user(['ROLE_USER_INTERNET']);
        $login = $this->request('POST', '/api/login', json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $cookies = [];
        foreach ($login->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        $tenant = MV_TEST_TENANT_CODE;
        foreach (["refresh_token_$tenant" => $cookies["refresh_token_$tenant"], "XSRF-TOKEN_$tenant" => $cookies["XSRF-TOKEN_$tenant"]] as $name => $value) {
            $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
        }
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/token/refresh', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $cookies["XSRF-TOKEN_$tenant"],
        ]);
        $refresh = $this->client->getResponse();
        $this->assertSame(200, $refresh->getStatusCode(), $refresh->getContent());
        $this->assertSame(['message', 'csrf_token'], array_keys(json_decode($refresh->getContent(), true)), 'session web : corps inchangé, sans jeton');
    }

    public function testAdminPlatformRequiresTheAdminRole(): void
    {
        $admin = $this->user(['ROLE_ADMIN']);
        $login = $this->request('POST', '/api/login', json_encode(['username' => $admin, 'password' => self::PASSWORD, 'platform' => 'admin']));
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $body = json_decode($login->getContent(), true);
        $this->assertNotEmpty($body['token']);
        $this->assertNotEmpty($body['refresh_token']);
        $this->assertEmpty($login->headers->getCookies(), 'comme « mobile » : jetons dans le corps, pas de cookie');
        $this->assertArrayNotHasKey('csrf_token', $body);

        $customer = $this->user(['ROLE_USER_INTERNET', 'ROLE_USER_POS']);
        $refused = $this->request('POST', '/api/login', json_encode(['username' => $customer, 'password' => self::PASSWORD, 'platform' => 'admin']));
        $this->assertSame(403, $refused->getStatusCode(), $refused->getContent());

        // OTP : même règle
        $this->db()->executeStatement("INSERT INTO otp_code (id, user_otp_id, code, expiration) VALUES (nextval('otp_code_id_seq'), (SELECT id FROM \"user\" WHERE email = ?), '424242', NOW() + INTERVAL '5 minutes')", [$admin]);
        $otp = $this->request('POST', '/api/otp-verify', json_encode(['username' => $admin, 'otp' => '424242', 'platform' => 'admin']));
        $this->assertSame(200, $otp->getStatusCode(), $otp->getContent());
        $this->assertNotEmpty(json_decode($otp->getContent(), true)['token'] ?? null);
        $this->db()->executeStatement("INSERT INTO otp_code (id, user_otp_id, code, expiration) VALUES (nextval('otp_code_id_seq'), (SELECT id FROM \"user\" WHERE email = ?), '424242', NOW() + INTERVAL '5 minutes')", [$customer]);
        $otp = $this->request('POST', '/api/otp-verify', json_encode(['username' => $customer, 'otp' => '424242', 'platform' => 'admin']));
        $this->assertSame(403, $otp->getStatusCode(), $otp->getContent());
    }

    private function request(string $method, string $path, ?string $body = null, array $server = []): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server + [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $body);

        return $this->client->getResponse();
    }

    private function user(array $roles): string
    {
        $email = 'natif-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Natif')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
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
