<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Correctifs de sécurité du 15/10/2026 liés aux applications mobiles (audit du POS) :
 * - GET /api/payments : administrateurs (toutes sources) et caissiers (source caisse seulement), plus aucun client ;
 * - /api/categories (API Platform) : écritures réservées aux administrateurs, lectures inchangées ;
 * - platform inconnue à la connexion et à l'OTP : refusée (400 PLATFORM_INVALID) ; valeurs connues et absence inchangées.
 */
class MobileAccessHardeningTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private ?string $token = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testPaymentsListIsReservedToAdminsAndToTheCashRegisterForPosSales(): void
    {
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $this->assertSame(200, $this->request('GET', '/api/payments?orderSource=1')->getStatusCode(), 'admin : e-commerce');
        $this->assertSame(200, $this->request('GET', '/api/payments?orderSource=2')->getStatusCode(), 'admin : caisse');
        $this->assertSame(400, $this->request('GET', '/api/payments')->getStatusCode(), 'orderSource requis, comme avant');

        $this->loginAs(['ROLE_USER_POS']);
        $pos = $this->request('GET', '/api/payments?orderSource=2');
        $this->assertSame(200, $pos->getStatusCode(), 'caissier sans commande : paiements de la caisse ' . $pos->getContent());
        $this->assertIsArray(json_decode($pos->getContent(), true));
        $refused = $this->request('GET', '/api/payments?orderSource=1');
        $this->assertSame(403, $refused->getStatusCode(), 'caissier : pas les paiements en ligne');
        $this->assertSame('FORBIDDEN', json_decode($refused->getContent(), true)['error']);

        $this->loginAs(['ROLE_USER_INTERNET'], 'mobile');
        $customer = $this->request('GET', '/api/payments?orderSource=1');
        $this->assertSame(403, $customer->getStatusCode(), 'client : jamais la liste des paiements du site');
        $this->assertSame('FORBIDDEN', json_decode($customer->getContent(), true)['error']);
    }

    public function testCategoriesApiPlatformWritesAreReservedToAdmins(): void
    {
        $ld = ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'];
        $body = json_encode(['name' => 'Catégorie test ' . bin2hex(random_bytes(2))]);

        $this->loginAs(['ROLE_USER_INTERNET'], 'mobile');
        // Lecture réservée à l'administrateur depuis S8 (26/10/2026) : avant, tout client connecté lisait les catégories avec leurs produits
        // imbriqués sans limite ; la boutique lit /api/category (route dédiée, publique, inchangée)
        $this->assertSame(403, $this->request('GET', '/api/categories', null, $ld)->getStatusCode(), 'client : lecture refusée (S8)');
        $this->assertSame(403, $this->request('POST', '/api/categories', $body, $ld)->getStatusCode(), 'client : création refusée');

        $this->loginAs(['ROLE_ADMIN']);
        $created = $this->request('POST', '/api/categories', $body, $ld);
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $id = json_decode($created->getContent(), true)['id'];

        $this->loginAs(['ROLE_USER_INTERNET'], 'mobile');
        $this->assertSame(403, $this->request('DELETE', "/api/categories/$id", null, $ld)->getStatusCode(), 'client : suppression refusée');
        $this->assertSame(403, $this->request('PUT', "/api/categories/$id", $body, $ld)->getStatusCode(), 'client : modification refusée');

        $this->loginAs(['ROLE_ADMIN']);
        $this->assertSame(204, $this->request('DELETE', "/api/categories/$id", null, $ld)->getStatusCode(), 'admin : suppression');
    }

    public function testKnownPlatformsLogInAsBeforeAndUnknownOnesAreRefused(): void
    {
        $email = $this->user(['ROLE_USER_INTERNET', 'ROLE_USER_POS', 'ROLE_ADMIN']);

        foreach (['web', ' Web ', null] as $platform) {
            $login = $this->login($email, $platform);
            $this->assertSame(200, $login->getStatusCode(), var_export($platform, true) . ' : ' . $login->getContent());
            $body = json_decode($login->getContent(), true);
            $this->assertNotEmpty($body['csrf_token'] ?? null, var_export($platform, true) . ' : session web avec jeton CSRF');
            $this->assertContains('auth_token_' . MV_TEST_TENANT_CODE, array_map(fn ($c) => $c->getName(), $login->headers->getCookies()), 'cookies web');
        }
        foreach (['mobile', 'pos', 'POS'] as $platform) {
            $login = $this->login($email, $platform);
            $this->assertSame(200, $login->getStatusCode(), $platform . ' : ' . $login->getContent());
            $body = json_decode($login->getContent(), true);
            $this->assertNotEmpty($body['token'] ?? null, "$platform : jeton dans le corps");
            $this->assertNotEmpty($body['refresh_token'] ?? null);
            $this->assertEmpty($login->headers->getCookies(), "$platform : pas de cookie");
        }

        $refused = $this->login($email, 'toto');
        $this->assertSame(400, $refused->getStatusCode(), $refused->getContent());
        $this->assertSame('PLATFORM_INVALID', json_decode($refused->getContent(), true)['error']);
        $this->assertEmpty($refused->headers->getCookies(), 'aucun jeton émis');

        // Rôles toujours contrôlés par plateforme
        $customer = $this->user(['ROLE_USER_INTERNET']);
        $this->assertSame(403, $this->login($customer, 'pos')->getStatusCode(), 'client sur la caisse : refusé comme avant');
        $this->assertSame(200, $this->login($customer, 'mobile')->getStatusCode());
    }

    public function testOtpVerifyAppliesTheSamePlatformRule(): void
    {
        $email = $this->user(['ROLE_USER_INTERNET', 'ROLE_ADMIN']);

        $refused = $this->otpVerify($email, 'toto');
        $this->assertSame(400, $refused->getStatusCode(), $refused->getContent());
        $this->assertSame('PLATFORM_INVALID', json_decode($refused->getContent(), true)['error']);
        $this->assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM otp_code WHERE user_otp_id = (SELECT id FROM "user" WHERE email = ?)', [$email]), 'code non consommé');
        $this->db()->executeStatement('DELETE FROM otp_code WHERE user_otp_id = (SELECT id FROM "user" WHERE email = ?)', [$email]);

        $mobile = $this->otpVerify($email, null);
        $this->assertSame(200, $mobile->getStatusCode(), 'absente → mobile : ' . $mobile->getContent());
        $this->assertNotEmpty(json_decode($mobile->getContent(), true)['token'] ?? null, 'jeton dans le corps');
        $this->assertEmpty($mobile->headers->getCookies());

        $web = $this->otpVerify($email, 'web');
        $this->assertSame(200, $web->getStatusCode(), $web->getContent());
        $this->assertNotEmpty(json_decode($web->getContent(), true)['csrf_token'] ?? null, 'web : session par cookies + csrf_token');
        $this->assertContains('auth_token_' . MV_TEST_TENANT_CODE, array_map(fn ($c) => $c->getName(), $web->headers->getCookies()));
    }

    /** Dépose un code à usage unique valide puis appelle /api/otp-verify */
    private function otpVerify(string $email, ?string $platform): Response
    {
        $this->db()->executeStatement("INSERT INTO otp_code (id, user_otp_id, code, expiration) VALUES (nextval('otp_code_id_seq'), (SELECT id FROM \"user\" WHERE email = ?), '424242', NOW() + INTERVAL '5 minutes')", [$email]);
        $this->token = null;

        return $this->request('POST', '/api/otp-verify', json_encode(array_filter(['username' => $email, 'otp' => '424242', 'platform' => $platform], fn ($v) => $v !== null)));
    }

    private function login(string $email, ?string $platform): Response
    {
        $this->token = null;

        return $this->request('POST', '/api/login', json_encode(array_filter(['username' => $email, 'password' => self::PASSWORD, 'platform' => $platform], fn ($v) => $v !== null)));
    }

    private function request(string $method, string $path, ?string $body = null, array $server = []): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server + array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
        ]), $body);

        return $this->client->getResponse();
    }

    /** Connexion native (jeton Bearer) : « pos » pour le personnel, « mobile » pour un client */
    private function loginAs(array $roles, string $platform = 'pos'): void
    {
        $email = $this->user(in_array('ROLE_USER_POS', $roles, true) || $platform !== 'pos' ? $roles : [...$roles, 'ROLE_USER_POS']);
        $login = $this->login($email, $platform);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
    }

    private function user(array $roles): string
    {
        $email = 'mobile-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Mobile')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
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
