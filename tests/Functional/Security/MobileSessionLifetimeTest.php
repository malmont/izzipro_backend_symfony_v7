<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Session de l'application mobile (01/11/2026) : /api/token/refresh avec {"platform": "mobile"} prolonge le jeton de
 * rafraîchissement de 7 jours (expiration glissante, même jeton) ; sans ce champ, rien ne change ; un nouveau mot de
 * passe par /api/password-reset/confirm révoque les jetons de rafraîchissement.
 */
class MobileSessionLifetimeTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAMobileRefreshExtendsTheRefreshTokenForSevenDays(): void
    {
        $email = $this->user();
        $login = json_decode($this->request('POST', '/api/login', json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'mobile']))->getContent(), true);
        $refreshToken = $login['refresh_token'];
        // Jeton presque expiré
        $this->db()->executeStatement("UPDATE refresh_tokens SET valid = NOW() + INTERVAL '1 day' WHERE refresh_token = ?", [$refreshToken]);

        $plain = $this->request('POST', '/api/token/refresh', json_encode(['refresh_token' => $refreshToken]));
        $this->assertSame(200, $plain->getStatusCode(), $plain->getContent());
        $this->assertLessThan(2, $this->daysLeft($refreshToken), 'sans platform : durée inchangée');

        $mobile = $this->request('POST', '/api/token/refresh', json_encode(['refresh_token' => $refreshToken, 'platform' => 'mobile']));
        $this->assertSame(200, $mobile->getStatusCode(), $mobile->getContent());
        $body = json_decode($mobile->getContent(), true);
        $this->assertNotEmpty($body['token']);
        $this->assertArrayNotHasKey('refresh_token', $body, 'même jeton de rafraîchissement, pas de rotation');
        $this->assertGreaterThan(6.9, $this->daysLeft($refreshToken), 'platform mobile : prolongé de 7 jours');
    }

    /** Gestion (« admin ») et caisse (« pos ») : même fenêtre glissante depuis le 10/11/2026 ; le web reste à expiration fixe */
    public function testAdminAndPosRefreshesExtendTooButNotTheWeb(): void
    {
        foreach (['admin', 'pos'] as $platform) {
            $email = $this->user();
            $refreshToken = json_decode($this->request('POST', '/api/login', json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'mobile']))->getContent(), true)['refresh_token'];
            $this->db()->executeStatement("UPDATE refresh_tokens SET valid = NOW() + INTERVAL '1 day' WHERE refresh_token = ?", [$refreshToken]);
            $response = $this->request('POST', '/api/token/refresh', json_encode(['refresh_token' => $refreshToken, 'platform' => $platform]));
            $this->assertSame(200, $response->getStatusCode(), $response->getContent());
            $this->assertGreaterThan(6.9, $this->daysLeft($refreshToken), "platform $platform : prolongé de 7 jours");
        }
        $email = $this->user();
        $refreshToken = json_decode($this->request('POST', '/api/login', json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'mobile']))->getContent(), true)['refresh_token'];
        $this->db()->executeStatement("UPDATE refresh_tokens SET valid = NOW() + INTERVAL '1 day' WHERE refresh_token = ?", [$refreshToken]);
        $this->assertSame(200, $this->request('POST', '/api/token/refresh', json_encode(['refresh_token' => $refreshToken, 'platform' => 'web']))->getStatusCode());
        $this->assertLessThan(2, $this->daysLeft($refreshToken), 'platform web : expiration fixe');
    }

    public function testAPasswordResetRevokesTheRefreshTokens(): void
    {
        $email = $this->user();
        $login = json_decode($this->request('POST', '/api/login', json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'mobile']))->getContent(), true);
        $this->assertSame(1, $this->tokenCount($email));
        $this->db()->executeStatement("UPDATE \"user\" SET reset_token = 'jeton-de-test-' || id, reset_token_expires_at = NOW() + INTERVAL '1 hour' WHERE email = ?", [$email]);
        $token = $this->db()->fetchOne('SELECT reset_token FROM "user" WHERE email = ?', [$email]);

        $reset = $this->request('POST', '/api/password-reset/confirm', json_encode(['token' => $token, 'password' => 'Nouveau-mot-de-passe-1!']), ['REMOTE_ADDR' => '10.79.' . random_int(1, 254) . '.' . random_int(1, 254)]);
        $this->assertSame(200, $reset->getStatusCode(), $reset->getContent());
        $this->assertSame(0, $this->tokenCount($email), 'jetons de rafraîchissement révoqués');
        $refresh = $this->request('POST', '/api/token/refresh', json_encode(['refresh_token' => $login['refresh_token'], 'platform' => 'mobile']));
        $this->assertSame(401, $refresh->getStatusCode(), 'l\'ancien jeton ne reconnecte plus');
        $again = $this->request('POST', '/api/login', json_encode(['username' => $email, 'password' => 'Nouveau-mot-de-passe-1!', 'platform' => 'mobile']));
        $this->assertSame(200, $again->getStatusCode(), 'nouveau mot de passe accepté');
    }

    private function daysLeft(string $refreshToken): float
    {
        return (float) $this->db()->fetchOne('SELECT EXTRACT(EPOCH FROM (valid - NOW())) / 86400 FROM refresh_tokens WHERE refresh_token = ?', [$refreshToken]);
    }

    private function tokenCount(string $email): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM refresh_tokens WHERE username = ?', [$email]);
    }

    private function request(string $method, string $path, ?string $body = null, array $server = []): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server + [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $body);

        return $this->client->getResponse();
    }

    private function user(): string
    {
        $email = 'session-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Session')->setLastname('Test')->setRoles(['ROLE_USER', 'ROLE_USER_INTERNET'])->setIsVerified(true);
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
