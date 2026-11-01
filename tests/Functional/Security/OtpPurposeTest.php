<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Usage des codes à usage unique (01/11/2026, otp_code.purpose) : un code de changement de courriel n'ouvre pas de session,
 * un code de connexion ne confirme pas un changement de courriel, un nouveau code invalide les codes non utilisés du même usage.
 */
class OtpPurposeTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAnEmailChangeCodeDoesNotOpenASession(): void
    {
        [$email, $id] = $this->user();
        $this->insertCode($id, '111111', 'email_change');
        $refused = $this->request('POST', '/api/otp-verify', json_encode(['username' => $email, 'otp' => '111111', 'platform' => 'mobile']));
        $this->assertSame(401, $refused->getStatusCode(), 'code d\'un autre usage : comme un code invalide');
        $this->assertSame('Code OTP invalide ou expiré', json_decode($refused->getContent(), true)['error']);
        $this->assertSame(1, $this->codeCount($id), 'le code n\'est pas consommé');

        $this->insertCode($id, '222222', 'login');
        $ok = $this->request('POST', '/api/otp-verify', json_encode(['username' => $email, 'otp' => '222222', 'platform' => 'mobile']));
        $this->assertSame(200, $ok->getStatusCode(), $ok->getContent());
        $this->assertNotEmpty(json_decode($ok->getContent(), true)['token']);
    }

    public function testALoginCodeDoesNotConfirmAnEmailChange(): void
    {
        [$email, $id] = $this->user(otpEnabled: true);
        $token = $this->bearer($email, $id);
        $this->insertCode($id, '333333', 'login');
        $newEmail = 'nouveau-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $refused = $this->request('PATCH', '/api/profile', json_encode(['email' => $newEmail, 'otpCode' => '333333']), ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $this->assertSame([403, 'OTP_INVALID'], [$refused->getStatusCode(), json_decode($refused->getContent(), true)['error']], 'code de connexion refusé par le changement de courriel');

        $this->insertCode($id, '444444', 'email_change');
        $ok = $this->request('PATCH', '/api/profile', json_encode(['email' => $newEmail, 'otpCode' => '444444']), ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $this->assertSame(200, $ok->getStatusCode(), $ok->getContent());
        $this->assertSame($newEmail, $this->db()->fetchOne('SELECT email FROM "user" WHERE id = ?', [$id]));
    }

    public function testANewCodeInvalidatesTheUnusedCodesOfTheSameUse(): void
    {
        [$email, $id] = $this->user(otpEnabled: true);
        $this->insertCode($id, '555555', 'email_change', purposeKept: true);
        $first = $this->request('POST', '/api/login', json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'mobile']));
        $this->assertTrue(json_decode($first->getContent(), true)['otp_required'] ?? false, $first->getContent());
        $firstCode = $this->db()->fetchOne("SELECT code FROM otp_code WHERE user_otp_id = ? AND purpose = 'login'", [$id]);
        $second = $this->request('POST', '/api/login', json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'mobile']));
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM otp_code WHERE user_otp_id = ? AND purpose = 'login'", [$id]), 'un seul code de connexion valable');
        $this->assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM otp_code WHERE user_otp_id = ? AND purpose = 'email_change'", [$id]), 'le code d\'un autre usage reste');
        $old = $this->request('POST', '/api/otp-verify', json_encode(['username' => $email, 'otp' => $firstCode, 'platform' => 'mobile']));
        $this->assertSame(401, $old->getStatusCode(), 'l\'ancien code ne sert plus');
    }

    private function insertCode(int $userId, string $code, string $purpose, bool $purposeKept = false): void
    {
        $this->db()->executeStatement("INSERT INTO otp_code (id, user_otp_id, code, expiration, purpose) VALUES (nextval('otp_code_id_seq'), ?, ?, NOW() + INTERVAL '5 minutes', ?)", [$userId, $code, $purpose]);
    }

    private function codeCount(int $userId): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM otp_code WHERE user_otp_id = ?', [$userId]);
    }

    /** Jeton d'accès par le flux de connexion avec code (compte otpEnabled) */
    private function bearer(string $email, int $id): string
    {
        $this->request('POST', '/api/login', json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'mobile']));
        $code = $this->db()->fetchOne("SELECT code FROM otp_code WHERE user_otp_id = ? AND purpose = 'login'", [$id]);
        $login = $this->request('POST', '/api/otp-verify', json_encode(['username' => $email, 'otp' => $code, 'platform' => 'mobile']));
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());

        return json_decode($login->getContent(), true)['token'];
    }

    private function request(string $method, string $path, ?string $body = null, array $server = []): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server + [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $body);

        return $this->client->getResponse();
    }

    /** @return array{0: string, 1: int} */
    private function user(bool $otpEnabled = false): array
    {
        $email = 'otp-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Otp')->setLastname('Test')->setRoles(['ROLE_USER', 'ROLE_USER_INTERNET'])->setIsVerified(true)->setOtpEnabled($otpEnabled);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return [$email, (int) $user->getId()];
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager()->getConnection();
    }
}
