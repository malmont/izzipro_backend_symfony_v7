<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Codes stables des refus du compte client (04/11/2026, site Next.js) : `code` et `message` ajoutés au premier niveau, `params` quand
 * le texte cite une valeur ; `error` et les statuts inchangés. Un cas par code sur register, resend-verification, password-reset
 * (request et confirm), otp-verify et profile.
 */
class AccountErrorCodesApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private ?string $ip = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /** @dataProvider anonymousRefusals */
    public function testEveryAnonymousRefusalCarriesItsCode(int $status, string $code, string $error, ?array $params, string $path, \Closure $body): void
    {
        $response = $this->request('POST', $path, json_encode($body($this)));
        $this->assertRefusal($status, $code, $error, $params, $response);
    }

    public static function anonymousRefusals(): iterable
    {
        $register = fn (array $extra) => fn (self $t) => $extra + ['email' => 'codes-' . bin2hex(random_bytes(3)) . '@example.com', 'password' => self::PASSWORD, 'firstName' => 'Marie', 'lastName' => 'Tremblay'];
        yield 'register FIELDS_REQUIRED' => [400, 'FIELDS_REQUIRED', 'Invalid data', ['fields' => ['email', 'password', 'firstName', 'lastName']], '/api/register', fn (self $t) => ['email' => 'x@example.com']];
        yield 'register PASSWORD_TOO_SHORT' => [400, 'PASSWORD_TOO_SHORT', 'PASSWORD_TOO_SHORT', ['min' => 8], '/api/register', $register(['password' => 'court'])];
        yield 'register EMAIL_INVALID' => [400, 'EMAIL_INVALID', 'Invalid email format', null, '/api/register', $register(['email' => 'pas-un-courriel'])];
        yield 'register EMAIL_DOMAIN_INVALID' => [400, 'EMAIL_DOMAIN_INVALID', 'Email domain appears invalid', null, '/api/register', $register(['email' => 'marie@domaine-inexistant.invalid'])];
        yield 'register EMAIL_ALREADY_USED' => [409, 'EMAIL_ALREADY_USED', 'User already exists', null, '/api/register', fn (self $t) => $t->registered()];
        yield 'resend EMAIL_REQUIRED' => [400, 'EMAIL_REQUIRED', 'Email manquant', null, '/api/resend-verification', fn (self $t) => []];
        yield 'resend USER_NOT_FOUND' => [404, 'USER_NOT_FOUND', 'Utilisateur introuvable', null, '/api/resend-verification', fn (self $t) => ['email' => 'personne-' . bin2hex(random_bytes(3)) . '@example.invalid']];
        yield 'resend ACCOUNT_ALREADY_VERIFIED' => [400, 'ACCOUNT_ALREADY_VERIFIED', 'Ce compte est déjà vérifié.', null, '/api/resend-verification', fn (self $t) => ['email' => $t->user()[0]]];
        yield 'reset request EMAIL_REQUIRED' => [400, 'EMAIL_REQUIRED', 'Email is required.', null, '/api/password-reset/request', fn (self $t) => []];
        yield 'reset confirm FIELDS_REQUIRED' => [400, 'FIELDS_REQUIRED', 'token et password attendus.', ['fields' => ['token', 'password']], '/api/password-reset/confirm', fn (self $t) => []];
        yield 'reset confirm PASSWORD_TOO_SHORT' => [400, 'PASSWORD_TOO_SHORT', 'Le mot de passe doit comporter au moins 8 caractères.', ['min' => 8], '/api/password-reset/confirm', fn (self $t) => ['token' => 'x', 'password' => 'court']];
        yield 'reset confirm RESET_LINK_INVALID' => [400, 'RESET_LINK_INVALID', 'Lien invalide ou expiré : demandez un nouveau lien.', null, '/api/password-reset/confirm', fn (self $t) => ['token' => 'inconnu-' . bin2hex(random_bytes(3)), 'password' => self::PASSWORD]];
        yield 'otp-verify UNAUTHORIZED' => [401, 'UNAUTHORIZED', 'Unauthorized', null, '/api/otp-verify', fn (self $t) => ['username' => 'personne-' . bin2hex(random_bytes(3)) . '@example.invalid', 'otp' => '000000', 'platform' => 'mobile']];
        yield 'otp-verify OTP_INVALID' => [401, 'OTP_INVALID', 'Code OTP invalide ou expiré', null, '/api/otp-verify', fn (self $t) => ['username' => $t->user()[0], 'otp' => '000000', 'platform' => 'mobile']];
    }

    public function testProfileRefusalsCarryTheirCodes(): void
    {
        [$email] = $this->user();
        [$other] = $this->user();
        $token = $this->login($email);

        $this->assertRefusal(400, 'INVALID_BODY', 'Invalid JSON', null, $this->request('PATCH', '/api/profile', 'pas du json', $token));
        $this->assertRefusal(400, 'EMAIL_INVALID', 'Invalid email', null, $this->request('PATCH', '/api/profile', json_encode(['email' => 123]), $token), 'courriel qui n\'est pas une chaîne');
        $this->assertRefusal(400, 'EMAIL_INVALID', 'Invalid email', null, $this->request('PATCH', '/api/profile', json_encode(['email' => 'pas-un-courriel']), $token));
        $this->assertRefusal(409, 'EMAIL_ALREADY_USED', 'Email already in use', null, $this->request('PATCH', '/api/profile', json_encode(['email' => $other, 'currentPassword' => self::PASSWORD]), $token), 'adresse d\'un autre compte');
        $this->assertRefusal(400, 'LICENSE_DATE_INVALID', 'Invalid date format for licenseExpirationDate. Use YYYY-MM-DD.', ['format' => 'YYYY-MM-DD'], $this->request('PATCH', '/api/profile', json_encode(['licenseExpirationDate' => 'pas-une-date']), $token));

        // Les codes du changement de courriel restent ceux d'avant, dans error
        $refused = $this->request('PATCH', '/api/profile', json_encode(['email' => 'nouvelle-' . bin2hex(random_bytes(3)) . '@example.invalid']), $token);
        $this->assertSame([403, 'PASSWORD_REQUIRED'], [$refused->getStatusCode(), json_decode($refused->getContent(), true)['error']]);
    }

    private function assertRefusal(int $status, string $code, string $error, ?array $params, Response $response, string $label = ''): void
    {
        $body = json_decode($response->getContent(), true);
        $this->assertSame($status, $response->getStatusCode(), $label . ' ' . $response->getContent());
        $this->assertSame([$error, $code], [$body['error'] ?? null, $body['code'] ?? null], $label . ' ' . $response->getContent());
        $this->assertArrayHasKey('message', $body, 'message de premier niveau');
        if ($params !== null) {
            $this->assertSame($params, $body['params'] ?? null, $label . ' params');
        }
    }

    /** @return array<string, string> corps d'inscription d'une adresse déjà inscrite (domaine réel : la vérification DNS précède le doublon) */
    public function registered(): array
    {
        $body = ['email' => 'codes-' . bin2hex(random_bytes(3)) . '@example.com', 'password' => self::PASSWORD, 'firstName' => 'Marie', 'lastName' => 'Tremblay'];
        $this->assertSame(201, $this->request('POST', '/api/register', json_encode($body))->getStatusCode());

        return $body;
    }

    /** @return array{0: string, 1: int} client vérifié, mot de passe choisi */
    public function user(): array
    {
        $email = 'codes-compte-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Marie')->setLastname('Tremblay')->setRoles(['ROLE_USER', 'ROLE_USER_INTERNET'])->setIsVerified(true)->setPasswordSet(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $em->persist($user);
        $em->flush();

        return [$email, (int) $user->getId()];
    }

    private function login(string $email): string
    {
        $login = $this->request('POST', '/api/login', json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'mobile']));
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());

        return (string) json_decode($login->getContent(), true)['token'];
    }

    private function request(string $method, string $path, ?string $body = null, ?string $bearer = null): Response
    {
        // IP propre au test : les limites de débit (Redis) survivent d'un lancement à l'autre
        $this->ip ??= '10.83.' . random_int(1, 254) . '.' . random_int(1, 254);
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $bearer ? 'Bearer ' . $bearer : null, 'REMOTE_ADDR' => $this->ip,
        ]), $body);

        return $this->client->getResponse();
    }
}
